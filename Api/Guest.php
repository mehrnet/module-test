<?php

/**
 * Hetzner Cloud module Guest API.
 */

namespace Box\Mod\Servicehetzner\Api;

class Guest extends \Api_Abstract
{
    public function catalog_get($data): array
    {
        $refresh = (string) ($data['refresh'] ?? '0') === '1';
        $projectRef = trim((string) ($data['project_ref'] ?? ''));

        try {
            $service = $this->resolveService();
            $catalog = $projectRef !== ''
                ? $service->getProjectCatalog($projectRef, $refresh)
                : $service->getGlobalCatalog($refresh);

            return [
                'ok' => true,
                'catalog' => $catalog,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Failed to load Hetzner catalog: ' . $e->getMessage(),
                'catalog' => [
                    'server_types' => [],
                    'locations' => [],
                    'images' => [],
                    'firewalls' => [],
                    'projects_loaded' => [],
                    'errors' => [],
                    'synced_at' => '',
                ],
            ];
        }
    }

    private function resolveService(): \Box\Mod\Servicehetzner\Service
    {
        $service = parent::getService();
        if ($service instanceof \Box\Mod\Servicehetzner\Service) {
            return $service;
        }

        $modServiceFactory = $this->di['mod_service'] ?? null;
        if (is_callable($modServiceFactory)) {
            $candidate = $modServiceFactory('servicehetzner');
            if ($candidate instanceof \Box\Mod\Servicehetzner\Service) {
                return $candidate;
            }
        }

        throw new \FOSSBilling\InformationException('Unable to resolve Servicehetzner service instance at runtime.', [], 500);
    }
}
