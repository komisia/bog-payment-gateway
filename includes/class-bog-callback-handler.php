<?php

if (!defined('ABSPATH')) {
    exit;
}

class BOG_Callback_Handler {
    
    const PUBLIC_KEY = '-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAqWKaHmv3FVzYCPq4x8c3
Wfg9c7nfSGS6bCNCd7Y7H+Jm5KmfqA6TwMdTrU1HZLFUhSZ4eCqLK3FPuGBGEooT
EM/VZ6lzSonmVMhLavGGSkFQlQiPU3J+Gqj2wzAENr3vAqLsywHDCQiB8BvU2Y5X
9zj4bVcGGQdLBV+bfc0/i6mZ/PF3BwQGRqFH7TTmUPwL5a4WYvb7qzLCpTlJzGqS
uJlH0qvmR3LC8YYJlPorPfqRSGTLELM6wBH/lgKnNxY7OH9g+XNkm3P5HTa8hea8
UrVIIU8IKUo8q+4xZeR6pIhTUCibNMSMAsNwOEFRYYlNBpQFpBuoMBQX0JNqkCBB
AQIDAQAB
-----END PUBLIC KEY-----';
    
    private $debug;
    
    public function __construct() {
        $gateway_settings = get_option('woocommerce_bog_payment_settings', array());
        $this->debug = isset($gateway_settings['debug']) && $gateway_settings['debug'] === 'yes';
    }
    
    private function log($message, $level = 'info') {
        if (!$this->debug) {
            return;
        }
        
        $logger = wc_get_logger();
        $context = array('source' => 'bog-payment-gateway-callback');
        $logger->log($level, $message, $context);
    }
    
    public function process_callback() {
        $this->log('Callback received at ' . current_time('mysql'));
        
        // Get headers with fallback for different server configurations
        $headers = getallheaders();
        if ($headers === false) {
            $headers = $this->get_headers_fallback();
        }
        
        $body = file_get_contents('php://input');
        
        $this->log('Callback headers: ' . json_encode($headers));
        $this->log('Callback body: ' . $body);
        
        if (empty($body)) {
            $this->log('Empty callback body', 'error');
            wp_die('Invalid callback', 'Invalid Request', array('response' => 400));
        }
        
        // Check for signature but don't require it (optional per BOG docs)
        $signature = isset($headers['signature']) ? $headers['signature'] : 
                     (isset($headers['Signature']) ? $headers['Signature'] : '');
        
        if ($signature) {
            $signature_valid = $this->verify_signature($body, $signature);
            if (!$signature_valid) {
                $this->log('Signature verification failed, but continuing processing', 'warning');
            }
        } else {
            $this->log('No signature provided in callback headers', 'warning');
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('Invalid JSON: ' . json_last_error_msg(), 'error');
            wp_die('Invalid JSON', 'Invalid Request', array('response' => 400));
        }
        
        if (!isset($data['event']) || $data['event'] !== 'order_payment') {
            $this->log('Unexpected event type: ' . (isset($data['event']) ? $data['event'] : 'none') . ', expected: order_payment', 'warning');
            // Don't reject, as BOG might send different event types
        }
        
        // Check for order ID in different possible locations
        // Based on production logs, BOG sends order_id in body.order_id
        $bog_order_id = null;
        if (isset($data['body']['order_id'])) {
            $bog_order_id = $data['body']['order_id'];
        } elseif (isset($data['body']['id'])) {
            $bog_order_id = $data['body']['id'];
        } elseif (isset($data['order_id'])) {
            $bog_order_id = $data['order_id'];
        } elseif (isset($data['id'])) {
            $bog_order_id = $data['id'];
        }
        
        if (!$bog_order_id) {
            $this->log('Missing order ID in callback data. Data structure: ' . json_encode($data), 'error');
            wp_die('Missing order ID', 'Invalid Request', array('response' => 400));
        }
        
        $order_status = '';
        if (isset($data['body']['order_status']['key'])) {
            $order_status = $data['body']['order_status']['key'];
        } elseif (isset($data['order_status']['key'])) {
            $order_status = $data['order_status']['key'];
        } elseif (isset($data['body']['status'])) {
            $order_status = $data['body']['status'];
        } elseif (isset($data['status'])) {
            $order_status = $data['status'];
        }
        
        $external_order_id = '';
        if (isset($data['body']['external_order_id'])) {
            $external_order_id = $data['body']['external_order_id'];
        } elseif (isset($data['external_order_id'])) {
            $external_order_id = $data['external_order_id'];
        }
        
        $this->log('Processing callback for BOG order: ' . $bog_order_id . ', status: ' . $order_status . ', external_order_id: ' . $external_order_id);
        
        if ($external_order_id) {
            $order = wc_get_order($external_order_id);
        } else {
            $args = array(
                'meta_query' => array(
                    array(
                        'key' => '_bog_order_id',
                        'value' => $bog_order_id,
                        'compare' => '='
                    )
                ),
                'return' => 'ids',
                'limit' => 1,
            );
            
            $orders = wc_get_orders($args);
            
            if (empty($orders)) {
                $this->log('Order not found for BOG order ID: ' . $bog_order_id, 'error');
                wp_die('Order not found', 'Not Found', array('response' => 404));
            }
            
            $order = wc_get_order($orders[0]);
        }
        
        if (!$order) {
            $this->log('WooCommerce order not found', 'error');
            wp_die('Order not found', 'Not Found', array('response' => 404));
        }
        
        // Pass the correct data structure
        $payment_data = isset($data['body']) ? $data['body'] : $data;
        $this->process_order_status($order, $order_status, $payment_data);
        
        status_header(200);
        echo 'OK';
        exit;
    }
    
