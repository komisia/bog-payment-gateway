<?php
/**
 * BOG Payment Gateway Blocks Integration
 * 
 * Integrates the payment gateway with WooCommerce Blocks checkout
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BOG Blocks Integration class
 */
final class BOG_Blocks_Integration extends AbstractPaymentMethodType {
    
    /**
     * Payment method name/slug
     *
     * @var string
     */
    protected $name = 'bog_payment';
    
    /**
     * The gateway instance
     */
    private $gateway;
    
    /**
     * Initializes the payment method type
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_bog_payment_settings', array());
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = isset($gateways[$this->name]) ? $gateways[$this->name] : false;
    }
    
    /**
     * Returns if this payment method should be active
     */
    public function is_active() {
        return $this->gateway && $this->gateway->is_available();
    }
    
    /**
     * Returns an array of scripts/handles to be registered for this payment method
     */
    public function get_payment_method_script_handles() {
        $script_path = 'assets/js/blocks/bog-payment-block.js';
        $script_asset_path = BOG_PAYMENT_GATEWAY_PLUGIN_DIR . 'assets/js/blocks/bog-payment-block.asset.php';
        $script_asset = file_exists($script_asset_path)
            ? require($script_asset_path)
            : array(
                'dependencies' => array(),
                'version' => BOG_PAYMENT_GATEWAY_VERSION
            );
        $script_url = BOG_PAYMENT_GATEWAY_PLUGIN_URL . $script_path;
        
        // Register the main block script
        wp_register_script(
            'bog-payment-block',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );
        
        // Set script translations if available
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                'bog-payment-block',
                'bog-payment-gateway',
                BOG_PAYMENT_GATEWAY_PLUGIN_DIR . 'languages/'
            );
        }
        
        return array('bog-payment-block');
    }
    
    /**
     * Returns an array of data to be exposed to the block on the client side
     */
    public function get_payment_method_data() {
        if (!$this->gateway) {
            return array();
        }
        
        return array(
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
            'supports' => array_filter($this->gateway->supports, array($this->gateway, 'supports')),
            'icon' => $this->gateway->icon,
            'test_mode' => $this->gateway->get_option('test_mode') === 'yes',
        );
    }
}