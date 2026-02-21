<?php

/**
 * Hetzner Cloud module Admin API.
 */

namespace Box\Mod\Servicehetzner\Api;

class Admin extends \Api_Abstract
{
    public function ping($data): array
    {
        return [
            'ok' => true,
            'module' => 'servicehetzner',
            'api' => 'admin',
            'version' => '0.4.0',
        ];
    }

    public function config_get($data): array
    {
        $service = $this->resolveService();
        $config = $service->getModuleConfig();

        return [
            'default_project_ref' => $config['default_project_ref'],
            'delete_on_cancel' => $config['delete_on_cancel'],
            'api_url' => $config['api_url'],
            'api_token_set' => trim((string) $config['api_token']) !== '',
            'verify_ssl' => $config['verify_ssl'],
            'timeout' => $config['timeout'],
            'max_servers' => $config['max_servers'],
            'projects' => $service->getProjectsForApi(true),
        ];
    }

    public function config_update($data): array
    {
        try {
            $service = $this->resolveService();
            $service->updateModuleConfig($data);

            return [
                'ok' => true,
                'message' => 'Settings saved successfully.',
                'config' => $this->config_get([]),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Failed to save Hetzner settings: ' . $e->getMessage(),
            ];
        }
    }

    public function connection_test($data): array
    {
        try {
            $service = $this->resolveService();

            return $service->testConnection($data);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Hetzner connection test failed: ' . $e->getMessage(),
                'http_code' => 500,
            ];
        }
    }

    public function project_get_list($data): array
    {
        $service = $this->resolveService();

        return [
            'list' => $service->getProjectsForApi(true),
        ];
    }

    public function project_upsert($data): array
    {
        try {
            $service = $this->resolveService();
            $project = $service->upsertProject($data);

            return [
                'ok' => true,
                'message' => 'Project saved successfully.',
                'project' => $project,
                'list' => $service->getProjectsForApi(true),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Failed to save project: ' . $e->getMessage(),
            ];
        }
    }

    public function project_delete($data): array
    {
        try {
            $ref = (string) ($data['ref'] ?? $data['project_ref'] ?? '');
            if ($ref === '') {
                throw new \FOSSBilling\InformationException('Project ref is required.');
            }

            $service = $this->resolveService();
            $service->deleteProject($ref);

            return [
                'ok' => true,
                'message' => 'Project deleted.',
                'list' => $service->getProjectsForApi(true),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Failed to delete project: ' . $e->getMessage(),
            ];
        }
    }

    public function project_sync($data): array
    {
        try {
            $ref = (string) ($data['ref'] ?? $data['project_ref'] ?? '');
            if ($ref === '') {
                throw new \FOSSBilling\InformationException('Project ref is required.');
            }

            $service = $this->resolveService();
            $inventory = $service->syncProjectInventory($ref);

            return [
                'ok' => true,
                'message' => 'Project inventory synced successfully.',
                'inventory' => $inventory,
                'list' => $service->getProjectsForApi(true),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Failed to sync project inventory: ' . $e->getMessage(),
            ];
        }
    }

    public function catalog_get($data): array
    {
        try {
            $ref = (string) ($data['ref'] ?? $data['project_ref'] ?? '');
            if ($ref === '') {
                throw new \FOSSBilling\InformationException('Project ref is required.');
            }

            $refresh = (string) ($data['refresh'] ?? '0') === '1';
            $service = $this->resolveService();

            return [
                'ok' => true,
                'catalog' => $service->getProjectCatalog($ref, $refresh),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Failed to load project catalog: ' . $e->getMessage(),
            ];
        }
    }

    public function catalog_global_get($data): array
    {
        try {
            $refresh = (string) ($data['refresh'] ?? '0') === '1';
            $service = $this->resolveService();

            return [
                'ok' => true,
                'catalog' => $service->getGlobalCatalog($refresh),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Failed to load global catalog: ' . $e->getMessage(),
            ];
        }
    }

    public function service_get_details($data): array
    {
        $order = $this->getOrderForAdmin($data);
        $service = $this->resolveService();

        return $service->getOrderServerDetails($order);
    }

    public function service_power_action($data): array
    {
        $order = $this->getOrderForAdmin($data);
        $action = (string) ($data['action'] ?? '');

        if ($action === '') {
            throw new \FOSSBilling\InformationException('Power action is required.');
        }

        $service = $this->resolveService();

        return $service->runOrderPowerAction($order, $action);
    }

    public function billing_hourly_run($data): array
    {
        $service = $this->resolveService();
        $result = $service->runHourlyBillingTick(true);

        return array_merge(['ok' => true], $result);
    }

    private function getOrderForAdmin(array $data): \Model_ClientOrder
    {
        $orderId = (int) ($data['order_id'] ?? 0);
        if ($orderId <= 0) {
            throw new \FOSSBilling\InformationException('order_id is required.');
        }

        $order = $this->di['db']->findOne('ClientOrder', 'id = :id', [':id' => $orderId]);
        if (!$order instanceof \Model_ClientOrder) {
            throw new \FOSSBilling\InformationException('Order not found.');
        }

        return $order;
    }

    private function resolveService(): \Box\Mod\Servicehetzner\Service
    {
        $service = parent::getService();
        if ($service instanceof \Box\Mod\Servicehetzner\Service) {
            return $service;
        }

        try {
            $modServiceFactory = $this->di['mod_service'] ?? null;
            if (is_callable($modServiceFactory)) {
                $candidate = $modServiceFactory('servicehetzner');
                if ($candidate instanceof \Box\Mod\Servicehetzner\Service) {
                    return $candidate;
                }
            }
        } catch (\Throwable $e) {
            // Fallback below.
        }

        try {
            $mod = $this->getMod();
            if ($mod && method_exists($mod, 'getService')) {
                $candidate = $mod->getService();
                if ($candidate instanceof \Box\Mod\Servicehetzner\Service) {
                    return $candidate;
                }
            }
        } catch (\Throwable $e) {
            // Ignored; throw unified exception below.
        }

        throw new \FOSSBilling\InformationException('Unable to resolve Servicehetzner service instance at runtime.', [], 500);
    }
}
