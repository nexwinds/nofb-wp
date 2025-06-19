<?php
/**
 * NOFB API Class
 * Handles API connections and requests
 */

if (!defined('ABSPATH')) {
    exit;
}

class NOFB_API {
    
    private $api_key;
    private $api_region;
    private $api_base_url;
    
    public function __construct() {
        $this->api_key = NOFB_API_KEY;
        $this->api_region = NOFB_API_REGION;
        $this->api_base_url = $this->get_api_base_url();
    }
    
    /**
     * Get API base URL based on region
     */
    private function get_api_base_url() {
        if ($this->api_region === 'eu' || $this->api_region === 'me') {
            return 'https://api-eu.nofb.nexwinds.com';
        }
        return 'https://api-us.nofb.nexwinds.com';
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        // Check if API key is set
        if (empty($this->api_key)) {
            return array(
                'status' => 'error',
                'message' => __('API key not configured.', 'nexoffload-for-bunny')
            );
        }
        
        // Send test request
        $response = wp_remote_get(
            $this->api_base_url . '/v1/account/status',
            array(
                'headers' => array(
                    'x-api-key' => $this->api_key
                ),
                'timeout' => 15,
                'sslverify' => true
            )
        );
        
        if (is_wp_error($response)) {
            return array(
                'status' => 'error',
                'message' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($response_code === 200 && isset($response_body['status']) && $response_body['status'] === 'active') {
            return array(
                'status' => 'success',
                /* translators: %s: Number of API credits available or N/A if unavailable */
                'message' => sprintf(
                    // translators: %s: Number of API credits available or N/A if unavailable
                    __('API connection successful. Credits: %s', 'nexoffload-for-bunny'),
                    isset($response_body['credits']) ? $response_body['credits'] : 'N/A'
                ),
                'credits' => isset($response_body['credits']) ? $response_body['credits'] : 0
            );
        } elseif ($response_code === 401) {
            return array(
                'status' => 'error',
                'message' => __('Invalid API key.', 'nexoffload-for-bunny')
            );
        } else {
            return array(
                'status' => 'error',
                /* translators: %s: Error message from API response or "Unknown error" if unavailable */
                'message' => sprintf(
                    // translators: %s: Error message from API response or "Unknown error" if unavailable
                    __('API error: %s', 'nexoffload-for-bunny'),
                    isset($response_body['message']) ? $response_body['message'] : 'Unknown error'
                )
            );
        }
    }
    
    /**
     * Get account status and credits
     */
    public function get_account_status() {
        // Send request
        $response = wp_remote_get(
            $this->api_base_url . '/v1/account/status',
            array(
                'headers' => array(
                    'x-api-key' => $this->api_key
                ),
                'timeout' => 15,
                'sslverify' => true
            )
        );
        
        if (is_wp_error($response)) {
            return array(
                'status' => 'error',
                'message' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($response_code === 200) {
            return $response_body;
        } else {
            return array(
                'status' => 'error',
                /* translators: %s: Error message from API or "Unknown error" if unavailable */
                'message' => sprintf(
                    // translators: %s: Error message from API or "Unknown error" if unavailable
                    __('API error: %s', 'nexoffload-for-bunny'),
                    isset($response_body['message']) ? $response_body['message'] : 'Unknown error'
                )
            );
        }
    }
    
    /**
     * Verify custom hostname validity
     */
    public function verify_custom_hostname() {
        if (empty($this->bunny_hostname)) {
            return array(
                'status' => 'error',
                'message' => __('Bunny.net custom hostname is not configured.', 'nexoffload-for-bunny')
            );
        }
        
        $url = 'https://' . $this->bunny_hostname . '/nexoffload-for-bunny-test.txt';
        
        $response = wp_remote_get($url, array('timeout' => 30, 'sslverify' => true));
        
        if (is_wp_error($response)) {
            return array(
                'status' => 'error',
                'message' => $response->get_error_message()
            );
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code === 404) {
            // This is expected since the test file doesn't exist
            return array(
                'status' => 'success',
                'message' => __('Bunny.net custom hostname is valid.', 'nexoffload-for-bunny')
            );
        }
        
        return array(
            'status' => 'warning',
            /* translators: %s: HTTP status code */
            'message' => sprintf(
                // translators: %s: HTTP status code
                __('Bunny.net custom hostname returned unexpected status: %s. This may still work, but verify your configuration.', 'nexoffload-for-bunny'), 
                $code
            )
        );
    }
    
    /**
     * Get NOFB account credits
     */
    public function get_nofb_credits() {
        if (empty($this->NOFB_API_KEY)) {
            return 0;
        }
        
        $url = $this->get_nofb_api_url() . '/v1/account/credits';
        
        $response = wp_remote_get(
            $url,
            array(
                'headers' => array(
                    'x-api-key' => $this->NOFB_API_KEY
                ),
                'timeout' => 30,
                'sslverify' => true
            )
        );
        
        if (is_wp_error($response)) {
            return 0;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code === 200 && isset($body['credits'])) {
            return intval($body['credits']);
        }
        
        return 0;
    }
} 