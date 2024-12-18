<?php
namespace Zithara\Webhook\Model\Queue;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Zithara\Webhook\Model\ConfigFactory;
use Psr\Log\LoggerInterface;
use Zithara\Webhook\Model\Config\ApiEndpoints;

class CartProcessor
{

    protected $curl;
    protected $jsonHelper;
    protected $configFactory;
    protected $logger;
    protected $apiEndpoints;

    public function __construct(
        Curl $curl,
        JsonHelper $jsonHelper,
        ConfigFactory $configFactory,
        ApiEndpoints $apiEndpoints,
        LoggerInterface $logger
    ) {
        $this->curl = $curl;
        $this->jsonHelper = $jsonHelper;
        $this->logger = $logger;
        $this->configFactory = $configFactory;
        $this->apiEndpoints = $apiEndpoints;
    }

    /**
     * Processes a cart message by decoding the JSON payload, retrieving configuration
     * and access token, transforming the cart data, and sending it to the Zithara API.
     * Logs errors for invalid JSON format, missing configuration, or token retrieval issues.
     *
     * @param string $message JSON formatted cart data
     * @return void
     */
    public function process($message)
    {
        try {
            if (empty($message)) {
                $this->logger->error('CartProcessor: Empty message received');
                return;
            }

            $this->logger->info('CartProcessor: Processing cart message');

            try {
                $data = $this->jsonHelper->jsonDecode($message, true);
                
                // Log the complete data structure
                //$this->logger->info('CartProcessor: Received data:', [
                 //   'data' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                //]);
            } catch (\Exception $e) {
                $this->logger->error('CartProcessor: Invalid JSON message format', [
                    'error' => $e->getMessage(),
                    'message' => substr($message, 0, 255)
                ]);
                return;
            }

            // Load configuration
            $config = $this->configFactory->create()->getCollection()
                ->addFieldToFilter('is_active', 1)
                ->getFirstItem();

            if (!$config->getId()) {
                throw new \Exception('Active webhook configuration not found');
            }

            // Get fresh token
            $accessToken = $this->getAccessToken($config);
            if (!$accessToken) {
                throw new \Exception('Failed to obtain access token');
            }

            // Transform cart data
            $zitharaCartData = $this->transformCartData($data);
            
            // Log the transformed data
            //$this->logger->info('CartProcessor: Transformed data:', [
                //'transformed_data' => json_encode($zitharaCartData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            //]);

            // Send to Zithara API
            $this->sendToZitharaApi($zitharaCartData, $accessToken);

        } catch (\Exception $e) {
            $this->logger->error('CartProcessor: Unexpected error', [
                'error' => $e->getMessage()
                //'trace' => $e->getTraceAsString()
            ]);
        }
    }

