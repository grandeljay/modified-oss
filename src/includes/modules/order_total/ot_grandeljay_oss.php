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
    private array $autoKeys = array();

    public array $output = array();

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
     * @return void
     */
    public function process($file = null): void
    {
        global $order;

        $customer_vat_id     = $_SESSION['customer_vat_id'];
        $customer_has_vat_id = !empty($customer_vat_id);

        /**
         * Standard OSS, use VAT of target EU country.
         */
        if (!$customer_has_vat_id) {
            return;
        }

        $tax_is_included = (bool) $_SESSION['customers_status']['customers_status_show_price_tax'];
        $tax_is_excluded = !$tax_is_included;
        $addTax          = (bool) $_SESSION['customers_status']['customers_status_add_tax_ot'];

        $customer_is_national      = STORE_COUNTRY === $order->delivery['country']['id'];
        $customer_is_international = !$customer_is_national;
        $shipment_is_national      = STORE_COUNTRY === $order->customer['country']['id'];
        $shipment_is_international = !$shipment_is_national;

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

        if (false === $apply_national_tax) {
            return;
        }

        /**
         * Otherwise modified will ignore the parameters in
         * `xtc_get_tax_description`
         */
        unset($_SESSION['country']);

        foreach ($order->products as &$product) {
            $tax_class_id    = $product['tax_class_id'];
            $tax_description = xtc_get_tax_description($tax_class_id, $order->delivery['id'], $order->delivery['zone_id']);
            $tax_info        = TAX_NO_TAX . $tax_description;
            $tax_rate        = xtc_get_tax_rate($tax_class_id, $order->delivery['id'], $order->delivery['zone_id']);
            $tax_amount      = $product['price'] * ($tax_rate / 100);

            $product['tax']             = $tax_rate;
            $product['tax_info']        = $tax_info;
            $product['tax_description'] = $tax_description;

            $order->info['tax']                  += $tax_amount;
            $order->info['tax_groups'][$tax_info] = $order->info['tax'];
        }
    }
}
