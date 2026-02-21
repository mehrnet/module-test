<?php

/**
 * Hetzner Cloud service manager module for FOSSBilling.
 *
 * @copyright Mehrnet
 * @license Apache-2.0
 */

namespace Box\Mod\Servicehetzner;

class Service
{
    protected $di;

    private const EXT_KEY = 'mod_servicehetzner';
    private const DEFAULT_API_URL = 'https://api.hetzner.cloud/v1';
    private const DEFAULT_PROJECT_REF = 'default';
    private const BILLING_MODE_STANDARD = 'standard';
    private const BILLING_MODE_PREPAID_HOURS = 'prepaid_hours';

    public function setDi($di)
    {
        $this->di = $di;
    }

    public function getDi()
    {
        return $this->di;
    }

    public function install(): bool
    {
        $this->ensureSchema();

        $config = $this->getModuleConfig();
        if (!is_array($config) || empty($config)) {
            $this->persistModuleConfig([
                'default_project_ref' => self::DEFAULT_PROJECT_REF,
                'delete_on_cancel' => '0',
                'projects' => [
                    $this->ensureProjectDefaults([
                        'ref' => self::DEFAULT_PROJECT_REF,
                        'label' => 'Default project',
                        'priority' => '0',
                        'api_url' => self::DEFAULT_API_URL,
                        'api_token' => '',
                    ]),
                ],
            ]);
        }

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    public function update(array $manifest): bool
    {
        $this->ensureSchema();

        return true;
    }

    /**
     * Product/order config validation before provisioning.
     */
    public function validateOrderData(array &$data): void
    {
        if (!isset($data['server_type']) || trim((string) $data['server_type']) === '') {
            throw new \FOSSBilling\InformationException('Hetzner order config is missing server_type.');
        }

        if (!isset($data['image']) || trim((string) $data['image']) === '') {
            throw new \FOSSBilling\InformationException('Hetzner order config is missing image.');
        }

        $billingMode = $this->normalizeBillingMode((string) ($data['billing_mode'] ?? self::BILLING_MODE_STANDARD));
        $data['billing_mode'] = $billingMode;
        if ($billingMode === self::BILLING_MODE_PREPAID_HOURS) {
            if (isset($data['period']) && trim((string) $data['period']) !== '') {
                throw new \FOSSBilling\InformationException('Prepaid hourly mode does not support recurring periods. Configure this product with one-time pricing.');
            }

            $hours = $this->normalizeHours(
                $data['prepaid_hours'] ?? $data['quantity'] ?? 0,
                (int) ($data['prepaid_hours_min'] ?? 1),
                (int) ($data['prepaid_hours_max'] ?? 8760),
                24
            );
            if ($hours <= 0) {
                throw new \FOSSBilling\InformationException('Prepaid hours must be greater than zero.');
            }
            $data['prepaid_hours'] = $hours;
            $data['quantity'] = $hours;
        }
    }

    /**
     * Creates local service record.
     *
     * @return \Model_ServiceHetzner
     */
    public function action_create(\Model_ClientOrder $order)
    {
        $this->ensureSchema();

        $existing = $this->getServiceByOrder($order, false);
        if ($existing) {
            return $existing;
        }

        $projectRef = '';
        $snapshot = [];
        try {
            $orderConfig = $this->getOrderConfig($order);
            $projectRef = (string) ($orderConfig['project_ref'] ?? '');
            $snapshot = [
                'server_type' => (string) ($orderConfig['server_type'] ?? ''),
                'location' => (string) ($orderConfig['location'] ?? ''),
                'datacenter' => (string) ($orderConfig['datacenter'] ?? ''),
                'image' => (string) ($orderConfig['image'] ?? ''),
            ];
        } catch (\Throwable $e) {
            // Keep default values; activation will validate mandatory fields.
        }

        $service = $this->di['db']->dispense('service_hetzner');
        $service->order_id = $order->id;
        $service->client_id = $order->client_id;
        $service->status = 'pending';
        $service->provision_status = 'not_provisioned';
        $service->hcloud_server_id = null;
        $service->project_ref = $projectRef;
        $service->config = json_encode(['snapshot' => $snapshot]);
        $service->created_at = date('Y-m-d H:i:s');
        $service->updated_at = date('Y-m-d H:i:s');

        $this->di['db']->store($service);

        return $service;
    }

    public function action_activate(\Model_ClientOrder $order): bool
    {
        $service = $this->getServiceByOrder($order, false);
        if (!$service) {
            $service = $this->action_create($order);
        }

        $orderConfig = $this->getOrderConfig($order);
        $this->validateOrderData($orderConfig);
        $billingPolicy = $this->resolveBillingPolicyFromOrderConfig($orderConfig);
        if ($billingPolicy['mode'] === self::BILLING_MODE_PREPAID_HOURS) {
            if ($this->isRecurringOrder($order)) {
                throw new \FOSSBilling\InformationException('Prepaid hourly mode does not support recurring periods. Configure this product with one-time pricing where the unit price equals one hour.');
            }

            $orderUnitPrice = isset($order->price) && is_numeric($order->price) ? (float) $order->price : 0.0;
            if ($orderUnitPrice <= 0) {
                throw new \FOSSBilling\InformationException('Prepaid hourly mode requires a non-zero one-time product unit price (per hour).');
            }

            $hourlyRate = (float) ($billingPolicy['hourly_rate'] ?? 0);
            if ($hourlyRate <= 0) {
                $hourlyRate = $orderUnitPrice;
            }
            if ($hourlyRate <= 0) {
                throw new \FOSSBilling\InformationException('Hourly billing mode requires a non-zero product unit price or hourly rate override.');
            }
        }
        $explicitProjectRef = $this->sanitizeProjectRef((string) ($orderConfig['project_ref'] ?? ''));
        $projectCandidates = $this->resolveProvisioningProjectsForOrder($orderConfig);

        if (!empty($service->hcloud_server_id)) {
            $project = $this->resolveProjectForService($service, $order);
            $details = $this->fetchServerById($project, (string) $service->hcloud_server_id);
            $service->status = 'active';
            $service->provision_status = 'provisioned';
            $service->updated_at = date('Y-m-d H:i:s');
            $service->config = json_encode(array_merge(
                $this->decodeJson((string) $service->config),
                [
                    'project_ref' => $project['ref'],
                    'last_sync' => date('c'),
                    'server' => $details,
                ]
            ));
            $this->initializePrepaidBillingState($service, $order, $orderConfig);
            $this->di['db']->store($service);

            return true;
        }

        $attemptErrors = [];

        foreach ($projectCandidates as $project) {
            try {
                if (!$this->projectHasAvailableCapacity($project)) {
                    $attemptErrors[] = 'Project ' . $project['ref'] . ' has no free capacity';
                    continue;
                }

                $created = $this->createServer($order, $project, $orderConfig);
                $serverId = (string) ($created['server']['id'] ?? '');
                if ($serverId === '') {
                    throw new \FOSSBilling\InformationException('Hetzner did not return a server ID after creation.');
                }

                $service->status = 'active';
                $service->provision_status = 'provisioned';
                $service->hcloud_server_id = $serverId;
                $service->project_ref = $project['ref'];
                $service->updated_at = date('Y-m-d H:i:s');
                $service->config = json_encode(array_merge(
                    $this->decodeJson((string) $service->config),
                    [
                        'project_ref' => $project['ref'],
                        'created_at' => date('c'),
                        'order_config' => [
                            'server_type' => (string) ($orderConfig['server_type'] ?? ''),
                            'image' => (string) ($orderConfig['image'] ?? ''),
                            'location' => (string) ($orderConfig['location'] ?? ''),
                            'datacenter' => (string) ($orderConfig['datacenter'] ?? ''),
                        ],
                        'server' => $created['server'] ?? [],
                        'action' => $created['action'] ?? [],
                    ]
                ));
                $this->initializePrepaidBillingState($service, $order, $orderConfig);

                $this->di['db']->store($service);
                $this->refreshProjectUsage($project);

                return true;
            } catch (\Throwable $e) {
                $attemptErrors[] = $project['ref'] . ': ' . $e->getMessage();
                $this->updateProjectState($project['ref'], [
                    'status' => 'error',
                    'last_error' => $e->getMessage(),
                    'last_sync_at' => date('c'),
                ]);

                if ($explicitProjectRef !== '' || !$this->isRetryableProvisioningError($e->getMessage())) {
                    $this->markProvisionFailure($service, $project['ref'], $e->getMessage());
                    throw $e;
                }
            }
        }

        $message = empty($attemptErrors)
            ? 'No eligible Hetzner project found for provisioning.'
            : 'Provisioning failed across candidate projects: ' . implode(' | ', $attemptErrors);

        $this->markProvisionFailure($service, $explicitProjectRef, $message);
        throw new \FOSSBilling\InformationException($message);
    }

    public function action_suspend(\Model_ClientOrder $order): bool
    {
        $service = $this->getServiceByOrder($order);
        $this->touchBillingAccountedAt($service);
        if (!empty($service->hcloud_server_id)) {
            $this->runOrderPowerAction($order, 'poweroff');
        }

        $service->status = 'suspended';
        $service->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($service);

        return true;
    }

    public function action_unsuspend(\Model_ClientOrder $order): bool
    {
        $service = $this->getServiceByOrder($order);
        if (!$this->canPowerOnByBalance($service)) {
            throw new \FOSSBilling\InformationException('Prepaid hourly balance is exhausted. Top up hours to power on this server.');
        }
        if (!empty($service->hcloud_server_id)) {
            $this->runOrderPowerAction($order, 'poweron');
        }

        $service->status = 'active';
        $service->updated_at = date('Y-m-d H:i:s');
        $this->touchBillingAccountedAt($service);
        $this->di['db']->store($service);

        return true;
    }

    public function action_renew(\Model_ClientOrder $order): bool
    {
        $service = $this->getServiceByOrder($order);
        $service->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($service);

        return true;
    }

    public function action_cancel(\Model_ClientOrder $order): bool
    {
        $service = $this->getServiceByOrder($order);
        $this->touchBillingAccountedAt($service);

        $orderConfig = [];
        try {
            $orderConfig = $this->getOrderConfig($order);
        } catch (\Throwable $e) {
            // Keep default cancellation behavior.
        }

        $moduleConfig = $this->getModuleConfig();
        $deleteOnCancel = $this->parseBool($orderConfig['delete_on_cancel'] ?? $moduleConfig['delete_on_cancel'] ?? '0');

        if ($deleteOnCancel && !empty($service->hcloud_server_id)) {
            $this->deleteRemoteServer($order, $service);
            $service->hcloud_server_id = null;
            $service->provision_status = 'deleted';
        }

        $service->status = 'cancelled';
        $service->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($service);

        return true;
    }

    public function action_uncancel(\Model_ClientOrder $order): bool
    {
        $service = $this->getServiceByOrder($order);
        if (!$this->canPowerOnByBalance($service)) {
            throw new \FOSSBilling\InformationException('Prepaid hourly balance is exhausted. Top up hours before reactivating this service.');
        }
        $service->status = 'active';
        $service->updated_at = date('Y-m-d H:i:s');
        $this->touchBillingAccountedAt($service);
        $this->di['db']->store($service);

        return true;
    }

    public function action_delete(\Model_ClientOrder $order): bool
    {
        $service = $this->getServiceByOrder($order, false);
        if ($service) {
            if (!empty($service->hcloud_server_id)) {
                try {
                    $this->deleteRemoteServer($order, $service);
                } catch (\Throwable $e) {
                    // We still remove local service on delete action.
                }
            }

            $this->di['db']->trash($service);
        }

        return true;
    }

    public function toApiArray($service): array
    {
        return [
            'id' => $service->id,
            'order_id' => $service->order_id,
            'client_id' => $service->client_id,
            'status' => $service->status,
            'provision_status' => $service->provision_status,
            'hcloud_server_id' => $service->hcloud_server_id,
            'project_ref' => $service->project_ref,
            'config' => $this->decodeJson((string) $service->config),
            'created_at' => $service->created_at,
            'updated_at' => $service->updated_at,
        ];
    }

    public function prependOrderConfig(\Model_Product $product, array $data): array
    {
        $productConfig = $this->decodeJson((string) $product->config);

        return array_merge($productConfig, $data);
    }

    public function getModuleConfig(): array
    {
        $sql = "
            SELECT meta_value
            FROM extension_meta
            WHERE extension = :ext
            AND meta_key = 'config'
            LIMIT 1
        ";

        $raw = $this->di['db']->getCell($sql, ['ext' => self::EXT_KEY]);
        $config = [];

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $config = $decoded;
            } elseif (isset($this->di['crypt']) && class_exists('\\FOSSBilling\\Config')) {
                try {
                    $decrypted = $this->di['crypt']->decrypt($raw, \FOSSBilling\Config::getProperty('info.salt'));
                    $decoded = is_string($decrypted) ? json_decode($decrypted, true) : [];
                    if (is_array($decoded)) {
                        $config = $decoded;
                    }
                } catch (\Throwable $e) {
                    // Keep empty config and continue with defaults.
                }
            }
        }

        $projects = [];
        $rawProjects = $config['projects'] ?? [];
        if (is_array($rawProjects)) {
            foreach ($rawProjects as $rawProject) {
                if (!is_array($rawProject)) {
                    continue;
                }

                $ref = $this->sanitizeProjectRef((string) ($rawProject['ref'] ?? ''));
                if ($ref === '') {
                    continue;
                }

                $rawProject['ref'] = $ref;
                $projects[$ref] = $this->ensureProjectDefaults($rawProject);
            }
        }

        // Legacy one-key configuration support.
        if (empty($projects)) {
            $projects[self::DEFAULT_PROJECT_REF] = $this->ensureProjectDefaults([
                'ref' => self::DEFAULT_PROJECT_REF,
                'label' => 'Default project',
                'api_url' => trim((string) ($config['api_url'] ?? self::DEFAULT_API_URL)),
                'api_token' => (string) ($config['api_token'] ?? ''),
                'verify_ssl' => (string) ($config['verify_ssl'] ?? '1'),
                'timeout' => (string) ($config['timeout'] ?? '20'),
                'max_servers' => (string) ($config['max_servers'] ?? '0'),
                'priority' => (string) ($config['priority'] ?? '0'),
            ]);
        }