    protected function transformCartData($data)
    {
        try {
            // Use the correct data structure based on the input
            $cart = $data['quote'] ?? [];
            $cartItems = $data['items'] ?? [];
            $customer = $data['customer'] ?? [];

            // Log input data for transformation
            $this->logger->info('CartProcessor: Input data for transformation:', [
                'cart' => json_encode($cart, JSON_PRETTY_PRINT),
                'cart_items' => json_encode($cartItems, JSON_PRETTY_PRINT),
                'customer' => json_encode($customer, JSON_PRETTY_PRINT)
            ]);

            // Transform customer data
            $customerData = [
                'platform_customer_id' => $customer['platform_customer_id'] ?? '',
                'first_name' => $customer['first_name'] ?? '',
                'last_name' => $customer['last_name'] ?? '',
                'name' => $customer['name'] ?? trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')),
                'whatsapp_phone_number' => $customer['whatsapp_phone_number'] ?? '',
                'email' => $customer['email'] ?? false,
                'custom_attributes' => new \stdClass()
            ];

            // Transform cart items - use the existing platform_cart_item_id
            $transformedCartItems = [];
            foreach ($cartItems as $item) {
                // Use platform_cart_item_id directly since it's already in the correct format
                if (!isset($item['platform_cart_item_id']) || empty($item['platform_cart_item_id'])) {
                    $this->logger->warning('CartProcessor: Missing platform_cart_item_id for cart item', [
                        'cart_id' => $cart['platform_cart_id'] ?? 'unknown',
                        'product_id' => $item['product_id'] ?? 'unknown',
                        'item_data' => json_encode($item, JSON_PRETTY_PRINT)
                    ]);
                    continue;
                }

                $transformedCartItems[] = [
                    'platform_cart_item_id' => (string)$item['platform_cart_item_id'],
                    'price' => (float)($item['price'] ?? 0),
                    'product_id' => $item['product_id'] ?? '',
                    'quantity' => (float)($item['quantity'] ?? 1)
                ];
            }

            if (empty($transformedCartItems)) {
                throw new \Exception('No valid cart items found');
            }

            // Transform cart data
            $cartData = [
                'currency' => $cart['currency'] ?? 'INR',
                'platform_cart_id' => $cart['platform_cart_id'] ?? '',
                'name' => $cart['platform_cart_id'],
                'created_at' => $cart['created_at'] ?? date('Y-m-d H:i:s'),
                'total_tax' => (float)($cart['total_tax'] ?? 0),
                'total_price' => (float)($cart['total_price'] ?? 0),
                'shopify_customer_id' => $customer['platform_customer_id'] ?? null
            ];

            $transformedData = [
                'customer' => $customerData,
                'cart_item' => $transformedCartItems,
                'cart' => $cartData
            ];

            // Log the transformed data
            //$this->logger->info('CartProcessor: Transformed cart data:', [
                //'transformed_data' => json_encode($transformedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            //]);

            return $transformedData;

        } catch (\Exception $e) {
            $this->logger->error('CartProcessor: Error transforming cart data', [
                'error' => $e->getMessage()
                //'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function generateCartName($cart)
    {
        $prefix = 'CART';
        $cartId = $cart['platform_cart_id'] ?? '';
        $timestamp = date('YmdHis');
        
        return "{$prefix}/{$cartId}/{$timestamp}";
    }

    protected function formatPhoneNumber($phone)
    {
        if (empty($phone)) {
            return '';
        }

        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Ensure it starts with '91'
        if (substr($phone, 0, 2) !== '91') {
            $phone = '91' . $phone;
        }

        return $phone;
    }

    protected function getAccessToken($config)
    {
        try {
            // Check if we have a valid token
            if ($config->getAccessToken() && $config->getTokenExpiry() && 
                strtotime($config->getTokenExpiry()) > time()) {
                return $config->getAccessToken();
            }

            $tokenCurl = new Curl();
            
            $tokenCurl->addHeader('Content-Type', 'application/json');
            $tokenCurl->addHeader('Accept', 'application/json');
            $tokenCurl->addHeader('client_id', $config->getClientId());
            $tokenCurl->addHeader('client_secret', $config->getClientSecret());

            $tokenCurl->get($this->apiEndpoints->getTokenEndpoint());

            $responseStatus = $tokenCurl->getStatus();
            $responseBody = $tokenCurl->getBody();

            if ($responseStatus !== 200 && $responseStatus !== 201) {
                throw new \Exception("Token request failed with status {$responseStatus}");
            }

            $response = $this->jsonHelper->jsonDecode($responseBody, true);

            if (!isset($response['access_token'])) {
                throw new \Exception('Access token not found in response');
            }

            // Update config with new token
            $config->setAccessToken($response['access_token']);
            $expiresIn = isset($response['expires_in']) ? (int)$response['expires_in'] : 3600;
            $config->setTokenExpiry(date('Y-m-d H:i:s', time() + $expiresIn));
            $config->save();

            return $response['access_token'];

        } catch (\Exception $e) {
            $this->logger->error('CartProcessor: Error getting access token', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    protected function sendToZitharaApi($data, $accessToken)
    {
        try {
            $this->curl = new Curl();

            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('Authorization', $accessToken);

            $requestBody = $this->jsonHelper->jsonEncode($data);

            //$this->logger->info('CartProcessor: Sending request to Zithara API', [
             //   'cart_id' => $data['cart']['platform_cart_id'] ?? 'unknown',
              //  'endpoint' => self::$this->apiEndpoints->getCartEndpoint(),
             //   'request_body' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            //]);

            $this->curl->post($this->apiEndpoints->getCartEndpoint(), $requestBody);

            $responseStatus = $this->curl->getStatus();
            $responseBody = $this->curl->getBody();

            // Log the complete response
            $this->logger->info('CartProcessor: Received response from Zithara API', [
                'status' => $responseStatus,
                'response_body' => $responseBody
            ]);

            // Decode the outer JSON
            $outerJson = json_decode($responseBody, true);
            $outerStatus = $outerJson['status'] ?? $responseStatus;

            if ($outerStatus === 201 || $responseStatus === 200) {
                $this->logger->info('CartProcessor: Successfully sent cart to Zithara API', [
                    'cart_id' => $data['cart']['platform_cart_id'] ?? 'unknown',
                    'response_status' => $outerStatus
                ]);
                return;
            }

            throw new \Exception("API request failed. Status: {$responseStatus}, Response: {$responseBody}");

        } catch (\Exception $e) {
            $this->logger->error('CartProcessor: Error sending data to Zithara API', [
                'error' => $e->getMessage()
               // 'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}