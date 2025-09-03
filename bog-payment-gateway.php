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
        
        // Add admin actions for manual status check
        add_action('woocommerce_order_actions', array($this, 'add_check_payment_action'));
        add_action('woocommerce_order_action_bog_check_payment', array($this, 'process_check_payment_action'));
        
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
        // Log redirect for debugging
        if (class_exists('WC_Logger')) {
            $logger = wc_get_logger();
            $logger->info('Success redirect received: ' . json_encode($_GET), array('source' => 'bog-payment-gateway'));
        }
        
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        
        if (!$order_id) {
            wc_add_notice(__('Invalid order information. Please contact support.', 'bog-payment-gateway'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found. Please contact support.', 'bog-payment-gateway'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }
        
        // Check if order is already paid
        if ($order->is_paid()) {
            WC()->cart->empty_cart();
            wp_redirect($order->get_checkout_order_received_url());
            exit;
        }
        
        // Get the stored BOG order ID
        $bog_order_id = $order->get_meta('_bog_order_id', true);
        
        if ($bog_order_id) {
            try {
                // Always check payment status via API on success redirect
                $gateway = new BOG_Payment_Gateway();
                $payment_status = $gateway->check_payment_status($bog_order_id);
                
                if ($payment_status) {
                    if (class_exists('WC_Logger')) {
                        $logger->info('Payment status check result: ' . json_encode($payment_status), array('source' => 'bog-payment-gateway'));
                    }
                    
                    if ($payment_status['status'] === 'completed') {
                        // Payment successful - mark order as completed
                        $order->payment_complete($bog_order_id);
                        $order->update_status('completed', __('Payment confirmed via Bank of Georgia', 'bog-payment-gateway'));
                        
                        // Add transaction details if available
                        if (isset($payment_status['details']['payment_detail'])) {
                            $payment_detail = $payment_status['details']['payment_detail'];
                            $transaction_id = isset($payment_detail['transaction_id']) ? $payment_detail['transaction_id'] : '';
                            if ($transaction_id) {
                                $order->set_transaction_id($transaction_id);
                                $order->save();
                            }
                        }
                        
                        WC()->cart->empty_cart();
                        wp_redirect($order->get_checkout_order_received_url());
                        exit;
                    } elseif ($payment_status['status'] === 'pending' || $payment_status['status'] === 'processing') {
                        // Payment still processing - wait for callback
                        $order->update_status('on-hold', __('Payment is being processed by Bank of Georgia', 'bog-payment-gateway'));
                        $order->add_order_note(__('Customer returned from payment page. Awaiting final confirmation.', 'bog-payment-gateway'));
                        
                        WC()->cart->empty_cart();
                        wp_redirect($order->get_checkout_order_received_url());
                        exit;
                    } else {
                        // Payment failed or unknown status
                        wc_add_notice(__('Payment was not successful. Please try again.', 'bog-payment-gateway'), 'error');
                        wp_redirect(wc_get_checkout_url());
                        exit;
                    }
                }
            } catch (Exception $e) {
                // Log error but don't fail the redirect
                if (class_exists('WC_Logger')) {
                    $logger = wc_get_logger();
                    $logger->error('Error checking payment status: ' . $e->getMessage(), array('source' => 'bog-payment-gateway'));
                }
                
                // Mark as on-hold and wait for callback
                $order->update_status('on-hold', __('Awaiting payment confirmation from Bank of Georgia', 'bog-payment-gateway'));
            }
        } else {
            // No BOG order ID - this shouldn't happen
            $order->add_order_note(__('Warning: BOG order ID not found. Awaiting callback.', 'bog-payment-gateway'));
            $order->update_status('on-hold', __('Awaiting payment confirmation', 'bog-payment-gateway'));
        }
        
        WC()->cart->empty_cart();
        wp_redirect($order->get_checkout_order_received_url());
        exit;
    }
    
    public function handle_fail_redirect() {
        // Log redirect for debugging
        if (class_exists('WC_Logger')) {
            $logger = wc_get_logger();
            $logger->info('Fail redirect received: ' . json_encode($_GET), array('source' => 'bog-payment-gateway'));
        }
        
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                // Only update to failed if not already processed
                if (!$order->is_paid() && $order->get_status() !== 'failed') {
                    $order->update_status('failed', __('Payment cancelled or failed at Bank of Georgia', 'bog-payment-gateway'));
                }
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
    
    /**
     * Add manual payment check action to order actions
     */
    public function add_check_payment_action($actions) {
        global $theorder;
        
        if (!$theorder) {
            return $actions;
        }
        
        // Only add for BOG payment method
        if ($theorder->get_payment_method() !== 'bog_payment') {
            return $actions;
        }
        
        // Only for pending or on-hold orders
        if (!in_array($theorder->get_status(), array('pending', 'on-hold', 'processing'))) {
            return $actions;
        }
        
        $bog_order_id = $theorder->get_meta('_bog_order_id', true);
        if ($bog_order_id) {
            $actions['bog_check_payment'] = __('Check BOG Payment Status', 'bog-payment-gateway');
        }
        
        return $actions;
    }
    
    /**
     * Process manual payment check action
     */
    public function process_check_payment_action($order) {
        $bog_order_id = $order->get_meta('_bog_order_id', true);
        
        if (!$bog_order_id) {
            $order->add_order_note(__('Cannot check payment status: BOG order ID not found', 'bog-payment-gateway'));
            return;
        }
        
        try {
            $gateway = new BOG_Payment_Gateway();
            $payment_status = $gateway->check_payment_status($bog_order_id);
            
            if ($payment_status) {
                $order->add_order_note(sprintf(
                    __('Manual status check - BOG Status: %s', 'bog-payment-gateway'),
                    $payment_status['bog_status']
                ));
                
                if ($payment_status['status'] === 'completed' && !$order->is_paid()) {
                    $order->payment_complete($bog_order_id);
                    $order->add_order_note(__('Payment confirmed via manual status check', 'bog-payment-gateway'));
                } elseif ($payment_status['status'] === 'failed' && $order->get_status() !== 'failed') {
                    $order->update_status('failed', __('Payment failed (confirmed via manual check)', 'bog-payment-gateway'));
                }
                
                // Store the full response for debugging
                $order->update_meta_data('_bog_last_manual_check', current_time('mysql'));
                $order->update_meta_data('_bog_last_status_response', json_encode($payment_status['details']));
                $order->save();
            } else {
                $order->add_order_note(__('Failed to retrieve payment status from BOG API', 'bog-payment-gateway'));
            }
        } catch (Exception $e) {
            $order->add_order_note(sprintf(
                __('Error checking payment status: %s', 'bog-payment-gateway'),
                $e->getMessage()
            ));
        }
    }
}

BOG_Payment_Gateway_Init::get_instance();