        $projects = $this->ensureUniqueProjectPriorities($projects);

        $defaultProjectRef = $this->sanitizeProjectRef((string) ($config['default_project_ref'] ?? ''));
        if ($defaultProjectRef === '' || !isset($projects[$defaultProjectRef])) {
            $defaultProjectRef = (string) array_key_first($projects);
        }

        $defaultProject = $projects[$defaultProjectRef] ?? $this->ensureProjectDefaults([
            'ref' => self::DEFAULT_PROJECT_REF,
        ]);

        return [
            'api_url' => $defaultProject['api_url'],
            'api_token' => $defaultProject['api_token'],
            'verify_ssl' => $defaultProject['verify_ssl'],
            'timeout' => $defaultProject['timeout'],
            'max_servers' => $defaultProject['max_servers'],
            'default_project_ref' => $defaultProjectRef,
            'delete_on_cancel' => ((string) ($config['delete_on_cancel'] ?? '0') === '1') ? '1' : '0',
            'projects' => array_values($projects),
        ];
    }

    public function updateModuleConfig(array $data): bool
    {
        $config = $this->getModuleConfig();
        $projectsByRef = $this->indexProjectsByRef($config['projects']);
        $defaultRef = (string) ($config['default_project_ref'] ?? self::DEFAULT_PROJECT_REF);

        if (isset($data['default_project_ref'])) {
            $candidate = $this->sanitizeProjectRef((string) $data['default_project_ref']);
            if ($candidate !== '' && isset($projectsByRef[$candidate])) {
                $defaultRef = $candidate;
            }
        }

        if (isset($data['delete_on_cancel'])) {
            $config['delete_on_cancel'] = $this->parseBool($data['delete_on_cancel']) ? '1' : '0';
        }

        // Backward-compatible one-key settings update edits the current default project.
        $legacyTouched = isset($data['api_url']) || array_key_exists('api_token', $data) || isset($data['verify_ssl']) || isset($data['timeout']) || isset($data['max_servers']) || isset($data['priority']);
        if ($legacyTouched) {
            if (!isset($projectsByRef[$defaultRef])) {
                $projectsByRef[$defaultRef] = $this->ensureProjectDefaults([
                    'ref' => $defaultRef,
                    'label' => ucfirst($defaultRef) . ' project',
                ]);
            }

            $project = $projectsByRef[$defaultRef];

            if (isset($data['api_url']) && trim((string) $data['api_url']) !== '') {
                $project['api_url'] = rtrim(trim((string) $data['api_url']), '/');
            }

            if (array_key_exists('api_token', $data)) {
                $incoming = trim((string) $data['api_token']);
                if ($incoming !== '' && !preg_match('/^[*\\x{2022}]+$/u', $incoming)) {
                    $project['api_token'] = $incoming;
                }
            }

            if (isset($data['verify_ssl'])) {
                $project['verify_ssl'] = $this->parseBool($data['verify_ssl']) ? '1' : '0';
            }

            if (isset($data['timeout']) && is_numeric($data['timeout'])) {
                $timeout = max(3, min(120, (int) $data['timeout']));
                $project['timeout'] = (string) $timeout;
            }

            if (isset($data['max_servers']) && is_numeric($data['max_servers'])) {
                $maxServers = max(0, (int) $data['max_servers']);
                $project['max_servers'] = (string) $maxServers;
            }

            if (isset($data['priority']) && is_numeric($data['priority'])) {
                $project['priority'] = (string) (int) $data['priority'];
            }

            $projectsByRef[$defaultRef] = $this->ensureProjectDefaults($project);
        }

        $projectsByRef = $this->ensureUniqueProjectPriorities($projectsByRef, $defaultRef);

        $payload = [
            'default_project_ref' => $defaultRef,
            'delete_on_cancel' => $config['delete_on_cancel'] ?? '0',
            'projects' => array_values($projectsByRef),
        ];

        $this->persistModuleConfig($payload);

        return true;
    }

    public function getProjectsForApi(bool $withInventory = true): array
    {
        $config = $this->getModuleConfig();
        $projects = $config['projects'] ?? [];

        $result = [];
        foreach ($projects as $project) {
            if (!is_array($project)) {
                continue;
            }

            $project = $this->ensureProjectDefaults($project);
            $isDefault = $project['ref'] === $config['default_project_ref'];

            $item = $this->redactProject($project, $withInventory);
            $item['is_default'] = $isDefault;

            $result[] = $item;
        }

        usort($result, static function (array $a, array $b): int {
            $aPriority = (int) ($a['priority'] ?? 100);
            $bPriority = (int) ($b['priority'] ?? 100);
            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }

            return strcmp((string) ($a['ref'] ?? ''), (string) ($b['ref'] ?? ''));
        });

        return $result;
    }

    public function upsertProject(array $data): array
    {
        $config = $this->getModuleConfig();
        $projectsByRef = $this->indexProjectsByRef($config['projects']);

        $refInput = (string) ($data['ref'] ?? $data['project_ref'] ?? '');
        if ($refInput === '' && isset($data['label'])) {
            $refInput = (string) $data['label'];
        }
        $ref = $this->sanitizeProjectRef($refInput);
        if ($ref === '') {
            throw new \FOSSBilling\InformationException('Project ref is required (letters, numbers, dash, underscore).');
        }

        $isNewProject = !isset($projectsByRef[$ref]);
        $project = $projectsByRef[$ref] ?? $this->ensureProjectDefaults([
            'ref' => $ref,
            'label' => ucfirst($ref) . ' project',
        ]);

        if (isset($data['label']) && trim((string) $data['label']) !== '') {
            $project['label'] = trim((string) $data['label']);
        }

        if (isset($data['api_url']) && trim((string) $data['api_url']) !== '') {
            $project['api_url'] = rtrim(trim((string) $data['api_url']), '/');
        }

        if (array_key_exists('api_token', $data)) {
            $incomingToken = trim((string) $data['api_token']);
            if ($incomingToken !== '' && !preg_match('/^[*\\x{2022}]+$/u', $incomingToken)) {
                $project['api_token'] = $incomingToken;
            }
        }

        if (isset($data['verify_ssl'])) {
            $project['verify_ssl'] = $this->parseBool($data['verify_ssl']) ? '1' : '0';
        }

        if (isset($data['timeout']) && is_numeric($data['timeout'])) {
            $project['timeout'] = (string) max(3, min(120, (int) $data['timeout']));
        }

        if (isset($data['max_servers']) && is_numeric($data['max_servers'])) {
            $project['max_servers'] = (string) max(0, (int) $data['max_servers']);
        }

        if (isset($data['priority']) && is_numeric($data['priority'])) {
            $project['priority'] = (string) (int) $data['priority'];
        } elseif ($isNewProject) {
            $project['priority'] = (string) $this->getNextProjectPriority($projectsByRef);
        }

        if (isset($data['status']) && trim((string) $data['status']) !== '') {
            $project['status'] = trim((string) $data['status']);
        }

        $project = $this->ensureProjectDefaults($project);
        $projectsByRef[$ref] = $project;
        $projectsByRef = $this->ensureUniqueProjectPriorities($projectsByRef, $ref);

        $defaultRef = $config['default_project_ref'] ?? '';
        if ($defaultRef === '' || !isset($projectsByRef[$defaultRef])) {
            $defaultRef = $ref;
        }
        if (isset($data['is_default']) && $this->parseBool($data['is_default'])) {
            $defaultRef = $ref;
        }

        $this->persistModuleConfig([
            'default_project_ref' => $defaultRef,
            'delete_on_cancel' => $config['delete_on_cancel'] ?? '0',
            'projects' => array_values($projectsByRef),
        ]);

        $saved = $projectsByRef[$ref] ?? $project;

        return $this->redactProject($saved, true);
    }

    public function deleteProject(string $projectRef): bool
    {
        $ref = $this->sanitizeProjectRef($projectRef);
        if ($ref === '') {
            throw new \FOSSBilling\InformationException('Project ref is required.');
        }

        $config = $this->getModuleConfig();
        $projectsByRef = $this->indexProjectsByRef($config['projects']);

        if (!isset($projectsByRef[$ref])) {
            throw new \FOSSBilling\InformationException('Project :ref not found', [':ref' => $ref]);
        }

        if (count($projectsByRef) <= 1) {
            throw new \FOSSBilling\InformationException('Cannot delete the last remaining Hetzner project.');
        }

        unset($projectsByRef[$ref]);
        $projectsByRef = $this->ensureUniqueProjectPriorities($projectsByRef);

        $defaultRef = (string) ($config['default_project_ref'] ?? '');
        if ($defaultRef === $ref || !isset($projectsByRef[$defaultRef])) {
            $defaultRef = (string) array_key_first($projectsByRef);
        }

        $this->persistModuleConfig([
            'default_project_ref' => $defaultRef,
            'delete_on_cancel' => $config['delete_on_cancel'] ?? '0',
            'projects' => array_values($projectsByRef),
        ]);

        return true;
    }

    public function syncProjectInventory(string $projectRef): array
    {
        $project = $this->resolveProjectByRef($projectRef, true);
        if (trim((string) $project['api_token']) === '') {
            throw new \FOSSBilling\InformationException('Project :ref has no API token configured.', [':ref' => $project['ref']]);
        }

        $serverTypesResponse = $this->performHetznerRequest(
            'GET',
            $project,
            '/server_types?per_page=200'
        );
        $locationsResponse = $this->performHetznerRequest(
            'GET',
            $project,
            '/locations?per_page=200'
        );
        $imagesResponse = $this->performHetznerRequest(
            'GET',
            $project,
            '/images?type=system&status=available&per_page=200'
        );
        $firewallsResponse = $this->performHetznerRequest(
            'GET',
            $project,
            '/firewalls?per_page=200'
        );
        $pricingResponse = $this->performHetznerRequest(
            'GET',
            $project,
            '/pricing'
        );
        $serversResponse = $this->performHetznerRequest(
            'GET',
            $project,
            '/servers?per_page=1'
        );

        $serverTypesBody = $this->assertHetznerSuccess($serverTypesResponse, 'Failed to load server types');
        $locationsBody = $this->assertHetznerSuccess($locationsResponse, 'Failed to load locations');
        $imagesBody = $this->assertHetznerSuccess($imagesResponse, 'Failed to load images');
        $firewallsBody = $this->assertHetznerSuccess($firewallsResponse, 'Failed to load firewalls');
        $pricingBody = $this->assertHetznerSuccess($pricingResponse, 'Failed to load pricing');
        $serversBody = $this->assertHetznerSuccess($serversResponse, 'Failed to load servers usage');

        $serverTypes = [];
        foreach (($serverTypesBody['server_types'] ?? []) as $type) {
            if (!is_array($type)) {
                continue;
            }

            $pricing = $this->normalizeServerTypePricing($type['prices'] ?? []);
            $availableLocations = $this->extractServerTypeAvailableLocations($type, $pricing);

            $serverTypes[] = [
                'id' => (int) ($type['id'] ?? 0),
                'name' => (string) ($type['name'] ?? ''),
                'description' => (string) ($type['description'] ?? ''),
                'cores' => (int) ($type['cores'] ?? 0),
                'memory' => (int) ($type['memory'] ?? 0),
                'disk' => (int) ($type['disk'] ?? 0),
                'architecture' => $this->normalizeArchitectureValue($type['architecture'] ?? ''),
                'architecture_raw' => (string) ($type['architecture'] ?? ''),
                'deprecated' => (bool) ($type['deprecated'] ?? false),
                'price_currency' => $pricing['currency'],
                'price_hourly_from' => $pricing['from_hourly_gross'],
                'price_monthly_from' => $pricing['from_monthly_gross'],
                'pricing' => $pricing,
                'available_locations' => $availableLocations,
            ];
        }

        $locations = [];
        foreach (($locationsBody['locations'] ?? []) as $location) {
            if (!is_array($location)) {
                continue;
            }

            $locations[] = [
                'id' => (int) ($location['id'] ?? 0),
                'name' => (string) ($location['name'] ?? ''),
                'description' => (string) ($location['description'] ?? ''),
                'country' => (string) ($location['country'] ?? ''),
                'city' => (string) ($location['city'] ?? ''),
                'network_zone' => (string) ($location['network_zone'] ?? ''),
            ];
        }

        $images = [];
        foreach (($imagesBody['images'] ?? []) as $image) {
            if (!is_array($image)) {
                continue;
            }

            $images[] = [
                'id' => (int) ($image['id'] ?? 0),
                'name' => (string) ($image['name'] ?? ''),
                'description' => (string) ($image['description'] ?? ''),
                'type' => (string) ($image['type'] ?? ''),
                'status' => (string) ($image['status'] ?? ''),
                'os_flavor' => (string) ($image['os_flavor'] ?? ''),
                'os_version' => (string) ($image['os_version'] ?? ''),
                'architecture' => $this->normalizeArchitectureValue($image['architecture'] ?? ''),
                'architecture_raw' => (string) ($image['architecture'] ?? ''),
                'deprecated' => is_array($image['deprecated'] ?? null) ? $image['deprecated'] : ($image['deprecated'] ?? null),
                'rapid_deploy' => (bool) ($image['rapid_deploy'] ?? false),
            ];
        }

        $firewalls = [];
        foreach (($firewallsBody['firewalls'] ?? []) as $firewall) {
            if (!is_array($firewall)) {
                continue;
            }

            $firewalls[] = [
                'id' => (int) ($firewall['id'] ?? 0),
                'name' => (string) ($firewall['name'] ?? ''),
                'labels' => is_array($firewall['labels'] ?? null) ? $firewall['labels'] : [],
            ];
        }

        $usedServers = (int) ($serversBody['meta']['pagination']['total_entries'] ?? 0);
        if ($usedServers === 0 && isset($serversBody['servers']) && is_array($serversBody['servers'])) {
            $usedServers = count($serversBody['servers']);
        }

        $maxServers = (int) ($project['max_servers'] ?? 0);
        $optionPricing = $this->normalizeOptionPricing($pricingBody['pricing'] ?? []);
        $inventory = [
            'synced_at' => date('c'),
            'used_servers' => $usedServers,
            'max_servers' => $maxServers,
            'available_slots' => $maxServers > 0 ? max($maxServers - $usedServers, 0) : null,
            'server_types' => $serverTypes,
            'locations' => $locations,
            'images' => $images,
            'firewalls' => $firewalls,
            'option_pricing' => $optionPricing,
        ];

        $this->updateProjectState($project['ref'], [
            'status' => 'active',
            'last_error' => '',
            'last_sync_at' => date('c'),
            'inventory' => $inventory,
        ]);

        return [
            'project_ref' => $project['ref'],
            'inventory' => $inventory,
        ];
    }

    public function getProjectCatalog(string $projectRef, bool $refresh = false): array
    {
        $project = $this->resolveProjectByRef($projectRef, true);
        $inventory = is_array($project['inventory'] ?? null) ? $project['inventory'] : [];

        if ($refresh || empty($inventory['server_types']) || empty($inventory['locations'])) {
            $synced = $this->syncProjectInventory($projectRef);
            $inventory = $synced['inventory'];
        }

        return [
            'project_ref' => $project['ref'],
            'server_types' => is_array($inventory['server_types'] ?? null) ? $inventory['server_types'] : [],
            'locations' => is_array($inventory['locations'] ?? null) ? $inventory['locations'] : [],
            'images' => is_array($inventory['images'] ?? null) ? $inventory['images'] : [],
            'firewalls' => is_array($inventory['firewalls'] ?? null) ? $inventory['firewalls'] : [],
            'option_pricing' => is_array($inventory['option_pricing'] ?? null) ? $inventory['option_pricing'] : $this->normalizeOptionPricing([]),
            'capacity' => [
                'used_servers' => (int) ($inventory['used_servers'] ?? 0),
                'max_servers' => (int) ($project['max_servers'] ?? 0),
                'available_slots' => $inventory['available_slots'] ?? null,
            ],
            'synced_at' => (string) ($inventory['synced_at'] ?? ''),
        ];
    }

    public function getGlobalCatalog(bool $refresh = false): array
    {
        $projects = $this->indexProjectsByRef($this->getModuleConfig()['projects']);
        $projects = $this->ensureUniqueProjectPriorities($projects);

        $mergedTypes = [];
        $mergedLocations = [];
        $mergedImages = [];
        $mergedFirewalls = [];
        $mergedOptionPricing = $this->normalizeOptionPricing([]);
        $errors = [];
        $loadedRefs = [];

        foreach ($projects as $project) {
            if (trim((string) ($project['api_token'] ?? '')) === '') {
                continue;
            }

            try {
                $inventory = is_array($project['inventory'] ?? null) ? $project['inventory'] : [];
                if ($refresh || empty($inventory['server_types']) || empty($inventory['locations'])) {
                    $synced = $this->syncProjectInventory($project['ref']);
                    $inventory = $synced['inventory'];
                }
                $mergedOptionPricing = $this->mergeOptionPricing(
                    $mergedOptionPricing,
                    is_array($inventory['option_pricing'] ?? null) ? $inventory['option_pricing'] : $this->normalizeOptionPricing([])
                );

                foreach ((array) ($inventory['server_types'] ?? []) as $type) {
                    if (!is_array($type)) {
                        continue;
                    }

                    $key = (string) ($type['name'] ?? '');
                    if ($key === '') {
                        continue;
                    }

                    if (!isset($mergedTypes[$key])) {
                        $type['available_locations'] = array_values(array_unique(array_map('strval', (array) ($type['available_locations'] ?? []))));
                        $type['available_in_projects'] = [$project['ref']];
                        $mergedTypes[$key] = $type;
                        continue;
                    }

                    $existing = $mergedTypes[$key];
                    $existing['available_in_projects'] = array_values(array_unique(array_merge(
                        (array) ($existing['available_in_projects'] ?? []),
                        [$project['ref']]
                    )));

                    $existing['pricing'] = $this->mergeServerTypePricing(
                        is_array($existing['pricing'] ?? null) ? $existing['pricing'] : [],
                        is_array($type['pricing'] ?? null) ? $type['pricing'] : []
                    );
                    if (!empty($existing['pricing']['currency'])) {
                        $existing['price_currency'] = (string) $existing['pricing']['currency'];
                    }
                    $existing['price_hourly_from'] = $existing['pricing']['from_hourly_gross'] ?? ($existing['price_hourly_from'] ?? null);
                    $existing['price_monthly_from'] = $existing['pricing']['from_monthly_gross'] ?? ($existing['price_monthly_from'] ?? null);

                    $existingHourly = isset($existing['price_hourly_from']) && is_numeric($existing['price_hourly_from']) ? (float) $existing['price_hourly_from'] : null;
                    $typeHourly = isset($type['price_hourly_from']) && is_numeric($type['price_hourly_from']) ? (float) $type['price_hourly_from'] : null;
                    if ($typeHourly !== null && ($existingHourly === null || $typeHourly < $existingHourly)) {
                        $existing['price_hourly_from'] = $typeHourly;
                    }

                    $existingMonthly = isset($existing['price_monthly_from']) && is_numeric($existing['price_monthly_from']) ? (float) $existing['price_monthly_from'] : null;
                    $typeMonthly = isset($type['price_monthly_from']) && is_numeric($type['price_monthly_from']) ? (float) $type['price_monthly_from'] : null;
                    if ($typeMonthly !== null && ($existingMonthly === null || $typeMonthly < $existingMonthly)) {
                        $existing['price_monthly_from'] = $typeMonthly;
                    }

                    $existing['available_locations'] = array_values(array_unique(array_merge(
                        (array) ($existing['available_locations'] ?? []),
                        (array) ($type['available_locations'] ?? [])
                    )));
                    sort($existing['available_locations']);

                    $mergedTypes[$key] = $existing;
                }

                foreach ((array) ($inventory['locations'] ?? []) as $location) {
                    if (!is_array($location)) {
                        continue;
                    }

                    $key = (string) ($location['name'] ?? '');
                    if ($key === '') {
                        continue;
                    }

                    if (!isset($mergedLocations[$key])) {
                        $mergedLocations[$key] = $location;
                    }
                }

                foreach ((array) ($inventory['images'] ?? []) as $image) {
                    if (!is_array($image)) {
                        continue;
                    }

                    $key = (string) ($image['name'] ?? '');
                    if ($key === '') {
                        continue;
                    }

                    if (!isset($mergedImages[$key])) {
                        $image['available_in_projects'] = [$project['ref']];
                        $mergedImages[$key] = $image;
                        continue;
                    }

                    $existing = $mergedImages[$key];
                    $existing['available_in_projects'] = array_values(array_unique(array_merge(
                        (array) ($existing['available_in_projects'] ?? []),
                        [$project['ref']]
                    )));
                    $mergedImages[$key] = $existing;
                }

                foreach ((array) ($inventory['firewalls'] ?? []) as $firewall) {
                    if (!is_array($firewall)) {
                        continue;
                    }

                    $id = (int) ($firewall['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }

                    $key = (string) $id;
                    if (!isset($mergedFirewalls[$key])) {
                        $firewall['available_in_projects'] = [$project['ref']];
                        $mergedFirewalls[$key] = $firewall;
                        continue;
                    }

                    $existing = $mergedFirewalls[$key];
                    $existing['available_in_projects'] = array_values(array_unique(array_merge(
                        (array) ($existing['available_in_projects'] ?? []),
                        [$project['ref']]
                    )));
                    $mergedFirewalls[$key] = $existing;
                }

                $loadedRefs[] = $project['ref'];
            } catch (\Throwable $e) {
                $errors[] = [
                    'project_ref' => $project['ref'],
                    'message' => $e->getMessage(),
                ];
            }
        }

        $serverTypes = array_values($mergedTypes);
        usort($serverTypes, static function (array $a, array $b): int {
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $locations = array_values($mergedLocations);
        usort($locations, static function (array $a, array $b): int {
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $images = array_values($mergedImages);
        usort($images, static function (array $a, array $b): int {
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $firewalls = array_values($mergedFirewalls);
        usort($firewalls, static function (array $a, array $b): int {
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return [
            'server_types' => $serverTypes,
            'locations' => $locations,
            'images' => $images,
            'firewalls' => $firewalls,
            'option_pricing' => $mergedOptionPricing,
            'projects_loaded' => $loadedRefs,
            'errors' => $errors,
            'synced_at' => date('c'),
        ];
    }

    public function testConnection(array $data = []): array
    {
        try {
            $config = $this->getModuleConfig();

            $projectRef = $this->sanitizeProjectRef((string) ($data['project_ref'] ?? ''));
            if ($projectRef !== '') {
                try {
                    $project = $this->resolveProjectByRef($projectRef, false);
                } catch (\Throwable $e) {
                    // Allow testing an unsaved project payload directly from form data.
                    $project = $this->ensureProjectDefaults([
                        'ref' => $projectRef,
                        'label' => (string) ($data['label'] ?? ucfirst($projectRef) . ' project'),
                        'api_url' => (string) ($data['api_url'] ?? self::DEFAULT_API_URL),
                        'api_token' => (string) ($data['api_token'] ?? ''),
                        'verify_ssl' => (string) ($data['verify_ssl'] ?? '1'),
                        'timeout' => (string) ($data['timeout'] ?? '20'),
                        'max_servers' => (string) ($data['max_servers'] ?? '0'),
                        'priority' => (string) ($data['priority'] ?? '100'),
                    ]);
                }
            } else {
                $defaultRef = (string) ($config['default_project_ref'] ?? self::DEFAULT_PROJECT_REF);
                $project = $this->resolveProjectByRef($defaultRef, true);
            }

            // Backward-compatible direct form test with explicit credentials.
            if (isset($data['api_url']) || array_key_exists('api_token', $data) || isset($data['verify_ssl']) || isset($data['timeout'])) {
                if (isset($data['api_url']) && trim((string) $data['api_url']) !== '') {
                    $project['api_url'] = rtrim(trim((string) $data['api_url']), '/');
                }

                if (array_key_exists('api_token', $data) && trim((string) $data['api_token']) !== '' && !preg_match('/^[*\\x{2022}]+$/u', (string) $data['api_token'])) {
                    $project['api_token'] = trim((string) $data['api_token']);
                }

                if (isset($data['verify_ssl'])) {
                    $project['verify_ssl'] = $this->parseBool($data['verify_ssl']) ? '1' : '0';
                }

                if (isset($data['timeout']) && is_numeric($data['timeout'])) {
                    $project['timeout'] = (string) max(3, min(120, (int) $data['timeout']));
                }
            }

            if (trim((string) $project['api_token']) === '') {
                return [
                    'ok' => false,
                    'message' => 'Hetzner API token is required.',
                    'http_code' => 400,
                    'project_ref' => $project['ref'],
                ];
            }

            $response = $this->performHetznerRequest('GET', $project, '/servers?per_page=1');
            if ($response['transport_error'] !== '') {
                return [
                    'ok' => false,
                    'message' => 'Hetzner API request failed: ' . $response['transport_error'],
                    'http_code' => 0,
                    'project_ref' => $project['ref'],
                ];
            }

            $decoded = $this->decodeJson($response['raw_body']);
            if ($response['http_code'] >= 200 && $response['http_code'] < 300) {
                $totalEntries = (int) ($decoded['meta']['pagination']['total_entries'] ?? 0);
                if ($totalEntries === 0 && isset($decoded['servers']) && is_array($decoded['servers'])) {
                    $totalEntries = count($decoded['servers']);
                }

                return [
                    'ok' => true,
                    'message' => 'Connection successful.',
                    'http_code' => $response['http_code'],
                    'project_ref' => $project['ref'],
                    'used_servers' => $totalEntries,
                    'token_redacted' => $this->redactToken((string) $project['api_token']),
                ];
            }

            return [
                'ok' => false,
                'message' => 'Hetzner API HTTP ' . $response['http_code'] . ': ' . $this->extractHetznerError($decoded),
                'http_code' => $response['http_code'],
                'project_ref' => $project['ref'],
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Connection test runtime failure: ' . $e->getMessage(),
                'http_code' => 500,
            ];
        }
    }

    public function getOrderServerDetails(\Model_ClientOrder $order): array
    {
        $service = $this->getServiceByOrder($order);

        $base = [
            'order_id' => $order->id,
            'status' => (string) $service->status,
            'provision_status' => (string) $service->provision_status,
            'project_ref' => (string) $service->project_ref,
            'hcloud_server_id' => (string) ($service->hcloud_server_id ?? ''),
            'config' => $this->decodeJson((string) $service->config),
            'billing' => $this->getServiceBillingSummary($service, $order),
        ];

        if (empty($service->hcloud_server_id)) {
            $base['server'] = null;
            return $base;
        }

        $project = $this->resolveProjectForService($service, $order);
        $server = $this->fetchServerById($project, (string) $service->hcloud_server_id);

        $base['server'] = $server;

        return $base;
    }

    public function runOrderPowerAction(\Model_ClientOrder $order, string $action): array
    {
        $service = $this->getServiceByOrder($order);
        if (empty($service->hcloud_server_id)) {
            throw new \FOSSBilling\InformationException('No Hetzner server is attached to this order yet.');
        }

        $normalizedAction = strtolower(trim($action));
        $allowed = [
            'poweron' => 'poweron',
            'poweroff' => 'poweroff',
            'reboot' => 'reboot',
            'shutdown' => 'shutdown',
            'reset' => 'reset',
        ];

        if (!isset($allowed[$normalizedAction])) {
            throw new \FOSSBilling\InformationException('Unsupported Hetzner power action :action', [':action' => $action]);
        }

        if ($normalizedAction === 'poweron' && !$this->canPowerOnByBalance($service)) {
            throw new \FOSSBilling\InformationException('Prepaid hourly balance is exhausted. Top up hours to power on this server.');
        }

        $project = $this->resolveProjectForService($service, $order);
        $path = '/servers/' . rawurlencode((string) $service->hcloud_server_id) . '/actions/' . $allowed[$normalizedAction];

        $response = $this->performHetznerRequest('POST', $project, $path, []);
        $body = $this->assertHetznerSuccess($response, 'Failed to run power action');

        if (in_array($normalizedAction, ['poweroff', 'shutdown'], true)) {
            $service->status = 'suspended';
        }
        if (in_array($normalizedAction, ['poweron', 'reboot', 'reset'], true)) {
            $service->status = 'active';
        }

        $service->updated_at = date('Y-m-d H:i:s');
        $this->touchBillingAccountedAt($service);
        $service->config = json_encode(array_merge(
            $this->decodeJson((string) $service->config),
            [
                'last_action' => [
                    'action' => $normalizedAction,
                    'at' => date('c'),
                    'result' => $body,
                ],
            ]
        ));
        $this->di['db']->store($service);

        return [
            'ok' => true,
            'action' => $normalizedAction,
            'result' => $body,
        ];
    }

    public function onAfterAdminCronRun(\Box_Event $event): void
    {
        try {
            $this->runHourlyBillingTick(true);
        } catch (\Throwable $e) {
            error_log('Servicehetzner hourly billing cron failed: ' . $e->getMessage());
        }
    }

    public function onAfterAdminInvoicePaymentReceived(\Box_Event $event): bool
    {
        try {
            $params = $event->getParameters();
            $invoiceId = (int) ($params['id'] ?? 0);
            if ($invoiceId > 0) {
                $this->applyPendingTopupsForInvoice($invoiceId);
            }
        } catch (\Throwable $e) {
            error_log('Servicehetzner top-up application failed: ' . $e->getMessage());
        }

        return true;
    }

    public function runHourlyBillingTick(bool $applyTopups = true): array
    {
        $this->ensureSchema();
        $processed = 0;
        $chargedHours = 0;
        $suspended = 0;
        $errors = [];

        if ($applyTopups) {
            try {
                $this->applyPendingTopupsForInvoice(0);
            } catch (\Throwable $e) {
                $errors[] = 'Top-up apply failed: ' . $e->getMessage();
            }
        }

        $services = $this->di['db']->find(
            'service_hetzner',
            'provision_status = :ps AND hcloud_server_id IS NOT NULL',
            [':ps' => 'provisioned']
        );

        foreach ($services as $service) {
            if (!is_object($service)) {
                continue;
            }

            try {
                $order = $this->di['db']->findOne('ClientOrder', 'id = :id', [':id' => (int) $service->order_id]);
                if (!$order instanceof \Model_ClientOrder) {
                    continue;
                }

                $summary = $this->getServiceBillingSummary($service, $order);
                if (($summary['mode'] ?? self::BILLING_MODE_STANDARD) !== self::BILLING_MODE_PREPAID_HOURS) {
                    continue;
                }

                if ((string) $service->status !== 'active') {
                    $this->touchBillingAccountedAt($service);
                    $this->di['db']->store($service);
                    continue;
                }

                $delta = $this->applyHourlyConsumptionForService($service, $summary);
                $processed++;
                $chargedHours += max(0, (int) ($delta['charged_hours'] ?? 0));
                if (!empty($delta['suspended'])) {
                    $suspended++;
                }
            } catch (\Throwable $e) {
                $errors[] = 'Service #' . ((int) ($service->id ?? 0)) . ': ' . $e->getMessage();
            }
        }

        return [
            'ok' => true,
            'processed' => $processed,
            'charged_hours' => $chargedHours,
            'suspended' => $suspended,
            'errors' => $errors,
            'at' => date('c'),
        ];
    }

    public function createTopupInvoice(\Model_ClientOrder $order, int $hours): array
    {
        $this->ensureSchema();
        $service = $this->getServiceByOrder($order);
        $summary = $this->getServiceBillingSummary($service, $order);
        if (($summary['mode'] ?? self::BILLING_MODE_STANDARD) !== self::BILLING_MODE_PREPAID_HOURS) {
            throw new \FOSSBilling\InformationException('This service is not using prepaid hourly billing mode.');
        }

        $minHours = (int) ($summary['topup_min_hours'] ?? 1);
        $maxHours = (int) ($summary['topup_max_hours'] ?? 8760);
        $hours = $this->normalizeHours($hours, $minHours, $maxHours, $minHours);
        if ($hours <= 0) {
            throw new \FOSSBilling\InformationException('Top-up hours are invalid.');
        }

        $hourlyRate = (float) ($summary['hourly_rate'] ?? 0);
        if ($hourlyRate <= 0) {
            $hourlyRate = $this->resolveHourlyRateFromOrder($order, $summary);
        }
        if ($hourlyRate <= 0) {
            throw new \FOSSBilling\InformationException('Hourly rate is not configured for this service.');
        }

        $client = $this->di['db']->findOne('Client', 'id = :id', [':id' => (int) $order->client_id]);
        if (!$client instanceof \Model_Client) {
            throw new \FOSSBilling\InformationException('Client not found for this service.');
        }

        $invoiceService = $this->di['mod_service']('Invoice');
        $invoice = $invoiceService->prepareInvoice($client, [
            'approve' => true,
            'items' => [
                [
                    'title' => 'Hetzner hourly top-up (' . $hours . 'h) for order #' . $order->id,
                    'price' => $hourlyRate,
                    'quantity' => $hours,
                    'unit' => 'hour',
                    'taxed' => false,
                    'type' => \Model_InvoiceItem::TYPE_CUSTOM,
                    'task' => \Model_InvoiceItem::TASK_VOID,
                ],
            ],
        ]);

        $topup = $this->di['db']->dispense('service_hetzner_topup');
        $topup->service_id = (int) $service->id;
        $topup->order_id = (int) $order->id;
        $topup->client_id = (int) $order->client_id;
        $topup->invoice_id = (int) $invoice->id;
        $topup->hours = $hours;
        $topup->hourly_rate = $hourlyRate;
        $topup->currency = (string) ($summary['currency'] ?? $order->currency ?? '');
        $topup->status = 'pending_payment';
        $topup->created_at = date('Y-m-d H:i:s');
        $topup->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($topup);

        $url = '';
        try {
            $url = $this->di['url']->link('invoice/' . $invoice->hash);
        } catch (\Throwable $e) {
            $url = '';
        }

        return [
            'ok' => true,
            'invoice_id' => (int) $invoice->id,
            'invoice_hash' => (string) ($invoice->hash ?? ''),
            'invoice_url' => $url,
            'hours' => $hours,
            'hourly_rate' => $hourlyRate,
            'currency' => (string) ($summary['currency'] ?? $order->currency ?? ''),
        ];
    }

    private function applyPendingTopupsForInvoice(int $invoiceId = 0): int
    {
        $params = [];
        $where = 'status = :status';
        $params[':status'] = 'pending_payment';
        if ($invoiceId > 0) {
            $where .= ' AND invoice_id = :invoice_id';
            $params[':invoice_id'] = $invoiceId;
        }

        $rows = $this->di['db']->find('service_hetzner_topup', $where, $params);
        $applied = 0;
        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }

            $invoice = $this->di['db']->findOne('Invoice', 'id = :id', [':id' => (int) $row->invoice_id]);
            if (!$invoice instanceof \Model_Invoice) {
                continue;
            }
            if ((string) $invoice->status !== \Model_Invoice::STATUS_PAID) {
                continue;
            }

            $service = $this->di['db']->findOne('service_hetzner', 'id = :id', [':id' => (int) $row->service_id]);
            if (!$service) {
                $row->status = 'cancelled';
                $row->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($row);
                continue;
            }

            $order = $this->di['db']->findOne('ClientOrder', 'id = :id', [':id' => (int) $row->order_id]);
            if (!$order instanceof \Model_ClientOrder) {
                $row->status = 'cancelled';
                $row->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($row);
                continue;
            }

            $summary = $this->getServiceBillingSummary($service, $order);
            if (($summary['mode'] ?? self::BILLING_MODE_STANDARD) !== self::BILLING_MODE_PREPAID_HOURS) {
                $row->status = 'cancelled';
                $row->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($row);
                continue;
            }

            $hours = max(1, (int) ($row->hours ?? 0));
            $state = $this->readBillingStateFromService($service);
            $state['hours_purchased_total'] = max(0, (int) ($state['hours_purchased_total'] ?? 0)) + $hours;
            $state['hours_balance'] = max(0, (int) ($state['hours_balance'] ?? 0)) + $hours;
            $state['hold_reason'] = '';
            $state['hold_since'] = '';
            $state['last_topup_at'] = date('c');
            $this->touchBillingState($state);
            $this->writeBillingStateToService($service, $state);

            if ((string) $service->status === 'suspended' && $this->parseBool($state['auto_poweron_on_topup'] ?? '1')) {
                try {
                    if (!empty($service->hcloud_server_id)) {
                        $this->runOrderPowerAction($order, 'poweron');
                    }
                    $service->status = 'active';
                    $service->updated_at = date('Y-m-d H:i:s');
                    $this->touchBillingAccountedAt($service);
                } catch (\Throwable $e) {
                    // Keep top-up applied even if power action fails.
                }
            }

            $this->di['db']->store($service);

            $row->status = 'applied';
            $row->applied_at = date('Y-m-d H:i:s');
            $row->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($row);
            $applied++;
        }

        return $applied;
    }

    private function ensureSchema(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS service_hetzner (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                order_id INTEGER NOT NULL,
                client_id INTEGER NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                provision_status VARCHAR(64) NOT NULL DEFAULT 'not_provisioned',
                hcloud_server_id VARCHAR(64) NULL,
                project_ref VARCHAR(64) NULL,
                config MEDIUMTEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        $this->di['db']->exec($sql);

        $topupSql = "
            CREATE TABLE IF NOT EXISTS service_hetzner_topup (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                service_id INTEGER NOT NULL,
                order_id INTEGER NOT NULL,
                client_id INTEGER NOT NULL,
                invoice_id INTEGER NOT NULL,
                hours INTEGER NOT NULL,
                hourly_rate DECIMAL(20,8) NOT NULL DEFAULT 0,
                currency VARCHAR(8) NOT NULL DEFAULT '',
                status VARCHAR(32) NOT NULL DEFAULT 'pending_payment',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                applied_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $this->di['db']->exec($topupSql);

        $alterQueries = [
            'ALTER TABLE service_hetzner ADD COLUMN project_ref VARCHAR(64) NULL',
            'ALTER TABLE service_hetzner ADD COLUMN provision_status VARCHAR(64) NOT NULL DEFAULT "not_provisioned"',
        ];

        foreach ($alterQueries as $alterSql) {
            try {
                $this->di['db']->exec($alterSql);
            } catch (\Throwable $e) {
                // Ignore "duplicate column" and other already-migrated scenarios.
            }
        }
    }

    private function getServiceByOrder(\Model_ClientOrder $order, bool $required = true)
    {
        $service = $this->di['db']->findOne('service_hetzner', 'order_id = :oid', [':oid' => $order->id]);

        if (!$service && $required) {
            throw new \FOSSBilling\Exception('Hetzner service record not found for order :id', [':id' => $order->id]);
        }

        return $service;
    }

    private function getOrderConfig(\Model_ClientOrder $order): array
    {
        $orderService = $this->getOrderService();
        $orderConfig = $orderService->getConfig($order);
        $orderConfig = is_array($orderConfig) ? $orderConfig : [];

        $productConfig = [];
        try {
            $product = $this->di['db']->load('Product', $order->product_id);
            if ($product instanceof \Model_Product) {
                $productConfig = $this->decodeJson((string) $product->config);
            }
        } catch (\Throwable $e) {
            // Ignore and continue with order config only.
        }

        $merged = array_merge($productConfig, $orderConfig);

        // Normalize compact aliases frequently used in product JSON.
        if (isset($merged['type']) && !isset($merged['server_type'])) {
            $merged['server_type'] = $merged['type'];
        }
        if (isset($merged['os_image']) && !isset($merged['image'])) {
            $merged['image'] = $merged['os_image'];
        }

        $merged['__product_config'] = $productConfig;

        return $merged;
    }

    private function getOrderService()
    {
        $factory = $this->di['mod_service'] ?? null;
        if (!is_callable($factory)) {
            throw new \FOSSBilling\InformationException('mod_service factory is not callable in Servicehetzner runtime.');
        }

        $service = $factory('order');
        if (!$service) {
            throw new \FOSSBilling\InformationException('Unable to resolve order module service.');
        }

        return $service;
    }

    private function normalizeBillingMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return $mode === self::BILLING_MODE_PREPAID_HOURS ? self::BILLING_MODE_PREPAID_HOURS : self::BILLING_MODE_STANDARD;
    }

    private function normalizeHours($value, int $min, int $max, int $default): int
    {
        $min = max(1, $min);
        $max = max($min, $max);
        $default = max($min, min($max, $default));

        if (!is_numeric($value)) {
            return $default;
        }

        $hours = (int) ceil((float) $value);
        if ($hours < $min) {
            return $min;
        }
        if ($hours > $max) {
            return $max;
        }

        return $hours;
    }

    private function resolveBillingPolicyFromOrderConfig(array $orderConfig): array
    {
        $productConfig = is_array($orderConfig['__product_config'] ?? null) ? $orderConfig['__product_config'] : [];
        $merged = array_merge($productConfig, $orderConfig);

        $minHours = $this->normalizeHours($merged['prepaid_hours_min'] ?? 1, 1, 8760, 1);
        $maxHours = $this->normalizeHours($merged['prepaid_hours_max'] ?? 8760, $minHours, 8760, 8760);
        $defaultHours = $this->normalizeHours($merged['prepaid_hours_default'] ?? 24, $minHours, $maxHours, 24);
        $topupMin = $this->normalizeHours($merged['topup_hours_min'] ?? $minHours, 1, 8760, $minHours);
        $topupMax = $this->normalizeHours($merged['topup_hours_max'] ?? $maxHours, $topupMin, 8760, $maxHours);

        $hourlyRate = isset($merged['hourly_rate']) && is_numeric($merged['hourly_rate'])
            ? max(0, (float) $merged['hourly_rate'])
            : 0.0;

        return [
            'mode' => $this->normalizeBillingMode((string) ($merged['billing_mode'] ?? self::BILLING_MODE_STANDARD)),
            'default_hours' => $defaultHours,
            'min_hours' => $minHours,
            'max_hours' => $maxHours,
            'topup_min_hours' => $topupMin,
            'topup_max_hours' => $topupMax,
            'hourly_rate' => $hourlyRate,
            'auto_suspend_on_exhaustion' => $this->parseBool($merged['auto_suspend_on_exhaustion'] ?? '1'),
            'auto_poweron_on_topup' => $this->parseBool($merged['auto_poweron_on_topup'] ?? '1'),
        ];
    }

    private function resolveHourlyRateFromOrder(\Model_ClientOrder $order, array $summary = []): float
    {
        $fromSummary = isset($summary['hourly_rate']) && is_numeric($summary['hourly_rate']) ? (float) $summary['hourly_rate'] : 0.0;
        if ($fromSummary > 0) {
            return $fromSummary;
        }

        $orderUnitPrice = isset($order->price) && is_numeric($order->price) ? (float) $order->price : 0.0;
        if ($orderUnitPrice > 0) {
            return $orderUnitPrice;
        }

        return 0.0;
    }

    private function isRecurringOrder(\Model_ClientOrder $order): bool
    {
        if (!isset($order->period)) {
            return false;
        }

        $period = trim((string) $order->period);
        if ($period === '') {
            return false;
        }

        return strtoupper($period) !== '0';
    }

    private function readBillingStateFromService($service): array
    {
        $config = $this->decodeJson((string) $service->config);
        $state = is_array($config['billing'] ?? null) ? $config['billing'] : [];

        return is_array($state) ? $state : [];
    }

    private function touchBillingState(array &$state): void
    {
        $state['updated_at'] = date('c');
    }

    private function writeBillingStateToService($service, array $state): void
    {
        $config = $this->decodeJson((string) $service->config);
        $this->touchBillingState($state);
        $config['billing'] = $state;
        $service->config = json_encode($config);
    }

    private function initializePrepaidBillingState($service, \Model_ClientOrder $order, array $orderConfig): void
    {
        $policy = $this->resolveBillingPolicyFromOrderConfig($orderConfig);
        if ($policy['mode'] !== self::BILLING_MODE_PREPAID_HOURS) {
            return;
        }

        $state = $this->readBillingStateFromService($service);
        $mode = $this->normalizeBillingMode((string) ($state['mode'] ?? ''));
        if ($mode === self::BILLING_MODE_PREPAID_HOURS && isset($state['hours_balance'])) {
            if (!isset($state['hourly_rate']) || !is_numeric($state['hourly_rate']) || (float) $state['hourly_rate'] <= 0) {
                $state['hourly_rate'] = $this->resolveHourlyRateFromOrder($order, ['hourly_rate' => (float) ($policy['hourly_rate'] ?? 0)]);
            }
            if (!isset($state['currency']) || trim((string) $state['currency']) === '') {
                $state['currency'] = (string) ($order->currency ?? '');
            }
            $this->writeBillingStateToService($service, $state);
            return;
        }

        $initialHours = $this->normalizeHours(
            $orderConfig['prepaid_hours'] ?? $orderConfig['quantity'] ?? $policy['default_hours'],
            $policy['min_hours'],
            $policy['max_hours'],
            $policy['default_hours']
        );
        $hourlyRate = (float) ($policy['hourly_rate'] ?? 0);
        if ($hourlyRate <= 0) {
            $hourlyRate = $this->resolveHourlyRateFromOrder($order, ['hourly_rate' => $hourlyRate]);
        }

        $state = [
            'mode' => self::BILLING_MODE_PREPAID_HOURS,
            'hourly_rate' => $hourlyRate,
            'currency' => (string) ($order->currency ?? ''),
            'hours_purchased_total' => $initialHours,
            'hours_consumed' => 0,
            'hours_balance' => $initialHours,
            'active_seconds_total' => 0,
            'last_accounted_at' => date('c'),
            'topup_min_hours' => (int) $policy['topup_min_hours'],
            'topup_max_hours' => (int) $policy['topup_max_hours'],
            'auto_suspend_on_exhaustion' => $policy['auto_suspend_on_exhaustion'] ? '1' : '0',
            'auto_poweron_on_topup' => $policy['auto_poweron_on_topup'] ? '1' : '0',
            'hold_reason' => '',
            'hold_since' => '',
            'initialized_at' => date('c'),
        ];

        $this->writeBillingStateToService($service, $state);
    }

    private function getServiceBillingSummary($service, \Model_ClientOrder $order): array
    {
        $orderConfig = [];
        try {
            $orderConfig = $this->getOrderConfig($order);
        } catch (\Throwable $e) {
            $orderConfig = [];
        }
        $policy = $this->resolveBillingPolicyFromOrderConfig($orderConfig);
        $state = $this->readBillingStateFromService($service);

        $mode = $this->normalizeBillingMode((string) ($state['mode'] ?? $policy['mode']));
        $hourlyRate = isset($state['hourly_rate']) && is_numeric($state['hourly_rate'])
            ? max(0, (float) $state['hourly_rate'])
            : max(0, (float) ($policy['hourly_rate'] ?? 0));
        if ($hourlyRate <= 0) {
            $hourlyRate = $this->resolveHourlyRateFromOrder($order, ['hourly_rate' => $hourlyRate]);
        }

        $hoursPurchased = max(0, (int) ($state['hours_purchased_total'] ?? 0));
        $hoursConsumed = max(0, (int) ($state['hours_consumed'] ?? 0));
        $hoursBalance = max(0, (int) ($state['hours_balance'] ?? max($hoursPurchased - $hoursConsumed, 0)));

        return [
            'mode' => $mode,
            'hourly_rate' => $hourlyRate,
            'currency' => trim((string) ($state['currency'] ?? $order->currency ?? '')),
            'hours_purchased_total' => $hoursPurchased,
            'hours_consumed' => $hoursConsumed,
            'hours_balance' => $hoursBalance,
            'active_seconds_total' => max(0, (int) ($state['active_seconds_total'] ?? 0)),
            'last_accounted_at' => (string) ($state['last_accounted_at'] ?? ''),
            'topup_min_hours' => max(1, (int) ($state['topup_min_hours'] ?? $policy['topup_min_hours'] ?? 1)),
            'topup_max_hours' => max(1, (int) ($state['topup_max_hours'] ?? $policy['topup_max_hours'] ?? 8760)),
            'auto_suspend_on_exhaustion' => $this->parseBool($state['auto_suspend_on_exhaustion'] ?? ($policy['auto_suspend_on_exhaustion'] ? '1' : '0')),
            'auto_poweron_on_topup' => $this->parseBool($state['auto_poweron_on_topup'] ?? ($policy['auto_poweron_on_topup'] ? '1' : '0')),
            'hold_reason' => (string) ($state['hold_reason'] ?? ''),
            'hold_since' => (string) ($state['hold_since'] ?? ''),
            'updated_at' => (string) ($state['updated_at'] ?? ''),
        ];
    }

    private function touchBillingAccountedAt($service): void
    {
        $state = $this->readBillingStateFromService($service);
        if ($this->normalizeBillingMode((string) ($state['mode'] ?? '')) !== self::BILLING_MODE_PREPAID_HOURS) {
            return;
        }
        $state['last_accounted_at'] = date('c');
        $this->writeBillingStateToService($service, $state);
    }

    private function canPowerOnByBalance($service): bool
    {
        $state = $this->readBillingStateFromService($service);
        if ($this->normalizeBillingMode((string) ($state['mode'] ?? '')) !== self::BILLING_MODE_PREPAID_HOURS) {
            return true;
        }

        return max(0, (int) ($state['hours_balance'] ?? 0)) > 0;
    }

    private function applyHourlyConsumptionForService($service, array $summary): array
    {
        $state = $this->readBillingStateFromService($service);
        if (($summary['mode'] ?? self::BILLING_MODE_STANDARD) !== self::BILLING_MODE_PREPAID_HOURS) {
            return ['charged_hours' => 0, 'suspended' => false];
        }

        $now = time();
        $lastTs = strtotime((string) ($state['last_accounted_at'] ?? ''));
        if ($lastTs === false || $lastTs <= 0 || $lastTs > $now) {
            $lastTs = $now;
        }
        $deltaSeconds = max(0, $now - $lastTs);
        $activeSeconds = max(0, (int) ($state['active_seconds_total'] ?? 0)) + $deltaSeconds;
        $state['active_seconds_total'] = $activeSeconds;
        $state['last_accounted_at'] = date('c', $now);

        $alreadyConsumed = max(0, (int) ($state['hours_consumed'] ?? 0));
        $shouldConsume = $activeSeconds > 0 ? (int) ceil($activeSeconds / 3600) : 0;
        $chargeHours = max(0, $shouldConsume - $alreadyConsumed);
        if ($chargeHours > 0) {
            $state['hours_consumed'] = $alreadyConsumed + $chargeHours;
            $state['hours_balance'] = max(0, max(0, (int) ($state['hours_balance'] ?? 0)) - $chargeHours);
        }

        $suspended = false;
        $balance = max(0, (int) ($state['hours_balance'] ?? 0));
        $autoSuspend = $this->parseBool($state['auto_suspend_on_exhaustion'] ?? ($summary['auto_suspend_on_exhaustion'] ? '1' : '0'));
        if ($balance <= 0 && $autoSuspend && (string) ($service->status ?? '') === 'active') {
            $order = $this->di['db']->findOne('ClientOrder', 'id = :id', [':id' => (int) $service->order_id]);
            if ($order instanceof \Model_ClientOrder) {
                try {
                    if (!empty($service->hcloud_server_id)) {
                        $this->runOrderPowerAction($order, 'poweroff');
                    }
                } catch (\Throwable $e) {
                    // Continue suspension even if remote action fails.
                }
            }

            $state['hold_reason'] = 'insufficient_hours';
            $state['hold_since'] = date('c');
            $service->status = 'suspended';
            $suspended = true;
        }

        $this->writeBillingStateToService($service, $state);
        $service->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($service);

        return [
            'charged_hours' => $chargeHours,
            'suspended' => $suspended,
        ];
    }

    private function createServer(\Model_ClientOrder $order, array $project, array $orderConfig): array
    {
        $productConfig = is_array($orderConfig['__product_config'] ?? null) ? $orderConfig['__product_config'] : [];

        $name = trim((string) ($orderConfig['hostname'] ?? $orderConfig['name'] ?? ''));
        if ($name === '') {
            $name = 'fb-' . $order->id . '-' . date('ymdHis');
        }

        $name = preg_replace('/[^a-zA-Z0-9-]+/', '-', strtolower($name));
        $name = trim((string) $name, '-');
        if ($name === '') {
            $name = 'fb-' . $order->id;
        }
        if (strlen($name) > 63) {
            $name = substr($name, 0, 63);
            $name = rtrim($name, '-');
        }

        $payload = [
            'name' => $name,
            'server_type' => trim((string) $this->resolveSelectedValue($orderConfig, $productConfig, 'server_type', 'allow_server_type_choice')),
            'image' => trim((string) $this->resolveSelectedValue($orderConfig, $productConfig, 'image', 'allow_image_choice')),
            'start_after_create' => true,
        ];

        $selectedLocation = trim((string) $this->resolveSelectedValue($orderConfig, $productConfig, 'location', 'allow_location_choice'));
        if ($selectedLocation !== '') {
            $this->assertServerTypeSupportsLocation($project, (string) $payload['server_type'], $selectedLocation);
            $payload['location'] = $selectedLocation;
        }

        if (isset($orderConfig['datacenter']) && trim((string) $orderConfig['datacenter']) !== '') {
            $payload['datacenter'] = trim((string) $orderConfig['datacenter']);
        }

        if (isset($orderConfig['ssh_keys'])) {
            $payload['ssh_keys'] = $this->parseCsvOrArray($orderConfig['ssh_keys']);
        }

        $payload['backups'] = $this->resolveBooleanSetting($orderConfig, $productConfig, 'enable_backups', false);
        $payload['public_net']['enable_ipv4'] = $this->resolveBooleanSetting($orderConfig, $productConfig, 'enable_ipv4', true);
        $payload['public_net']['enable_ipv6'] = $this->resolveBooleanSetting($orderConfig, $productConfig, 'enable_ipv6', true);

        $firewallIds = $this->resolveFirewallIds($orderConfig, $productConfig);
        if (!empty($firewallIds)) {
            $payload['firewalls'] = array_map(static function (int $id): array {
                return ['firewall' => $id];
            }, $firewallIds);
        }

        if (isset($orderConfig['labels'])) {
            $labels = $this->normalizeLabels($orderConfig['labels']);
            if (!empty($labels)) {
                $payload['labels'] = $labels;
            }
        }

        if (isset($orderConfig['user_data']) && trim((string) $orderConfig['user_data']) !== '') {
            $payload['user_data'] = (string) $orderConfig['user_data'];
        }

        $response = $this->performHetznerRequest('POST', $project, '/servers', $payload);

        return $this->assertHetznerSuccess($response, 'Failed to create Hetzner server');
    }

    private function fetchServerById(array $project, string $serverId): array
    {
        $response = $this->performHetznerRequest('GET', $project, '/servers/' . rawurlencode($serverId));
        $body = $this->assertHetznerSuccess($response, 'Failed to fetch Hetzner server details');

        if (!isset($body['server']) || !is_array($body['server'])) {
            throw new \FOSSBilling\InformationException('Hetzner API did not return server details for server :id', [':id' => $serverId]);
        }

        return $body['server'];
    }

    private function deleteRemoteServer(\Model_ClientOrder $order, $service): void
    {
        $project = $this->resolveProjectForService($service, $order);
        if (empty($service->hcloud_server_id)) {
            return;
        }

        $path = '/servers/' . rawurlencode((string) $service->hcloud_server_id);
        $response = $this->performHetznerRequest('DELETE', $project, $path);

        if ($response['transport_error'] !== '') {
            throw new \FOSSBilling\InformationException('Failed to delete Hetzner server: :msg', [':msg' => $response['transport_error']]);
        }

        if ($response['http_code'] >= 200 && $response['http_code'] < 300) {
            return;
        }

        // Consider missing server already deleted.
        $decoded = $this->decodeJson($response['raw_body']);
        $errorCode = (string) ($decoded['error']['code'] ?? '');
        if ($response['http_code'] === 404 || $errorCode === 'not_found') {
            return;
        }

        throw new \FOSSBilling\InformationException(
            'Hetzner API HTTP :code while deleting server: :msg',
            [
                ':code' => (string) $response['http_code'],
                ':msg' => $this->extractHetznerError($decoded),
            ]
        );
    }

    private function resolveProjectForService($service, \Model_ClientOrder $order): array
    {
        $projectRef = trim((string) ($service->project_ref ?? ''));
        if ($projectRef !== '') {
            return $this->resolveProjectByRef($projectRef, true);
        }

        $orderConfig = $this->getOrderConfig($order);

        return $this->resolveProjectForOrder($orderConfig);
    }

    private function assertServerTypeSupportsLocation(array $project, string $serverType, string $location): void
    {
        $serverType = trim($serverType);
        $location = trim($location);
        if ($serverType === '' || $location === '') {
            return;
        }

        $inventory = is_array($project['inventory'] ?? null) ? $project['inventory'] : [];
        $types = is_array($inventory['server_types'] ?? null) ? $inventory['server_types'] : [];
        foreach ($types as $type) {
            if (!is_array($type) || (string) ($type['name'] ?? '') !== $serverType) {
                continue;
            }

            $allowedLocations = array_map('strval', (array) ($type['available_locations'] ?? []));
            if (!empty($allowedLocations) && !in_array($location, $allowedLocations, true)) {
                throw new \FOSSBilling\InformationException(
                    'Server type :type is not available in location :location for project :project',
                    [
                        ':type' => $serverType,
                        ':location' => $location,
                        ':project' => (string) ($project['ref'] ?? ''),
                    ]
                );
            }
        }
    }

    private function resolveProjectForOrder(array $orderConfig): array
    {
        $projectRef = $this->sanitizeProjectRef((string) ($orderConfig['project_ref'] ?? ''));
        if ($projectRef === '') {
            $moduleConfig = $this->getModuleConfig();
            $projectRef = (string) ($moduleConfig['default_project_ref'] ?? self::DEFAULT_PROJECT_REF);
        }

        return $this->resolveProjectByRef($projectRef, true);
    }

    private function resolveProvisioningProjectsForOrder(array $orderConfig): array
    {
        $explicitRef = $this->sanitizeProjectRef((string) ($orderConfig['project_ref'] ?? ''));
        if ($explicitRef !== '') {
            return [$this->resolveProjectByRef($explicitRef, true)];
        }

        $moduleConfig = $this->getModuleConfig();
        $projectsByRef = $this->indexProjectsByRef($moduleConfig['projects']);
        $projectsByRef = $this->ensureUniqueProjectPriorities($projectsByRef, $moduleConfig['default_project_ref'] ?? null);
        $defaultRef = $this->sanitizeProjectRef((string) ($moduleConfig['default_project_ref'] ?? ''));

        $projects = array_values($projectsByRef);
        usort($projects, static function (array $a, array $b) use ($defaultRef): int {
            $aPriority = (int) ($a['priority'] ?? 100);
            $bPriority = (int) ($b['priority'] ?? 100);
            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }

            $aIsDefault = ((string) ($a['ref'] ?? '') === $defaultRef);
            $bIsDefault = ((string) ($b['ref'] ?? '') === $defaultRef);
            if ($aIsDefault !== $bIsDefault) {
                return $aIsDefault ? -1 : 1;
            }

            return strcmp((string) ($a['ref'] ?? ''), (string) ($b['ref'] ?? ''));
        });

        $eligible = array_values(array_filter($projects, static function (array $project): bool {
            return trim((string) ($project['api_token'] ?? '')) !== '';
        }));

        if (empty($eligible)) {
            throw new \FOSSBilling\InformationException('No Hetzner projects with API token are configured.');
        }

        return $eligible;
    }

    private function projectHasAvailableCapacity(array $project): bool
    {
        $maxServers = (int) ($project['max_servers'] ?? 0);
        if ($maxServers <= 0) {
            return true;
        }

        try {
            $usedServers = $this->fetchProjectUsedServersCount($project);
            $this->refreshProjectUsage($project, $usedServers);

            return $usedServers < $maxServers;
        } catch (\Throwable $e) {
            $inventory = is_array($project['inventory'] ?? null) ? $project['inventory'] : [];
            $fallbackUsed = isset($inventory['used_servers']) && is_numeric($inventory['used_servers'])
                ? (int) $inventory['used_servers']
                : null;

            $this->updateProjectState($project['ref'], [
                'status' => 'error',
                'last_error' => $e->getMessage(),
                'last_sync_at' => date('c'),
            ]);

            if ($fallbackUsed !== null) {
                return $fallbackUsed < $maxServers;
            }

            return false;
        }
    }

    private function fetchProjectUsedServersCount(array $project): int
    {
        $response = $this->performHetznerRequest('GET', $project, '/servers?per_page=1');
        $body = $this->assertHetznerSuccess($response, 'Failed to load servers usage');

        $usedServers = (int) ($body['meta']['pagination']['total_entries'] ?? 0);
        if ($usedServers === 0 && isset($body['servers']) && is_array($body['servers'])) {
            $usedServers = count($body['servers']);
        }

        return $usedServers;
    }

    private function refreshProjectUsage(array $project, ?int $usedServers = null): void
    {
        if ($usedServers === null) {
            try {
                $usedServers = $this->fetchProjectUsedServersCount($project);
            } catch (\Throwable $e) {
                $this->updateProjectState($project['ref'], [
                    'status' => 'error',
                    'last_error' => $e->getMessage(),
                    'last_sync_at' => date('c'),
                ]);
                return;
            }
        }

        $inventory = is_array($project['inventory'] ?? null) ? $project['inventory'] : [];
        $maxServers = (int) ($project['max_servers'] ?? 0);
        $inventory['used_servers'] = $usedServers;
        $inventory['max_servers'] = $maxServers;
        $inventory['available_slots'] = $maxServers > 0 ? max($maxServers - $usedServers, 0) : null;
        $inventory['synced_at'] = date('c');

        $this->updateProjectState($project['ref'], [
            'status' => 'active',
            'last_error' => '',
            'last_sync_at' => date('c'),
            'inventory' => $inventory,
        ]);
    }

    private function isRetryableProvisioningError(string $message): bool
    {
        $msg = strtolower($message);
        $needles = [
            'resource_limit_exceeded',
            'resource_unavailable',
            'capacity',
            'quota',
            'not enough',
            'temporarily unavailable',
            'locked',
            'not available in location',
            'not available',
            'invalid_input',
        ];

        foreach ($needles as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function markProvisionFailure($service, string $projectRef, string $message): void
    {
        $service->status = 'failed';
        $service->provision_status = 'provision_failed';
        $service->updated_at = date('Y-m-d H:i:s');
        $service->config = json_encode(array_merge(
            $this->decodeJson((string) $service->config),
            [
                'project_ref' => $projectRef,
                'last_error' => $message,
                'failed_at' => date('c'),
            ]
        ));
        $this->di['db']->store($service);
    }

    private function resolveProjectByRef(string $projectRef, bool $requireToken = false): array
    {
        $ref = $this->sanitizeProjectRef($projectRef);
        if ($ref === '') {
            throw new \FOSSBilling\InformationException('Project ref is required.');
        }

        $projectsByRef = $this->indexProjectsByRef($this->getModuleConfig()['projects']);
        if (!isset($projectsByRef[$ref])) {
            throw new \FOSSBilling\InformationException('Hetzner project :ref is not configured.', [':ref' => $ref]);
        }

        $project = $projectsByRef[$ref];
        if ($requireToken && trim((string) $project['api_token']) === '') {
            throw new \FOSSBilling\InformationException('Hetzner project :ref has no API token configured.', [':ref' => $ref]);
        }

        return $project;
    }

    private function updateProjectState(string $projectRef, array $changes): void
    {
        $config = $this->getModuleConfig();
        $projectsByRef = $this->indexProjectsByRef($config['projects']);

        if (!isset($projectsByRef[$projectRef])) {
            return;
        }

        $projectsByRef[$projectRef] = $this->ensureProjectDefaults(array_merge($projectsByRef[$projectRef], $changes));

        $this->persistModuleConfig([
            'default_project_ref' => $config['default_project_ref'] ?? self::DEFAULT_PROJECT_REF,
            'delete_on_cancel' => $config['delete_on_cancel'] ?? '0',
            'projects' => array_values($projectsByRef),
        ]);
    }

    private function indexProjectsByRef(array $projects): array
    {
        $indexed = [];
        foreach ($projects as $project) {
            if (!is_array($project)) {
                continue;
            }

            $ref = $this->sanitizeProjectRef((string) ($project['ref'] ?? ''));
            if ($ref === '') {
                continue;
            }

            $project['ref'] = $ref;
            $indexed[$ref] = $this->ensureProjectDefaults($project);
        }

        if (empty($indexed)) {
            $indexed[self::DEFAULT_PROJECT_REF] = $this->ensureProjectDefaults([
                'ref' => self::DEFAULT_PROJECT_REF,
            ]);
        }

        return $indexed;
    }

    private function ensureUniqueProjectPriorities(array $projectsByRef, ?string $preferredRef = null): array
    {
        if (empty($projectsByRef)) {
            return $projectsByRef;
        }

        uasort($projectsByRef, static function (array $a, array $b) use ($preferredRef): int {
            $aPriority = (int) ($a['priority'] ?? 100);
            $bPriority = (int) ($b['priority'] ?? 100);
            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }

            $aRef = (string) ($a['ref'] ?? '');
            $bRef = (string) ($b['ref'] ?? '');
            if ($preferredRef !== null) {
                if ($aRef === $preferredRef && $bRef !== $preferredRef) {
                    return -1;
                }
                if ($bRef === $preferredRef && $aRef !== $preferredRef) {
                    return 1;
                }
            }

            return strcmp($aRef, $bRef);
        });

        $used = [];
        foreach ($projectsByRef as $ref => $project) {
            $priority = isset($project['priority']) && is_numeric($project['priority']) ? (int) $project['priority'] : 100;
            while (isset($used[$priority])) {
                $priority++;
            }

            $project['priority'] = (string) $priority;
            $projectsByRef[$ref] = $this->ensureProjectDefaults($project);
            $used[$priority] = true;
        }

        return $projectsByRef;
    }

    private function getNextProjectPriority(array $projectsByRef): int
    {
        $max = -1;
        foreach ($projectsByRef as $project) {
            if (!is_array($project)) {
                continue;
            }

            if (isset($project['priority']) && is_numeric($project['priority'])) {
                $max = max($max, (int) $project['priority']);
            }
        }

        return $max + 1;
    }

    private function ensureProjectDefaults(array $project): array
    {
        $ref = $this->sanitizeProjectRef((string) ($project['ref'] ?? self::DEFAULT_PROJECT_REF));
        if ($ref === '') {
            $ref = self::DEFAULT_PROJECT_REF;
        }

        $maxServers = isset($project['max_servers']) && is_numeric($project['max_servers']) ? max(0, (int) $project['max_servers']) : 0;
        $priority = isset($project['priority']) && is_numeric($project['priority']) ? (int) $project['priority'] : 100;

        return [
            'ref' => $ref,
            'label' => trim((string) ($project['label'] ?? ucfirst($ref) . ' project')),
            'api_url' => rtrim(trim((string) ($project['api_url'] ?? self::DEFAULT_API_URL)), '/'),
            'api_token' => (string) ($project['api_token'] ?? ''),
            'verify_ssl' => $this->parseBool($project['verify_ssl'] ?? '1') ? '1' : '0',
            'timeout' => (string) max(3, min(120, (int) ($project['timeout'] ?? 20))),
            'max_servers' => (string) $maxServers,
            'priority' => (string) $priority,
            'status' => (string) ($project['status'] ?? 'unknown'),
            'last_error' => (string) ($project['last_error'] ?? ''),
            'last_sync_at' => (string) ($project['last_sync_at'] ?? ''),
            'inventory' => is_array($project['inventory'] ?? null) ? $project['inventory'] : [],
        ];
    }

    private function redactProject(array $project, bool $withInventory = false): array
    {
        $inventory = is_array($project['inventory'] ?? null) ? $project['inventory'] : [];

        $result = [
            'ref' => (string) ($project['ref'] ?? ''),
            'label' => (string) ($project['label'] ?? ''),
            'api_url' => (string) ($project['api_url'] ?? self::DEFAULT_API_URL),
            'api_token_set' => trim((string) ($project['api_token'] ?? '')) !== '',
            'token_redacted' => $this->redactToken((string) ($project['api_token'] ?? '')),
            'verify_ssl' => (string) ($project['verify_ssl'] ?? '1'),
            'timeout' => (string) ($project['timeout'] ?? '20'),
            'max_servers' => (int) ($project['max_servers'] ?? 0),
            'priority' => (int) ($project['priority'] ?? 100),
            'status' => (string) ($project['status'] ?? 'unknown'),
            'last_error' => (string) ($project['last_error'] ?? ''),
            'last_sync_at' => (string) ($project['last_sync_at'] ?? ''),
        ];

        if ($withInventory) {
            $result['inventory'] = $inventory;
            $used = (int) ($inventory['used_servers'] ?? 0);
            $max = (int) ($project['max_servers'] ?? 0);
            $result['capacity'] = [
                'used_servers' => $used,
                'max_servers' => $max,
                'available_slots' => $max > 0 ? max($max - $used, 0) : null,
            ];
        }

        return $result;
    }

    private function redactToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        $len = strlen($token);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        return substr($token, 0, 4) . str_repeat('*', $len - 8) . substr($token, -4);
    }

    private function sanitizeProjectRef(string $ref): string
    {
        $sanitized = strtolower(trim($ref));
        $sanitized = preg_replace('/[^a-z0-9_-]+/', '-', $sanitized);
        $sanitized = trim((string) $sanitized, '-_');

        if (strlen($sanitized) > 64) {
            $sanitized = substr($sanitized, 0, 64);
            $sanitized = rtrim($sanitized, '-_');
        }

        return (string) $sanitized;
    }

    private function persistModuleConfig(array $config): void
    {
        $row = $this->di['db']->findOne(
            'extension_meta',
            'extension = :ext AND meta_key = :meta_key',
            [':ext' => self::EXT_KEY, ':meta_key' => 'config']
        );

        if (!$row) {
            $row = $this->di['db']->dispense('extension_meta');
            $row->extension = self::EXT_KEY;
            $row->meta_key = 'config';
            $row->created_at = date('Y-m-d H:i:s');
        }

        $payload = array_merge(['ext' => self::EXT_KEY], $config);
        $row->meta_value = json_encode($payload);
        $row->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($row);
    }

    private function performHetznerRequest(string $method, array $project, string $path, ?array $payload = null): array
    {
        $apiUrl = rtrim(trim((string) ($project['api_url'] ?? self::DEFAULT_API_URL)), '/');
        $apiToken = trim((string) ($project['api_token'] ?? ''));
        $verifySsl = $this->parseBool($project['verify_ssl'] ?? '1');
        $timeout = max(3, min(120, (int) ($project['timeout'] ?? 20)));

        $url = $apiUrl . '/' . ltrim($path, '/');
        $headers = [
            'Authorization: Bearer ' . $apiToken,
            'Accept: application/json',
            'User-Agent: FossBilling-Servicehetzner/0.2',
        ];

        $jsonPayload = null;
        if ($payload !== null) {
            $jsonPayload = json_encode($payload);
            $headers[] = 'Content-Type: application/json';
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return [
                    'http_code' => 0,
                    'raw_body' => '',
                    'transport_error' => 'Failed to initialize cURL',
                ];
            }

            $curlOptions = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => $verifySsl,
                CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
            ];

            if ($jsonPayload !== null) {
                $curlOptions[CURLOPT_POSTFIELDS] = $jsonPayload;
            }

            curl_setopt_array($ch, $curlOptions);

            $rawBody = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($rawBody === false) {
                return [
                    'http_code' => 0,
                    'raw_body' => '',
                    'transport_error' => $curlError !== '' ? $curlError : 'Unknown cURL error',
                ];
            }

            return [
                'http_code' => $httpCode,
                'raw_body' => (string) $rawBody,
                'transport_error' => '',
            ];
        }

        $headerLines = implode("\r\n", $headers);
        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => $headerLines . "\r\n",
                'timeout' => $timeout,
                'ignore_errors' => true,
                'content' => $jsonPayload ?? '',
            ],
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
            ],
        ]);

        $rawBody = @file_get_contents($url, false, $context);
        if ($rawBody === false) {
            $error = error_get_last();

            return [
                'http_code' => 0,
                'raw_body' => '',
                'transport_error' => (string) ($error['message'] ?? 'Unknown stream error'),
            ];
        }

        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])) {
            if (preg_match('#HTTP/\\S+\\s+(\\d{3})#', $http_response_header[0], $matches)) {
                $httpCode = (int) $matches[1];
            }
        }

        return [
            'http_code' => $httpCode,
            'raw_body' => (string) $rawBody,
            'transport_error' => '',
        ];
    }

    private function assertHetznerSuccess(array $response, string $contextMessage): array
    {
        if (($response['transport_error'] ?? '') !== '') {
            throw new \FOSSBilling\InformationException(
                $contextMessage . ': ' . (string) $response['transport_error']
            );
        }

        $httpCode = (int) ($response['http_code'] ?? 0);
        $body = $this->decodeJson((string) ($response['raw_body'] ?? ''));

        if ($httpCode >= 200 && $httpCode < 300) {
            return $body;
        }

        throw new \FOSSBilling\InformationException(
            $contextMessage . ' (HTTP ' . $httpCode . '): ' . $this->extractHetznerError($body)
        );
    }

    private function extractHetznerError(array $body): string
    {
        $message = (string) ($body['error']['message'] ?? $body['message'] ?? 'Unknown API error');

        $errorCode = (string) ($body['error']['code'] ?? '');
        if ($errorCode !== '') {
            $message = $errorCode . ': ' . $message;
        }

        $details = $body['error']['details'] ?? null;
        if (is_array($details) && !empty($details)) {
            $flatDetails = [];
            foreach ($details as $key => $detail) {
                if (is_scalar($detail)) {
                    $flatDetails[] = (string) $key . '=' . (string) $detail;
                }
            }

            if (!empty($flatDetails)) {
                $message .= ' (' . implode(', ', $flatDetails) . ')';
            }
        }

        return $message;
    }

    private function resolveSelectedValue(array $orderConfig, array $productConfig, string $key, string $allowChoiceKey): string
    {
        $allowChoice = $this->parseBool($productConfig[$allowChoiceKey] ?? '0');
        $orderValue = trim((string) ($orderConfig[$key] ?? ''));
        $productValue = trim((string) ($productConfig[$key] ?? ''));

        if ($allowChoice && $orderValue !== '') {
            return $orderValue;
        }

        if ($productValue !== '') {
            return $productValue;
        }

        return $orderValue;
    }

    private function resolveBooleanSetting(array $orderConfig, array $productConfig, string $key, bool $fallback): bool
    {
        $mode = $this->normalizeSettingMode((string) ($productConfig[$key . '_mode'] ?? ''));
        if ($mode === 'force_on') {
            return true;
        }
        if ($mode === 'force_off') {
            return false;
        }

        $defaultKey = $key . '_default';
        if (isset($productConfig[$defaultKey])) {
            $default = $this->parseBool($productConfig[$defaultKey]);
        } elseif (isset($productConfig[$key])) {
            $default = $this->parseBool($productConfig[$key]);
        } else {
            $default = $fallback;
        }

        if (array_key_exists($key, $orderConfig)) {
            return $this->parseBool($orderConfig[$key]);
        }

        return $default;
    }

    private function resolveFirewallIds(array $orderConfig, array $productConfig): array
    {
        $mode = strtolower(trim((string) ($productConfig['firewall_mode'] ?? 'none')));
        if ($mode === '' || in_array($mode, ['none', 'disabled', 'off'], true)) {
            return [];
        }

        $allowed = $this->parseIntList($productConfig['firewall_ids'] ?? []);
        $defaults = $this->parseIntList($productConfig['firewall_default_ids'] ?? $allowed);

        if (in_array($mode, ['force', 'required', 'force_on'], true)) {
            return !empty($defaults) ? $defaults : $allowed;
        }

        if (!in_array($mode, ['customer', 'optional', 'client'], true)) {
            return [];
        }

        $selected = [];
        if (array_key_exists('firewall_ids', $orderConfig)) {
            $selected = $this->parseIntList($orderConfig['firewall_ids']);
        } elseif (array_key_exists('firewall_id', $orderConfig)) {
            $selected = $this->parseIntList($orderConfig['firewall_id']);
        }

        if (empty($selected)) {
            $selected = $defaults;
        }

        if (empty($allowed)) {
            return $selected;
        }

        $allowedMap = array_flip($allowed);
        $filtered = array_values(array_filter($selected, static function (int $id) use ($allowedMap): bool {
            return isset($allowedMap[$id]);
        }));

        return $filtered;
    }

    private function normalizeSettingMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if ($mode === '') {
            return 'customer';
        }
        if (in_array($mode, ['force_on', 'on', 'enabled', 'yes', '1', 'true'], true)) {
            return 'force_on';
        }
        if (in_array($mode, ['force_off', 'off', 'disabled', 'no', '0', 'false'], true)) {
            return 'force_off';
        }
        if (in_array($mode, ['customer', 'optional', 'client', 'user'], true)) {
            return 'customer';
        }

        return 'customer';
    }

    private function parseIntList($value): array
    {
        $items = [];
        if (is_array($value)) {
            $items = $value;
        } elseif (is_scalar($value)) {
            $items = explode(',', (string) $value);
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $id = (int) trim((string) $item);
            if ($id > 0) {
                $result[$id] = $id;
            }
        }

        ksort($result);

        return array_values($result);
    }

    private function normalizeOptionPricing($pricing): array
    {
        $fallbackCurrency = 'EUR';
        $backupPercentage = null;
        $primaryIps = [];
        $ipv4 = [];
        $ipv6 = [];

        if (is_array($pricing)) {
            $currency = trim((string) ($pricing['currency'] ?? ''));
            if ($currency !== '') {
                $fallbackCurrency = $currency;
            }

            if (isset($pricing['backup_percentage']) && is_numeric($pricing['backup_percentage'])) {
                $backupPercentage = (float) $pricing['backup_percentage'];
            } else {
                $backup = is_array($pricing['server_backup'] ?? null) ? $pricing['server_backup'] : [];
                if (isset($backup['percentage']) && is_numeric($backup['percentage'])) {
                    $backupPercentage = (float) $backup['percentage'];
                }
            }

            $ipv4 = $this->mergePrimaryIpPricing([], is_array($pricing['ipv4'] ?? null) ? $pricing['ipv4'] : [], $fallbackCurrency);
            $ipv6 = $this->mergePrimaryIpPricing([], is_array($pricing['ipv6'] ?? null) ? $pricing['ipv6'] : [], $fallbackCurrency);
            $primaryIps = is_array($pricing['primary_ips'] ?? null) ? $pricing['primary_ips'] : [];
        }

        if (!empty($primaryIps)) {
            $ipv4 = $this->mergePrimaryIpPricing($ipv4, $this->normalizePrimaryIpPricing($primaryIps, 'ipv4', $fallbackCurrency), $fallbackCurrency);
            $ipv6 = $this->mergePrimaryIpPricing($ipv6, $this->normalizePrimaryIpPricing($primaryIps, 'ipv6', $fallbackCurrency), $fallbackCurrency);
        }

        return [
            'currency' => $fallbackCurrency,
            'backup_percentage' => $backupPercentage,
            'ipv4' => $this->mergePrimaryIpPricing([], $ipv4, $fallbackCurrency),
            'ipv6' => $this->mergePrimaryIpPricing([], $ipv6, $fallbackCurrency),
        ];
    }

    private function extractPriceAmount($value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach (['gross', 'net', 'amount', 'value'] as $key) {
            if (isset($value[$key]) && is_numeric($value[$key])) {
                return (float) $value[$key];
            }
        }

        return null;
    }

    private function extractPriceCurrency($value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $currency = trim((string) ($value['currency'] ?? ''));

        return $currency;
    }

    private function extractLocationName($value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if (!is_array($value)) {
            return '';
        }

        foreach (['name', 'location', 'code'] as $key) {
            if (!isset($value[$key]) || !is_scalar($value[$key])) {
                continue;
            }
            $name = trim((string) $value[$key]);
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    private function normalizeArchitectureValue($value): string
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return '';
        }

        $compact = str_replace(['-', '_', ' '], '', $raw);
        if (str_contains($compact, 'arm') || str_contains($compact, 'aarch64')) {
            return 'arm64';
        }
        if (str_contains($compact, 'x86') || str_contains($compact, 'amd64') || str_contains($compact, 'intel64')) {
            return 'x86_64';
        }

        return $raw;
    }

    private function normalizePrimaryIpPricing(array $rows, string $type, string $fallbackCurrency): array
    {
        $fromHourly = null;
        $fromMonthly = null;
        $currency = $fallbackCurrency;
        $byLocation = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strtolower(trim((string) ($row['type'] ?? ''))) !== $type) {
                continue;
            }

            $hourly = $row['price_hourly'] ?? null;
            $monthly = $row['price_monthly'] ?? null;
            $hourlyCurrency = $this->extractPriceCurrency($hourly);
            $monthlyCurrency = $this->extractPriceCurrency($monthly);
            if ($hourlyCurrency !== '') {
                $currency = $hourlyCurrency;
            } elseif ($monthlyCurrency !== '') {
                $currency = $monthlyCurrency;
            }

            $hourlyGross = $this->extractPriceAmount($hourly);
            $monthlyGross = $this->extractPriceAmount($monthly);

            if ($hourlyGross !== null && ($fromHourly === null || $hourlyGross < $fromHourly)) {
                $fromHourly = $hourlyGross;
            }
            if ($monthlyGross !== null && ($fromMonthly === null || $monthlyGross < $fromMonthly)) {
                $fromMonthly = $monthlyGross;
            }

            $location = $this->extractLocationName($row['location'] ?? '');
            if ($location === '') {
                continue;
            }

            if (!isset($byLocation[$location])) {
                $byLocation[$location] = [
                    'location' => $location,
                    'hourly_gross' => $hourlyGross,
                    'monthly_gross' => $monthlyGross,
                    'currency' => $currency,
                ];
                continue;
            }

            $existing = $byLocation[$location];
            $existingHourly = isset($existing['hourly_gross']) && is_numeric($existing['hourly_gross']) ? (float) $existing['hourly_gross'] : null;
            $existingMonthly = isset($existing['monthly_gross']) && is_numeric($existing['monthly_gross']) ? (float) $existing['monthly_gross'] : null;

            if ($hourlyGross !== null && ($existingHourly === null || $hourlyGross < $existingHourly)) {
                $existing['hourly_gross'] = $hourlyGross;
            }
            if ($monthlyGross !== null && ($existingMonthly === null || $monthlyGross < $existingMonthly)) {
                $existing['monthly_gross'] = $monthlyGross;
            }
            $existing['currency'] = $currency;
            $byLocation[$location] = $existing;
        }

        ksort($byLocation);

        return [
            'currency' => $currency,
            'from_hourly_gross' => $fromHourly,
            'from_monthly_gross' => $fromMonthly,
            'location_prices' => array_values($byLocation),
        ];
    }

    private function mergeOptionPricing(array $base, array $incoming): array
    {
        $base = $this->normalizeOptionPricing($base);
        $incoming = $this->normalizeOptionPricing($incoming);

        $currency = trim((string) ($base['currency'] ?? ''));
        if ($currency === '') {
            $currency = trim((string) ($incoming['currency'] ?? ''));
        }
        if ($currency === '') {
            $currency = 'EUR';
        }

        $backupBase = isset($base['backup_percentage']) && is_numeric($base['backup_percentage']) ? (float) $base['backup_percentage'] : null;
        $backupIncoming = isset($incoming['backup_percentage']) && is_numeric($incoming['backup_percentage']) ? (float) $incoming['backup_percentage'] : null;
        if ($backupBase === null) {
            $backup = $backupIncoming;
        } elseif ($backupIncoming === null) {
            $backup = $backupBase;
        } else {
            $backup = min($backupBase, $backupIncoming);
        }

        return [
            'currency' => $currency,
            'backup_percentage' => $backup,
            'ipv4' => $this->mergePrimaryIpPricing($base['ipv4'] ?? [], $incoming['ipv4'] ?? [], $currency),
            'ipv6' => $this->mergePrimaryIpPricing($base['ipv6'] ?? [], $incoming['ipv6'] ?? [], $currency),
        ];
    }

    private function mergePrimaryIpPricing(array $base, array $incoming, string $fallbackCurrency): array
    {
        $baseCurrency = trim((string) ($base['currency'] ?? ''));
        $incomingCurrency = trim((string) ($incoming['currency'] ?? ''));
        if ($incomingCurrency === '') {
            $incomingCurrency = $this->extractPriceCurrency($incoming['price_hourly'] ?? null);
        }
        if ($incomingCurrency === '') {
            $incomingCurrency = $this->extractPriceCurrency($incoming['price_monthly'] ?? null);
        }
        $currency = $baseCurrency !== '' ? $baseCurrency : ($incomingCurrency !== '' ? $incomingCurrency : $fallbackCurrency);

        $baseFromHourly = isset($base['from_hourly_gross']) && is_numeric($base['from_hourly_gross'])
            ? (float) $base['from_hourly_gross']
            : $this->extractPriceAmount($base['price_hourly'] ?? null);
        $incomingFromHourly = isset($incoming['from_hourly_gross']) && is_numeric($incoming['from_hourly_gross'])
            ? (float) $incoming['from_hourly_gross']
            : $this->extractPriceAmount($incoming['price_hourly'] ?? null);
        $fromHourly = $baseFromHourly;
        if ($fromHourly === null || ($incomingFromHourly !== null && $incomingFromHourly < $fromHourly)) {
            $fromHourly = $incomingFromHourly;
        }

        $baseFromMonthly = isset($base['from_monthly_gross']) && is_numeric($base['from_monthly_gross'])
            ? (float) $base['from_monthly_gross']
            : $this->extractPriceAmount($base['price_monthly'] ?? null);
        $incomingFromMonthly = isset($incoming['from_monthly_gross']) && is_numeric($incoming['from_monthly_gross'])
            ? (float) $incoming['from_monthly_gross']
            : $this->extractPriceAmount($incoming['price_monthly'] ?? null);
        $fromMonthly = $baseFromMonthly;
        if ($fromMonthly === null || ($incomingFromMonthly !== null && $incomingFromMonthly < $fromMonthly)) {
            $fromMonthly = $incomingFromMonthly;
        }

        $mergedByLocation = [];
        foreach ((array) ($base['location_prices'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $location = $this->extractLocationName($row['location'] ?? '');
            if ($location === '') {
                continue;
            }
            $mergedByLocation[$location] = [
                'location' => $location,
                'hourly_gross' => isset($row['hourly_gross']) && is_numeric($row['hourly_gross'])
                    ? (float) $row['hourly_gross']
                    : $this->extractPriceAmount($row['price_hourly'] ?? null),
                'monthly_gross' => isset($row['monthly_gross']) && is_numeric($row['monthly_gross'])
                    ? (float) $row['monthly_gross']
                    : $this->extractPriceAmount($row['price_monthly'] ?? null),
                'currency' => trim((string) ($row['currency'] ?? '')) ?: $currency,
            ];
        }
        foreach ((array) ($incoming['location_prices'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $location = $this->extractLocationName($row['location'] ?? '');
            if ($location === '') {
                continue;
            }
            $hourly = isset($row['hourly_gross']) && is_numeric($row['hourly_gross'])
                ? (float) $row['hourly_gross']
                : $this->extractPriceAmount($row['price_hourly'] ?? null);
            $monthly = isset($row['monthly_gross']) && is_numeric($row['monthly_gross'])
                ? (float) $row['monthly_gross']
                : $this->extractPriceAmount($row['price_monthly'] ?? null);

            if (!isset($mergedByLocation[$location])) {
                $mergedByLocation[$location] = [
                    'location' => $location,
                    'hourly_gross' => $hourly,
                    'monthly_gross' => $monthly,
                    'currency' => trim((string) ($row['currency'] ?? '')) ?: $currency,
                ];
                continue;
            }

            $existing = $mergedByLocation[$location];
            $existingHourly = isset($existing['hourly_gross']) && is_numeric($existing['hourly_gross']) ? (float) $existing['hourly_gross'] : null;
            $existingMonthly = isset($existing['monthly_gross']) && is_numeric($existing['monthly_gross']) ? (float) $existing['monthly_gross'] : null;

            if ($hourly !== null && ($existingHourly === null || $hourly < $existingHourly)) {
                $existing['hourly_gross'] = $hourly;
            }
            if ($monthly !== null && ($existingMonthly === null || $monthly < $existingMonthly)) {
                $existing['monthly_gross'] = $monthly;
            }
            $incomingCurrencyRow = trim((string) ($row['currency'] ?? ''));
            if ($incomingCurrencyRow !== '') {
                $existing['currency'] = $incomingCurrencyRow;
            }
            $mergedByLocation[$location] = $existing;
        }

        ksort($mergedByLocation);

        return [
            'currency' => $currency,
            'from_hourly_gross' => $fromHourly,
            'from_monthly_gross' => $fromMonthly,
            'location_prices' => array_values($mergedByLocation),
        ];
    }

    private function normalizeServerTypePricing($prices): array
    {
        if (!is_array($prices)) {
            return [
                'currency' => 'EUR',
                'from_hourly_gross' => null,
                'from_monthly_gross' => null,
                'location_prices' => [],
            ];
        }

        $currency = 'EUR';
        $fromHourlyGross = null;
        $fromMonthlyGross = null;
        $locationPrices = [];

        foreach ($prices as $row) {
            if (!is_array($row)) {
                continue;
            }

            $hourly = $row['price_hourly'] ?? null;
            $monthly = $row['price_monthly'] ?? null;
            $hourlyCurrency = $this->extractPriceCurrency($hourly);
            $monthlyCurrency = $this->extractPriceCurrency($monthly);
            if ($hourlyCurrency !== '') {
                $currency = $hourlyCurrency;
            } elseif ($monthlyCurrency !== '') {
                $currency = $monthlyCurrency;
            }

            $hourlyGross = $this->extractPriceAmount($hourly);
            $monthlyGross = $this->extractPriceAmount($monthly);

            if ($hourlyGross !== null && ($fromHourlyGross === null || $hourlyGross < $fromHourlyGross)) {
                $fromHourlyGross = $hourlyGross;
            }
            if ($monthlyGross !== null && ($fromMonthlyGross === null || $monthlyGross < $fromMonthlyGross)) {
                $fromMonthlyGross = $monthlyGross;
            }

            $locationPrices[] = [
                'location' => $this->extractLocationName($row['location'] ?? ''),
                'hourly_net' => is_array($hourly) && isset($hourly['net']) && is_numeric($hourly['net']) ? (float) $hourly['net'] : null,
                'hourly_gross' => $hourlyGross,
                'monthly_net' => is_array($monthly) && isset($monthly['net']) && is_numeric($monthly['net']) ? (float) $monthly['net'] : null,
                'monthly_gross' => $monthlyGross,
                'currency' => $currency,
            ];
        }

        return [
            'currency' => $currency,
            'from_hourly_gross' => $fromHourlyGross,
            'from_monthly_gross' => $fromMonthlyGross,
            'location_prices' => $locationPrices,
        ];
    }

    private function mergeServerTypePricing(array $base, array $incoming): array
    {
        $baseCurrency = trim((string) ($base['currency'] ?? ''));
        $incomingCurrency = trim((string) ($incoming['currency'] ?? ''));
        $currency = $baseCurrency !== '' ? $baseCurrency : ($incomingCurrency !== '' ? $incomingCurrency : 'EUR');

        $baseFromHourly = isset($base['from_hourly_gross']) && is_numeric($base['from_hourly_gross']) ? (float) $base['from_hourly_gross'] : null;
        $incomingFromHourly = isset($incoming['from_hourly_gross']) && is_numeric($incoming['from_hourly_gross']) ? (float) $incoming['from_hourly_gross'] : null;
        $fromHourly = $baseFromHourly;
        if ($fromHourly === null || ($incomingFromHourly !== null && $incomingFromHourly < $fromHourly)) {
            $fromHourly = $incomingFromHourly;
        }

        $baseFromMonthly = isset($base['from_monthly_gross']) && is_numeric($base['from_monthly_gross']) ? (float) $base['from_monthly_gross'] : null;
        $incomingFromMonthly = isset($incoming['from_monthly_gross']) && is_numeric($incoming['from_monthly_gross']) ? (float) $incoming['from_monthly_gross'] : null;
        $fromMonthly = $baseFromMonthly;
        if ($fromMonthly === null || ($incomingFromMonthly !== null && $incomingFromMonthly < $fromMonthly)) {
            $fromMonthly = $incomingFromMonthly;
        }

        $mergedByLocation = [];
        foreach ([(array) ($base['location_prices'] ?? []), (array) ($incoming['location_prices'] ?? [])] as $rows) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $location = $this->extractLocationName($row['location'] ?? '');
                if ($location === '') {
                    continue;
                }

                $hourlyGross = isset($row['hourly_gross']) && is_numeric($row['hourly_gross'])
                    ? (float) $row['hourly_gross']
                    : $this->extractPriceAmount($row['price_hourly'] ?? null);
                $monthlyGross = isset($row['monthly_gross']) && is_numeric($row['monthly_gross'])
                    ? (float) $row['monthly_gross']
                    : $this->extractPriceAmount($row['price_monthly'] ?? null);
                $hourlyNet = isset($row['hourly_net']) && is_numeric($row['hourly_net']) ? (float) $row['hourly_net'] : null;
                $monthlyNet = isset($row['monthly_net']) && is_numeric($row['monthly_net']) ? (float) $row['monthly_net'] : null;
                $rowCurrency = trim((string) ($row['currency'] ?? '')) ?: $currency;

                if (!isset($mergedByLocation[$location])) {
                    $mergedByLocation[$location] = [
                        'location' => $location,
                        'hourly_net' => $hourlyNet,
                        'hourly_gross' => $hourlyGross,
                        'monthly_net' => $monthlyNet,
                        'monthly_gross' => $monthlyGross,
                        'currency' => $rowCurrency,
                    ];
                } else {
                    $existing = $mergedByLocation[$location];
                    $existingHourly = isset($existing['hourly_gross']) && is_numeric($existing['hourly_gross']) ? (float) $existing['hourly_gross'] : null;
                    $existingMonthly = isset($existing['monthly_gross']) && is_numeric($existing['monthly_gross']) ? (float) $existing['monthly_gross'] : null;
                    $existingHourlyNet = isset($existing['hourly_net']) && is_numeric($existing['hourly_net']) ? (float) $existing['hourly_net'] : null;
                    $existingMonthlyNet = isset($existing['monthly_net']) && is_numeric($existing['monthly_net']) ? (float) $existing['monthly_net'] : null;

                    if ($hourlyGross !== null && ($existingHourly === null || $hourlyGross < $existingHourly)) {
                        $existing['hourly_gross'] = $hourlyGross;
                    }
                    if ($monthlyGross !== null && ($existingMonthly === null || $monthlyGross < $existingMonthly)) {
                        $existing['monthly_gross'] = $monthlyGross;
                    }
                    if ($hourlyNet !== null && ($existingHourlyNet === null || $hourlyNet < $existingHourlyNet)) {
                        $existing['hourly_net'] = $hourlyNet;
                    }
                    if ($monthlyNet !== null && ($existingMonthlyNet === null || $monthlyNet < $existingMonthlyNet)) {
                        $existing['monthly_net'] = $monthlyNet;
                    }
                    if ($rowCurrency !== '') {
                        $existing['currency'] = $rowCurrency;
                    }

                    $mergedByLocation[$location] = $existing;
                }
            }
        }

        foreach ($mergedByLocation as $row) {
            $hourly = isset($row['hourly_gross']) && is_numeric($row['hourly_gross']) ? (float) $row['hourly_gross'] : null;
            $monthly = isset($row['monthly_gross']) && is_numeric($row['monthly_gross']) ? (float) $row['monthly_gross'] : null;
            if ($hourly !== null && ($fromHourly === null || $hourly < $fromHourly)) {
                $fromHourly = $hourly;
            }
            if ($monthly !== null && ($fromMonthly === null || $monthly < $fromMonthly)) {
                $fromMonthly = $monthly;
            }
        }

        ksort($mergedByLocation);

        return [
            'currency' => $currency,
            'from_hourly_gross' => $fromHourly,
            'from_monthly_gross' => $fromMonthly,
            'location_prices' => array_values($mergedByLocation),
        ];
    }

    private function extractServerTypeAvailableLocations(array $type, array $pricing): array
    {
        $locations = [];

        foreach ((array) ($pricing['location_prices'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = $this->extractLocationName($entry['location'] ?? '');
            if ($name !== '') {
                $locations[$name] = $name;
            }
        }

        foreach ((array) ($type['locations'] ?? []) as $row) {
            if (is_scalar($row)) {
                $name = trim((string) $row);
                if ($name !== '') {
                    $locations[$name] = $name;
                }
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $name = $this->extractLocationName($row['name'] ?? ($row['location'] ?? ''));
            if ($name === '') {
                continue;
            }

            $deprecation = is_array($row['deprecation'] ?? null) ? $row['deprecation'] : [];
            $unavailableAfter = trim((string) ($deprecation['unavailable_after'] ?? ''));
            if ($unavailableAfter !== '') {
                $ts = strtotime($unavailableAfter);
                if ($ts !== false && $ts <= time()) {
                    continue;
                }
            }

            $locations[$name] = $name;
        }

        ksort($locations);

        return array_values($locations);
    }

    private function decodeJson(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function parseCsvOrArray($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(static function ($item) {
                if (is_scalar($item)) {
                    return trim((string) $item);
                }

                return '';
            }, $value), static function (string $item): bool {
                return $item !== '';
            }));
        }

        if (!is_string($value)) {
            return [];
        }

        $parts = array_map(static function (string $chunk): string {
            return trim($chunk);
        }, explode(',', $value));

        return array_values(array_filter($parts, static function (string $chunk): bool {
            return $chunk !== '';
        }));
    }

    private function normalizeLabels($value): array
    {
        if (is_array($value)) {
            $labels = [];
            foreach ($value as $k => $v) {
                if (!is_scalar($k) || !is_scalar($v)) {
                    continue;
                }

                $key = trim((string) $k);
                $val = trim((string) $v);
                if ($key === '' || $val === '') {
                    continue;
                }
                $labels[$key] = $val;
            }

            return $labels;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $this->normalizeLabels($decoded);
        }

        return [];
    }

    private function parseBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $str = strtolower(trim((string) $value));

        return in_array($str, ['1', 'true', 'yes', 'on'], true);
    }
}
