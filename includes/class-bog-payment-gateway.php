<?php

if (!defined('ABSPATH')) {
    exit;
}

class BOG_Payment_Gateway extends WC_Payment_Gateway {
    
    private $client_id;
    private $client_secret;
    private $test_mode;
    private $debug;
    private $api_client;
    
    public function __construct() {
        $this->id = 'bog_payment';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = __('Bank of Georgia', 'bog-payment-gateway');
        $this->method_description = __('Accept payments through Bank of Georgia payment system', 'bog-payment-gateway');
        
        $this->supports = array(
            'products',
            'refunds',
        );
        
        $this->init_form_fields();
        $this->init_settings();
        
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->test_mode = 'yes' === $this->get_option('test_mode');
        $this->debug = 'yes' === $this->get_option('debug');
        $this->client_id = $this->test_mode ? $this->get_option('test_client_id') : $this->get_option('client_id');
        $this->client_secret = $this->test_mode ? $this->get_option('test_client_secret') : $this->get_option('client_secret');
        
        $this->api_client = new BOG_API_Client($this->client_id, $this->client_secret, $this->test_mode, $this->debug);
        
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('admin_notices', array($this, 'admin_notices'));
    }
    
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'bog-payment-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable Bank of Georgia Payment Gateway', 'bog-payment-gateway'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'bog-payment-gateway'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'bog-payment-gateway'),
                'default' => __('Bank of Georgia', 'bog-payment-gateway'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'bog-payment-gateway'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'bog-payment-gateway'),
                'default' => __('Pay securely with Bank of Georgia payment system.', 'bog-payment-gateway'),
                'desc_tip' => true,
            ),
            'test_mode' => array(
                'title' => __('Test Mode', 'bog-payment-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable Test Mode', 'bog-payment-gateway'),
                'default' => 'yes',
                'description' => __('Use test API credentials for testing.', 'bog-payment-gateway'),
            ),
            'test_client_id' => array(
                'title' => __('Test Client ID', 'bog-payment-gateway'),
                'type' => 'text',
                'description' => __('Enter your Bank of Georgia test API Client ID.', 'bog-payment-gateway'),
                'default' => '',
                'desc_tip' => true,
            ),
            'test_client_secret' => array(
                'title' => __('Test Client Secret', 'bog-payment-gateway'),
                'type' => 'password',
                'description' => __('Enter your Bank of Georgia test API Client Secret.', 'bog-payment-gateway'),
                'default' => '',
                'desc_tip' => true,
            ),
            'client_id' => array(
                'title' => __('Live Client ID', 'bog-payment-gateway'),
                'type' => 'text',
                'description' => __('Enter your Bank of Georgia API Client ID.', 'bog-payment-gateway'),
                'default' => '',
                'desc_tip' => true,
            ),
            'client_secret' => array(
                'title' => __('Live Client Secret', 'bog-payment-gateway'),
                'type' => 'password',
                'description' => __('Enter your Bank of Georgia API Client Secret.', 'bog-payment-gateway'),
                'default' => '',
                'desc_tip' => true,
            ),
            'debug' => array(
                'title' => __('Debug Log', 'bog-payment-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable logging', 'bog-payment-gateway'),
                'default' => 'no',
                'description' => sprintf(__('Log Bank of Georgia events, such as API requests, inside %s', 'bog-payment-gateway'), '<code>' . WC_Log_Handler_File::get_log_file_path('bog-payment-gateway') . '</code>'),
            ),
        );
    }
    
    public function admin_notices() {
        if (!$this->is_valid_for_use()) {
            echo '<div class="error"><p>' . __('Bank of Georgia Payment Gateway requires Georgian Lari (GEL) currency.', 'bog-payment-gateway') . '</p></div>';
        }
    }
    
    public function is_valid_for_use() {
        return in_array(get_woocommerce_currency(), array('GEL'), true);
    }
    
    public function process_admin_options() {
        $saved = parent::process_admin_options();
        
        if ($saved) {
            $this->test_mode = 'yes' === $this->get_option('test_mode');
            $this->client_id = $this->test_mode ? $this->get_option('test_client_id') : $this->get_option('client_id');
            $this->client_secret = $this->test_mode ? $this->get_option('test_client_secret') : $this->get_option('client_secret');
            
            if ($this->client_id && $this->client_secret) {
                $api_client = new BOG_API_Client($this->client_id, $this->client_secret, $this->test_mode, $this->debug);
                
                if ($api_client->test_connection()) {
                    WC_Admin_Settings::add_message(__('Bank of Georgia API connection successful!', 'bog-payment-gateway'));
                } else {
                    WC_Admin_Settings::add_error(__('Bank of Georgia API connection failed. Please check your credentials.', 'bog-payment-gateway'));
                }
            }
        }
        
        return $saved;
    }
    
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        try {
            $callback_url = add_query_arg('wc-api', 'bog_callback', home_url('/'));
            $success_url = add_query_arg(
                array(
                    'wc-api' => 'bog_success',
                    'order_id' => $order_id,
                    'bog_order_id' => '{order_id}',
                ),
                home_url('/')
            );
            $fail_url = add_query_arg(
                array(
                    'wc-api' => 'bog_fail',
                    'order_id' => $order_id,
                ),
                home_url('/')
            );
            
            $basket = array();
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                $basket[] = array(
                    'quantity' => $item->get_quantity(),
                    'unit_price' => number_format($item->get_subtotal() / $item->get_quantity(), 2, '.', ''),
                    'product_id' => $product->get_sku() ?: $product->get_id(),
                    'description' => $item->get_name(),
                );
            }
            
            if ($order->get_shipping_total() > 0) {
                $basket[] = array(
                    'quantity' => 1,
                    'unit_price' => number_format($order->get_shipping_total(), 2, '.', ''),
                    'product_id' => 'SHIPPING',
                    'description' => __('Shipping', 'bog-payment-gateway'),
                );
            }
            
            if ($order->get_total_tax() > 0) {
                $basket[] = array(
                    'quantity' => 1,
                    'unit_price' => number_format($order->get_total_tax(), 2, '.', ''),
                    'product_id' => 'TAX',
                    'description' => __('Tax', 'bog-payment-gateway'),
                );
            }
            
            $order_data = array(
                'callback_url' => $callback_url,
                'redirect_urls' => array(
                    'success' => $success_url,
                    'fail' => $fail_url,
                ),
                'purchase_units' => array(
                    'total_amount' => number_format($order->get_total(), 2, '.', ''),
                    'currency' => 'GEL',
                    'basket' => $basket,
                ),
                'capture' => 'automatic',
                'intent' => 'CAPTURE',
                'locale' => 'ka',
                'external_order_id' => (string)$order_id,
            );
            
            $response = $this->api_client->create_order($order_data);
            
            if (empty($response['id']) || empty($response['_links']['redirect']['href'])) {
                throw new Exception(__('Invalid response from payment gateway', 'bog-payment-gateway'));
            }
            
            $order->update_meta_data('_bog_order_id', $response['id']);
            $order->save();
            
            $order->add_order_note(sprintf(__('Bank of Georgia order created. Order ID: %s', 'bog-payment-gateway'), $response['id']));
            
            if ($this->debug) {
                $logger = wc_get_logger();
                $logger->info('BOG Payment - Order created: ' . json_encode($response), array('source' => 'bog-payment-gateway'));
            }
            
            return array(
                'result' => 'success',
                'redirect' => $response['_links']['redirect']['href'],
            );
            
        } catch (Exception $e) {
            wc_add_notice(__('Payment error: ', 'bog-payment-gateway') . $e->getMessage(), 'error');
            
            if ($this->debug) {
                $logger = wc_get_logger();
                $logger->error('BOG Payment - Process payment error: ' . $e->getMessage(), array('source' => 'bog-payment-gateway'));
            }
            
            return array(
                'result' => 'fail',
                'redirect' => '',
            );
        }
    }
    
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        $bog_order_id = $order->get_meta('_bog_order_id');
        
        if (!$bog_order_id) {
            return new WP_Error('error', __('Bank of Georgia order ID not found', 'bog-payment-gateway'));
        }
        
        return new WP_Error('error', __('Refunds are not yet implemented. Please process refunds through Bank of Georgia portal.', 'bog-payment-gateway'));
    }
    
    public function check_payment_status($bog_order_id) {
        try {
            $payment_details = $this->api_client->get_payment_details($bog_order_id);
            
            if (!isset($payment_details['order_status']['key'])) {
                return false;
            }
            
            $status_map = array(
                'completed' => 'completed',
                'created' => 'pending',
                'processing' => 'pending',
                'rejected' => 'failed',
                'refunded' => 'refunded',
            );
            
            $bog_status = $payment_details['order_status']['key'];
            $mapped_status = isset($status_map[$bog_status]) ? $status_map[$bog_status] : 'unknown';
            
            return array(
                'status' => $mapped_status,
                'bog_status' => $bog_status,
                'details' => $payment_details,
            );
            
        } catch (Exception $e) {
            if ($this->debug) {
                $logger = wc_get_logger();
                $logger->error('BOG Payment - Check payment status error: ' . $e->getMessage(), array('source' => 'bog-payment-gateway'));
            }
            return false;
        }
    }
}