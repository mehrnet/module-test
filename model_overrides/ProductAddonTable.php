<?php

if (!class_exists('Model_ProductAddonTable', false)) {
    class Model_ProductAddonTable extends \Model_ProductTable
    {
        public function getProductPrice(\Model_Product $product, ?array $config = null)
        {
            if (is_array($config)) {
                $dynamic = isset($config['servicehetzner_dynamic_unit_price']) && is_numeric($config['servicehetzner_dynamic_unit_price'])
                    ? max(0, (float) $config['servicehetzner_dynamic_unit_price'])
                    : null;
                if ((string) ($config['servicehetzner_dynamic_pricing'] ?? '') === '1' && $dynamic !== null) {
                    return $dynamic;
                }
            }

            return parent::getProductPrice($product, $config);
        }

        public function getProductSetupPrice(\Model_Product $product, ?array $config = null)
        {
            if (is_array($config) && (string) ($config['servicehetzner_dynamic_pricing'] ?? '') === '1') {
                return 0.0;
            }

            return parent::getProductSetupPrice($product, $config);
        }
    }
}