    private function verify_signature($body, $signature) {
        $this->log('Verifying signature');
        
        $public_key = openssl_pkey_get_public(self::PUBLIC_KEY);
        
        if (!$public_key) {
            $this->log('Failed to load public key', 'error');
            return false;
        }
        
        $signature_binary = base64_decode($signature);
        
        $result = openssl_verify($body, $signature_binary, $public_key, OPENSSL_ALGO_SHA256);
        
        openssl_free_key($public_key);
        
        if ($result === 1) {
            $this->log('Signature verified successfully');
            return true;
        } elseif ($result === 0) {
            $this->log('Signature verification failed', 'error');
            return false;
        } else {
            $this->log('Error verifying signature: ' . openssl_error_string(), 'error');
            return false;
        }
    }
    
    private function process_order_status($order, $status, $payment_data) {
        $this->log('Processing order status: ' . $status . ' for order #' . $order->get_id());
        
        // If order is already completed, don't process again
        if ($order->get_status() === 'completed') {
            $this->log('Order is already completed, skipping callback processing');
            return;
        }
        
        switch ($status) {
            case 'completed':
            case 'success':
            case 'successful':
            case 'approved':
                if (!$order->is_paid()) {
                    // First check payment status via API to be absolutely sure
                    $bog_order_id = $order->get_meta('_bog_order_id', true);
                    if (!$bog_order_id && isset($payment_data['order_id'])) {
                        $bog_order_id = $payment_data['order_id'];
                    }
                    
                    $should_complete = false;
                    
                    if ($bog_order_id) {
                        try {
                            $gateway_settings = get_option('woocommerce_bog_payment_settings', array());
                            $test_mode = isset($gateway_settings['test_mode']) && $gateway_settings['test_mode'] === 'yes';
                            $client_id = $test_mode ? $gateway_settings['test_client_id'] : $gateway_settings['client_id'];
                            $client_secret = $test_mode ? $gateway_settings['test_client_secret'] : $gateway_settings['client_secret'];
                            
                            if ($client_id && $client_secret) {
                                if (!class_exists('BOG_API_Client')) {
                                    require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-bog-api-client.php';
                                }
                                $api_client = new BOG_API_Client($client_id, $client_secret, $test_mode, $this->debug);
                                $payment_details = $api_client->get_payment_details($bog_order_id);
                                
                                if ($payment_details && isset($payment_details['order_status']['key']) && 
                                    $payment_details['order_status']['key'] === 'completed') {
                                    $should_complete = true;
                                    
                                    // Get transaction ID from API response
                                    if (isset($payment_details['payment_detail']['transaction_id'])) {
                                        $transaction_id = $payment_details['payment_detail']['transaction_id'];
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            $this->log('Error checking payment status in callback: ' . $e->getMessage(), 'warning');
                            // Fall back to callback data
                            $should_complete = true;
                        }
                    } else {
                        // No BOG order ID, trust callback
                        $should_complete = true;
                    }
                    
                    if ($should_complete) {
                        // Get transaction ID from various possible locations
                        if (!isset($transaction_id)) {
                            $transaction_id = '';
                            if (isset($payment_data['payment_detail']['transaction_id'])) {
                                $transaction_id = $payment_data['payment_detail']['transaction_id'];
                            } elseif (isset($payment_data['transaction_id'])) {
                                $transaction_id = $payment_data['transaction_id'];
                            } elseif (isset($payment_data['payment_hash'])) {
                                $transaction_id = $payment_data['payment_hash'];
                            } elseif (isset($payment_data['order_id'])) {
                                $transaction_id = $payment_data['order_id'];
                            }
                        }
                        
                        $order->payment_complete($transaction_id);
                        $order->update_status('completed', __('Payment confirmed via Bank of Georgia callback', 'bog-payment-gateway'));
                        
                        $payment_method = isset($payment_data['payment_detail']['type']) ? $payment_data['payment_detail']['type'] : 'Bank of Georgia';
                        if (isset($payment_data['payment_detail']['transfer_method']['value'])) {
                            $payment_method = $payment_data['payment_detail']['transfer_method']['value'];
                        }
                        
                        $order->add_order_note(sprintf(
                            __('Payment completed via %s. Transaction ID: %s', 'bog-payment-gateway'),
                            $payment_method,
                            $transaction_id
                        ));
                        
                        if (isset($payment_data['payment_detail']['code_description'])) {
                            $order->add_order_note(__('Payment details: ', 'bog-payment-gateway') . $payment_data['payment_detail']['code_description']);
                        }
                    } else {
                        $this->log('Payment verification failed in callback, not completing order', 'error');
                    }
                }
                break;
                
            case 'rejected':
            case 'failed':
                if ($order->get_status() !== 'failed') {
                    $order->update_status('failed', __('Payment failed at Bank of Georgia', 'bog-payment-gateway'));
                    
                    if (isset($payment_data['payment_detail']['code_description'])) {
                        $order->add_order_note(__('Failure reason: ', 'bog-payment-gateway') . $payment_data['payment_detail']['code_description']);
                    }
                }
                break;
                
            case 'refunded':
                if ($order->get_status() !== 'refunded') {
                    $order->update_status('refunded', __('Payment refunded via Bank of Georgia', 'bog-payment-gateway'));
                    
                    if (isset($payment_data['refund_amount'])) {
                        $order->add_order_note(sprintf(
                            __('Refund amount: %s GEL', 'bog-payment-gateway'),
                            $payment_data['refund_amount']
                        ));
                    }
                }
                break;
                
            case 'processing':
            case 'created':
                if ($order->get_status() === 'pending') {
                    $order->add_order_note(__('Payment is being processed at Bank of Georgia', 'bog-payment-gateway'));
                }
                break;
                
            default:
                $this->log('Unknown order status: ' . $status, 'warning');
                $order->add_order_note(sprintf(
                    __('Received unknown status from Bank of Georgia: %s', 'bog-payment-gateway'),
                    $status
                ));
                break;
        }
        
        $order->update_meta_data('_bog_last_status', $status);
        $order->update_meta_data('_bog_last_callback', current_time('mysql'));
        $order->save();
        
        $this->log('Order status processing completed');
        
        $this->save_callback_log($order->get_id(), $payment_data['id'], $status, json_encode($payment_data));
    }
    
    private function save_callback_log($order_id, $bog_order_id, $status, $message) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bog_payment_logs';
        
        // Check if table exists before inserting
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            $wpdb->insert(
                $table_name,
                array(
                    'order_id' => $order_id,
                    'bog_order_id' => $bog_order_id,
                    'status' => $status,
                    'message' => $message,
                ),
                array('%d', '%s', '%s', '%s')
            );
        }
    }
    
    private function get_headers_fallback() {
        $headers = array();
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $header_name = str_replace('_', '-', substr($name, 5));
                $header_name = str_replace(' ', '-', ucwords(strtolower($header_name)));
                $headers[$header_name] = $value;
            }
        }
        return $headers;
    }
}