<?php
/**
 * Plugin Name: Bank of Georgia Payment Gateway
 * Plugin URI: https://fb.me/komisia
 * Description: Accept payments through Bank of Georgia payment system in WooCommerce
 * Version: 1.0.0
 * Author: Giorgi Berikelashvili
 * Author URI: https://fb.me/komisia
 * Text Domain: bog-payment-gateway
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 4.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BOG_PAYMENT_GATEWAY_VERSION', '1.0.0');
define('BOG_PAYMENT_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BOG_PAYMENT_GATEWAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BOG_PAYMENT_GATEWAY_PLUGIN_BASENAME', plugin_basename(__FILE__));

class BOG_Payment_Gateway_Init {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'), 11);
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'), 10);
        add_action('init', array($this, 'register_callback_endpoint'));
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        
        // Register blocks support early
        add_action('woocommerce_blocks_loaded', array($this, 'woocommerce_blocks_support'));
        
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        $this->load_textdomain();
        $this->includes();
        
        // Initialize the gateway class early
        add_action('woocommerce_init', array($this, 'init_gateway_class'));
        
        add_action('woocommerce_api_bog_callback', array($this, 'handle_callback'));
        add_action('woocommerce_api_bog_success', array($this, 'handle_success_redirect'));
        add_action('woocommerce_api_bog_fail', array($this, 'handle_fail_redirect'));
    }
    
    public function init_gateway_class() {
        // Ensure the gateway class is available
        if (!class_exists('BOG_Payment_Gateway')) {
            require_once BOG_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-bog-payment-gateway.php';
        }
    }
    
    private function includes() {
        require_once BOG_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-bog-api-client.php';
        require_once BOG_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-bog-payment-gateway.php';
        require_once BOG_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-bog-callback-handler.php';
    }
    
    public function add_gateway($gateways) {
        if (class_exists('BOG_Payment_Gateway')) {
            $gateways[] = 'BOG_Payment_Gateway';
        }
        return $gateways;
    }
    
    public function register_callback_endpoint() {
        add_rewrite_endpoint('bog-callback', EP_ROOT);
    }
    
    public function handle_callback() {
        $handler = new BOG_Callback_Handler();
        $handler->process_callback();
    }
    
    public function handle_success_redirect() {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $bog_order_id = isset($_GET['bog_order_id']) ? sanitize_text_field($_GET['bog_order_id']) : '';
        
        if (!$order_id || !$bog_order_id) {
            wp_redirect(wc_get_checkout_url());
            exit;
        }
        
        $order = wc_get_order($order_id);
        $stored_bog_order_id = $order ? $order->get_meta('_bog_order_id', true) : '';
        if (!$order || $stored_bog_order_id !== $bog_order_id) {
            wp_redirect(wc_get_checkout_url());
            exit;
        }
        
        $gateway = new BOG_Payment_Gateway();
        $payment_status = $gateway->check_payment_status($bog_order_id);
        
        if ($payment_status && $payment_status['status'] === 'completed') {
            $order->payment_complete($bog_order_id);
            $order->add_order_note(__('Payment completed via Bank of Georgia', 'bog-payment-gateway'));
            
            WC()->cart->empty_cart();
            
            wp_redirect($order->get_checkout_order_received_url());
        } else {
            wc_add_notice(__('Payment verification failed. Please contact support.', 'bog-payment-gateway'), 'error');
            wp_redirect(wc_get_checkout_url());
        }
        exit;
    }
    
    public function handle_fail_redirect() {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_status('failed', __('Payment failed at Bank of Georgia', 'bog-payment-gateway'));
            }
        }
        
        wc_add_notice(__('Payment was not completed. Please try again.', 'bog-payment-gateway'), 'error');
        wp_redirect(wc_get_checkout_url());
        exit;
    }
    
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
    
    public function load_textdomain() {
        load_plugin_textdomain(
            'bog-payment-gateway',
            false,
            dirname(BOG_PAYMENT_GATEWAY_PLUGIN_BASENAME) . '/languages'
        );
    }
    
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('Bank of Georgia Payment Gateway requires WooCommerce to be installed and active.', 'bog-payment-gateway'); ?></p>
        </div>
        <?php
    }
    
    public function activate() {
        flush_rewrite_rules();
        
        add_option('bog_payment_gateway_version', BOG_PAYMENT_GATEWAY_VERSION);
        
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $table_name = $wpdb->prefix . 'bog_payment_logs';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            bog_order_id varchar(255) NOT NULL,
            status varchar(50) NOT NULL,
            message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY bog_order_id (bog_order_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    /**
     * Add support for WooCommerce Blocks
     */
    public function woocommerce_blocks_support() {
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            require_once BOG_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-bog-blocks-integration.php';
            
            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function($payment_method_registry) {
                    $payment_method_registry->register(new BOG_Blocks_Integration());
                },
                5
            );
        }
    }
}

BOG_Payment_Gateway_Init::get_instance();