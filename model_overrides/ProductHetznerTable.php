<?php

if (!class_exists('Model_ProductHetznerTable', false)) {
    class Model_ProductHetznerTable extends \Model_ProductTable
    {
        public function getProductPrice(\Model_Product $product, ?array $config = null)
        {
            if (is_array($config)) {
                $mode = strtolower(trim((string) ($config['billing_mode'] ?? 'standard')));
                $dynamic = isset($config['servicehetzner_dynamic_unit_price']) && is_numeric($config['servicehetzner_dynamic_unit_price'])
                    ? max(0, (float) $config['servicehetzner_dynamic_unit_price'])
                    : null;
                if ($mode === 'prepaid_hours' && $dynamic !== null) {
                    return $dynamic;
                }
            }

            return parent::getProductPrice($product, $config);
        }

        public function getProductSetupPrice(\Model_Product $product, ?array $config = null)
        {
            if (is_array($config)) {
                $mode = strtolower(trim((string) ($config['billing_mode'] ?? 'standard')));
                if ($mode === 'prepaid_hours' && (string) ($config['servicehetzner_dynamic_pricing'] ?? '') === '1') {
                    return 0.0;
                }
            }

            return parent::getProductSetupPrice($product, $config);
        }
    }
}

if (!class_exists('Model_ProductServicehetznerTable', false) && class_exists('Model_ProductHetznerTable', false)) {
    class Model_ProductServicehetznerTable extends Model_ProductHetznerTable
    {
    }
}
