<?php

/**
 * Hetzner Cloud module admin controller.
 */

namespace Box\Mod\Servicehetzner\Controller;

class Admin
{
    protected $di;

    public function setDi($di)
    {
        $this->di = $di;
    }

    public function getDi()
    {
        return $this->di;
    }

    public function fetchNavigation(): array
    {
        return [
            'subpages' => [
                [
                    'location' => 'system',
                    'index' => 141,
                    'label' => __trans('Hetzner Cloud'),
                    'uri' => $this->di['url']->adminLink('servicehetzner'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/servicehetzner', 'get_index', null, static::class);
        $app->get('/servicehetzner/index', 'get_index', null, static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];

        $config = $this->loadConfig();

        return $app->render('mod_servicehetzner_index', ['config' => $config]);
    }

    private function loadConfig(): array
    {
        $defaults = [
            'default_project_ref' => 'default',
            'delete_on_cancel' => '0',
            'api_url' => 'https://api.hetzner.cloud/v1',
            'api_token_set' => false,
            'verify_ssl' => '1',
            'timeout' => '20',
            'max_servers' => '0',
            'projects' => [],
        ];

        try {
            $modServiceFactory = $this->di['mod_service'] ?? null;
            if (is_callable($modServiceFactory)) {
                $service = $modServiceFactory('servicehetzner');
                if ($service instanceof \Box\Mod\Servicehetzner\Service) {
                    $cfg = $service->getModuleConfig();

                    return [
                        'default_project_ref' => (string) ($cfg['default_project_ref'] ?? $defaults['default_project_ref']),
                        'delete_on_cancel' => (string) ($cfg['delete_on_cancel'] ?? $defaults['delete_on_cancel']),
                        'api_url' => (string) ($cfg['api_url'] ?? $defaults['api_url']),
                        'api_token_set' => trim((string) ($cfg['api_token'] ?? '')) !== '',
                        'verify_ssl' => (string) ($cfg['verify_ssl'] ?? $defaults['verify_ssl']),
                        'timeout' => (string) ($cfg['timeout'] ?? $defaults['timeout']),
                        'max_servers' => (string) ($cfg['max_servers'] ?? $defaults['max_servers']),
                        'projects' => $service->getProjectsForApi(true),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Fallback to direct DB lookup below.
        }

        try {
            $raw = $this->di['db']->getCell(
                "SELECT meta_value FROM extension_meta WHERE extension = :ext AND meta_key = 'config' LIMIT 1",
                ['ext' => 'mod_servicehetzner']
            );
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $projects = is_array($decoded['projects'] ?? null) ? $decoded['projects'] : [];
                    $defaultRef = (string) ($decoded['default_project_ref'] ?? $defaults['default_project_ref']);

                    return [
                        'default_project_ref' => $defaultRef,
                        'delete_on_cancel' => (string) ($decoded['delete_on_cancel'] ?? $defaults['delete_on_cancel']),
                        'api_url' => (string) ($decoded['api_url'] ?? $defaults['api_url']),
                        'api_token_set' => trim((string) ($decoded['api_token'] ?? '')) !== '',
                        'verify_ssl' => (string) ($decoded['verify_ssl'] ?? $defaults['verify_ssl']),
                        'timeout' => (string) ($decoded['timeout'] ?? $defaults['timeout']),
                        'max_servers' => (string) ($decoded['max_servers'] ?? $defaults['max_servers']),
                        'projects' => $projects,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Keep defaults.
        }

        return $defaults;
    }
}
