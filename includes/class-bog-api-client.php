<?php

if (!defined('ABSPATH')) {
    exit;
}

class BOG_API_Client {
    
    const AUTH_URL = 'https://oauth2.bog.ge/auth/realms/bog/protocol/openid-connect/token';
    const API_BASE_URL = 'https://api.bog.ge/payments/v1';
    const TOKEN_OPTION_KEY = 'bog_payment_gateway_token';
    const TOKEN_EXPIRY_OPTION_KEY = 'bog_payment_gateway_token_expiry';
    
    private $client_id;
    private $client_secret;
    private $is_test_mode;
    private $debug;
    
    public function __construct($client_id, $client_secret, $is_test_mode = false, $debug = false) {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->is_test_mode = $is_test_mode;
        $this->debug = $debug;
    }
    
    private function log($message, $level = 'info') {
        if (!$this->debug) {
            return;
        }
        
        $logger = wc_get_logger();
        $context = array('source' => 'bog-payment-gateway');
        $logger->log($level, $message, $context);
    }
    
    public function get_access_token($force_refresh = false) {
        $cached_token = get_transient(self::TOKEN_OPTION_KEY);
        
        if (!$force_refresh && $cached_token) {
            $this->log('Using cached access token');
            return $cached_token;
        }
        
        $this->log('Requesting new access token');
        
        $response = wp_remote_post(self::AUTH_URL, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->client_id . ':' . $this->client_secret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => array(
                'grant_type' => 'client_credentials',
            ),
            'timeout' => 30,
            'sslverify' => !$this->is_test_mode,
        ));
        
        if (is_wp_error($response)) {
            $this->log('Authentication failed: ' . $response->get_error_message(), 'error');
            throw new Exception('Authentication failed: ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data['access_token'])) {
            $this->log('Invalid authentication response: ' . $body, 'error');
            throw new Exception('Invalid authentication response');
        }
        
        $expires_in = isset($data['expires_in']) ? intval($data['expires_in']) : 3600;
        $expires_in = max($expires_in - 60, 60);
        
        set_transient(self::TOKEN_OPTION_KEY, $data['access_token'], $expires_in);
        
        $this->log('Access token obtained, expires in ' . $expires_in . ' seconds');
        
        return $data['access_token'];
    }
    
    public function create_order($order_data) {
        $url = self::API_BASE_URL . '/ecommerce/orders';
        
        $this->log('Creating order: ' . json_encode($order_data));
        
        $token = $this->get_access_token();
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => json_encode($order_data),
            'timeout' => 30,
            'sslverify' => !$this->is_test_mode,
        ));
        
        if (is_wp_error($response)) {
            $this->log('Order creation failed: ' . $response->get_error_message(), 'error');
            throw new Exception('Order creation failed: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code === 401) {
            $this->log('Token expired, refreshing and retrying');
            $token = $this->get_access_token(true);
            
            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ),
                'body' => json_encode($order_data),
                'timeout' => 30,
                'sslverify' => !$this->is_test_mode,
            ));
            
            if (is_wp_error($response)) {
                $this->log('Order creation retry failed: ' . $response->get_error_message(), 'error');
                throw new Exception('Order creation failed: ' . $response->get_error_message());
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
        }
        
        if ($status_code !== 200 && $status_code !== 201) {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->log('Order creation failed with status ' . $status_code . ': ' . $error_message, 'error');
            throw new Exception('Order creation failed: ' . $error_message);
        }
        
        $this->log('Order created successfully: ' . json_encode($data));
        
        return $data;
    }
    
    public function get_payment_details($order_id) {
        $url = self::API_BASE_URL . '/receipt/' . $order_id;
        
        $this->log('Getting payment details for order: ' . $order_id);
        
        $token = $this->get_access_token();
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ),
            'timeout' => 30,
            'sslverify' => !$this->is_test_mode,
        ));
        
        if (is_wp_error($response)) {
            $this->log('Failed to get payment details: ' . $response->get_error_message(), 'error');
            throw new Exception('Failed to get payment details: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code === 401) {
            $this->log('Token expired, refreshing and retrying');
            $token = $this->get_access_token(true);
            
            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ),
                'timeout' => 30,
                'sslverify' => !$this->is_test_mode,
            ));
            
            if (is_wp_error($response)) {
                $this->log('Failed to get payment details (retry): ' . $response->get_error_message(), 'error');
                throw new Exception('Failed to get payment details: ' . $response->get_error_message());
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
        }
        
        if ($status_code !== 200) {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->log('Failed to get payment details with status ' . $status_code . ': ' . $error_message, 'error');
            throw new Exception('Failed to get payment details: ' . $error_message);
        }
        
        $this->log('Payment details retrieved: ' . json_encode($data));
        
        return $data;
    }
    
    public function test_connection() {
        try {
            $token = $this->get_access_token(true);
            return !empty($token);
        } catch (Exception $e) {
            $this->log('Connection test failed: ' . $e->getMessage(), 'error');
            return false;
        }
    }
}