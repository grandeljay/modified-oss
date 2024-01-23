<?php

/**
 * OSS
 *
 * @author  Jay Trees <modified-oss@grandels.email>
 * @link    https://github.com/grandeljay/modified-oss
 * @package GrandeljayOss
 *
 * @phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
 * @phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
 */

use Grandeljay\Oss\Constants;
use RobinTheHood\ModifiedStdModule\Classes\StdModule;

class ot_grandeljay_oss extends StdModule
{
    public const VERSION = Constants::MODULE_VERSION;

    /**
     * Keys to automatically add on __construct and to remove on remove.
     *
     * @var array
     */
    private array $autoKeys = [];

    public array $output = [];

    public function __construct()
    {
        parent::__construct(Constants::MODULE_NAME);

        $this->checkForUpdate(true);

        $this->autoKeys[] = 'SORT_ORDER';

        foreach ($this->autoKeys as $key) {
            $this->addKey($key);
        }
    }

    public function install()
    {
        parent::install();

        $this->addConfiguration('SORT_ORDER', MODULE_ORDER_TOTAL_TAX_SORT_ORDER - 2, 6, 1);
    }

    protected function updateSteps(): int
    {
        if (version_compare($this->getVersion(), self::VERSION, '<')) {
            $this->setVersion(self::VERSION);

            return self::UPDATE_SUCCESS;
        }

        return self::UPDATE_NOTHING;
    }

    public function remove()
    {
        parent::remove();

        foreach ($this->autoKeys as $key) {
            $this->removeConfiguration($key);
        }
    }

    /**
     * VAT Calculation
     *
     * | Has VAT ID | VAT Free Group | Billing       | Shipping | VAT      |
     * | ---------- | -------------- | ------------- | -------- | -------- |
     * | No         | -              | -             | -        | Shipping |
     * | Yes        | Yes            | International | National | Shipping |
     * | Yes        | No             | National      | -        | Billing  |
     *
     * Always use national VAT when Billing is also national.
     *
     * @return void
     */
    public function process($file = null): void
    {
        global $order;

        $customer_vat_id           = $_SESSION['customer_vat_id'];
        $customer_has_vat_id       = !empty($customer_vat_id);
        $customer_is_national      = STORE_COUNTRY === $order->billing['country_id'];
        $customer_is_international = !$customer_is_national;

        $shipment_is_national      = STORE_COUNTRY === $order->delivery['country_id'];
        $shipment_is_international = !$shipment_is_national;

        /**
         * Standard OSS, use VAT of target EU country, unless it's national
         * billing.
         */
        if (!$customer_has_vat_id && !$customer_is_national) {
            return;
        }

        $tax_is_included = (bool) $_SESSION['customers_status']['customers_status_show_price_tax'];
        $tax_is_excluded = !$tax_is_included;
        $addTax          = (bool) $_SESSION['customers_status']['customers_status_add_tax_ot'];

        $apply_national_tax = false;

        /**
         * Case 1: Prices without VAT, international billing address, national
         * shipping.
         */
        if ($tax_is_excluded && $addTax) {
            if ($customer_is_international && $shipment_is_national) {
                $apply_national_tax = true;
            }
        }

        /**
         * Case 2: Prices with VAT, national billing address, international
         * shipping.
         */
        if ($tax_is_included && $addTax) {
            if ($customer_is_national) {
                $apply_national_tax = true;
            }
        }
        /** */

        /**
         * Case Exception: Customer has no VAT and has national billing.
         */
        if (!$customer_has_vat_id && $customer_is_national) {
            $apply_national_tax = true;
        }

        /**
         * Set tax country
         */
        $tax_country = $order->delivery['country_id'];
        $tax_zone    = $order->delivery['zone_id'];

        if ($apply_national_tax) {
            $national_zone_query = xtc_db_query(
                sprintf(
                    'SELECT *
                       FROM `%s`
                      WHERE `zone_country_id` = %s',
                    TABLE_ZONES,
                    STORE_COUNTRY
                )
            );
            $national_zone_data  = xtc_db_fetch_array($national_zone_query);

            $tax_country = $national_zone_data['zone_country_id'];
            $tax_zone    = $national_zone_data['zone_id'];
        }

        /**
         * Otherwise modified will ignore the parameters in
         * `xtc_get_tax_description`
         */
        unset($_SESSION['country']);

        /**
         * Reset tax to avoid multiples.
         */
        $order->info['tax'] = 0;
        unset($order->info['tax_groups']);

        /** Add tax for each product */
        foreach ($order->products as &$product) {
            $tax_class_id    = $product['tax_class_id'];
            $tax_description = xtc_get_tax_description($tax_class_id, $tax_country, $tax_zone);
            $tax_rate        = xtc_get_tax_rate($tax_class_id, $tax_country, $tax_zone);
            $tax_info        = TAX_NO_TAX . $tax_description;
            $tax_amount      = $product['price'] * ($tax_rate / 100);

            $product['tax']             = $tax_rate;
            $product['tax_info']        = $tax_info;
            $product['tax_description'] = $tax_description;

            $order->info['tax']                  += $tax_amount;
            $order->info['tax_groups'][$tax_info] = $order->info['tax'];
        }

        /** Add tax for shipping method */
        $shipping_methods = explode('_', $order->info['shipping_class']);
        $shipping_class   = $shipping_methods[0] ?? 'Unknown';
        $shipping_object  = class_exists($shipping_class) ? new $shipping_class() : null;

        if (isset($shipping_object, $shipping_object->tax_class)) {
            $tax_delivery_rate        = xtc_get_tax_rate($shipping_object->tax_class, $tax_country, $tax_zone);
            $tax_delivery_description = xtc_get_tax_description($shipping_object->tax_class, $tax_country, $tax_zone);
            $tax_delivery_info        = TAX_NO_TAX . $tax_delivery_description;
            $tax_delivery_amount      = $order->info['shipping_cost'] * ($tax_delivery_rate / 100);

            $order->info['tax']                           += $tax_delivery_amount;
            $order->info['tax_groups'][$tax_delivery_info] = $order->info['tax'];
        }
    }
}
