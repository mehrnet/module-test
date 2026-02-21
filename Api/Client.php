<?php

/**
 * Hetzner Cloud module Client API.
 */

namespace Box\Mod\Servicehetzner\Api;

class Client extends \Api_Abstract
{
    public function get_details($data): array
    {
        $order = $this->getClientOrder($data);

        return $this->resolveService()->getOrderServerDetails($order);
    }

    public function power_on($data): array
    {
        $order = $this->getClientOrder($data);

        return $this->resolveService()->runOrderPowerAction($order, 'poweron');
    }

    public function power_off($data): array
    {
        $order = $this->getClientOrder($data);

        return $this->resolveService()->runOrderPowerAction($order, 'poweroff');
    }

    public function reboot($data): array
    {
        $order = $this->getClientOrder($data);

        return $this->resolveService()->runOrderPowerAction($order, 'reboot');
    }

    public function shutdown($data): array
    {
        $order = $this->getClientOrder($data);

        return $this->resolveService()->runOrderPowerAction($order, 'shutdown');
    }

    public function topup_invoice_create($data): array
    {
        $order = $this->getClientOrder($data);
        $hours = (int) ($data['hours'] ?? 0);
        if ($hours <= 0) {
            throw new \FOSSBilling\InformationException('hours is required and must be greater than zero.');
        }

        return $this->resolveService()->createTopupInvoice($order, $hours);
    }

    private function getClientOrder(array $data): \Model_ClientOrder
    {
        $orderId = (int) ($data['order_id'] ?? 0);
        if ($orderId <= 0) {
            throw new \FOSSBilling\InformationException('order_id is required.');
        }

        $identity = $this->getIdentity();
        $order = $this->di['db']->findOne(
            'ClientOrder',
            'id = :id AND client_id = :client_id',
            [
                ':id' => $orderId,
                ':client_id' => $identity->id,
            ]
        );

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
