<?php
namespace Zithara\Webhook\Model\Config;

class ApiEndpoints
{
    const BASE_URL = 'https://pos-api.zithara.com/v1';
    
    // Authentication endpoint
    const TOKEN_ENDPOINT = self::BASE_URL . '/generate-access-token';
    
    // Customer endpoints
    const CUSTOMER_ENDPOINT = self::BASE_URL . '/customer';
    
    // Order endpoints
    const ORDER_ENDPOINT = self::BASE_URL . '/order';
    const ORDER_UPDATE_ENDPOINT = self::BASE_URL . '/order';
    
    // Cart endpoints
    const CART_ENDPOINT = self::BASE_URL . '/cart';
    
    /**
     * Get the token endpoint
     *
     * @return string
     */
    public function getTokenEndpoint()
    {
        return self::TOKEN_ENDPOINT;
    }
    
    /**
     * Get the customer endpoint
     *
     * @return string
     */
    public function getCustomerEndpoint()
    {
        return self::CUSTOMER_ENDPOINT;
    }
    
    /**
     * Get the order endpoint
     *
     * @return string
     */
    public function getOrderEndpoint()
    {
        return self::ORDER_ENDPOINT;
    }
    
    /**
     * Get the order update endpoint
     *
     * @return string
     */
    public function getOrderUpdateEndpoint()
    {
        return self::ORDER_UPDATE_ENDPOINT;
    }
    
    /**
     * Get the cart endpoint
     *
     * @return string
     */
    public function getCartEndpoint()
    {
        return self::CART_ENDPOINT;
    }
}