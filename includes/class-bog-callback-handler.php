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
        $this->log('Callback received');
        
        $headers = getallheaders();
        $body = file_get_contents('php://input');
        
        $this->log('Callback headers: ' . json_encode($headers));
        $this->log('Callback body: ' . $body);
        
        if (empty($body)) {
            $this->log('Empty callback body', 'error');
            wp_die('Invalid callback', 'Invalid Request', array('response' => 400));
        }
        
        $signature = isset($headers['signature']) ? $headers['signature'] : (isset($headers['Signature']) ? $headers['Signature'] : '');
        
        if ($signature && !$this->verify_signature($body, $signature)) {
            $this->log('Invalid signature', 'error');
            wp_die('Invalid signature', 'Unauthorized', array('response' => 401));
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('Invalid JSON: ' . json_last_error_msg(), 'error');
            wp_die('Invalid JSON', 'Invalid Request', array('response' => 400));
        }
        
        if (!isset($data['event']) || $data['event'] !== 'order_payment') {
            $this->log('Invalid event type: ' . (isset($data['event']) ? $data['event'] : 'none'), 'error');
            wp_die('Invalid event', 'Invalid Request', array('response' => 400));
        }
        
        if (!isset($data['body']['id'])) {
            $this->log('Missing order ID in callback', 'error');
            wp_die('Missing order ID', 'Invalid Request', array('response' => 400));
        }
        
        $bog_order_id = $data['body']['id'];
        $order_status = isset($data['body']['order_status']['key']) ? $data['body']['order_status']['key'] : '';
        $external_order_id = isset($data['body']['external_order_id']) ? $data['body']['external_order_id'] : '';
        
        $this->log('Processing callback for BOG order: ' . $bog_order_id . ', status: ' . $order_status);
        
        if ($external_order_id) {
            $order = wc_get_order($external_order_id);
        } else {
            $args = array(
                'meta_key' => '_bog_order_id',
                'meta_value' => $bog_order_id,
                'meta_compare' => '=',
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
        
        $this->process_order_status($order, $order_status, $data['body']);
        
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
        
        switch ($status) {
            case 'completed':
                if ($order->get_status() !== 'processing' && $order->get_status() !== 'completed') {
                    $transaction_id = isset($payment_data['transaction_id']) ? $payment_data['transaction_id'] : $payment_data['id'];
                    $order->payment_complete($transaction_id);
                    
                    $payment_method = isset($payment_data['payment_detail']['type']) ? $payment_data['payment_detail']['type'] : 'Bank of Georgia';
                    $order->add_order_note(sprintf(
                        __('Payment completed via %s. Transaction ID: %s', 'bog-payment-gateway'),
                        $payment_method,
                        $transaction_id
                    ));
                    
                    if (isset($payment_data['payment_detail']['code_description'])) {
                        $order->add_order_note(__('Payment details: ', 'bog-payment-gateway') . $payment_data['payment_detail']['code_description']);
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