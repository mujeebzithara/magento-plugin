<?php
namespace Zithara\Webhook\Model\Queue;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Zithara\Webhook\Model\ConfigFactory;
use Psr\Log\LoggerInterface;
use Magento\Framework\DataObject;
use Zithara\Webhook\Model\Config\ApiEndpoints;

class OrderProcessor
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
        LoggerInterface $logger,
        ApiEndpoints $apiEndpoints
    ) {
        $this->curl = $curl;
        $this->jsonHelper = $jsonHelper;
        $this->logger = $logger;
        $this->configFactory = $configFactory;
        $this->apiEndpoints = $apiEndpoints;
    }

    public function process($message)
    {
        try {
            if (empty($message)) {
                $this->logger->error('OrderProcessor: Empty message received');
                return;
            }

            $this->logger->info('OrderProcessor: Processing order message');

            try {
                $data = $this->jsonHelper->jsonDecode($message, true);
            } catch (\Exception $e) {
                $this->logger->error('OrderProcessor: Invalid JSON message format', [
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

            // Check if this is an update
            $isUpdate = !empty($data['is_update']);

            // Transform order data
            $zitharaOrderData = $this->transformOrderData($data);

            // Send to appropriate endpoint based on operation type
            $this->sendToZitharaApi($zitharaOrderData, $accessToken, $isUpdate);

        } catch (\Exception $e) {
            $this->logger->error('OrderProcessor: Unexpected error', [
                'error' => $e->getMessage()
                //'trace' => $e->getTraceAsString()
            ]);
        }
    }

    protected function transformOrderData($data)
    {
        try {
            $order = $data['order'] ?? [];
            $items = $data['items'] ?? [];
            $payment = $data['payment'] ?? [];
            $billingAddress = $data['billing_address'] ?? [];
            $shippingAddress = $data['shipping_address'] ?? [];

            // Transform customer data
            $customer = [
                'platform_customer_id' => !empty($order['customer_id']) ? (string)$order['customer_id'] : '',
                'phone_number' => $this->extractPhoneNumber($billingAddress),
                'first_name' => $order['customer_firstname'] ?? '',
                'last_name' => $order['customer_lastname'] ?? '',
                'name' => trim(($order['customer_firstname'] ?? '') . ' ' . ($order['customer_lastname'] ?? '')),
                'whatsapp_phone_number' => $this->extractPhoneNumber($billingAddress),
                'email' => $order['customer_email'] ?? false,
                'custom_attributes' => new \stdClass()
            ];

            // Transform order line items
            $orderLineItems = [];
            foreach ($items as $item) {
                if (!isset($item['item_id']) || empty($item['item_id'])) {
                    $this->logger->warning('OrderProcessor: Missing item_id for order item', [
                        'order_id' => $order['increment_id'] ?? 'unknown',
                        'product_id' => $item['product_id'] ?? 'unknown'
                    ]);
                    continue;
                }

                $orderLineItems[] = [
                    'status' => '',
                    'platform_line_item_id' => (string)$item['item_id'],
                    'price' => (float)($item['price'] ?? 0),
                    'taxable' => ($item['tax_amount'] ?? 0) > 0,
                    'discount_amount' => (float)($item['discount_amount'] ?? 0),
                    'sku' => $item['sku'] ?? '',
                    'product_id' => $item['product_id'] ?? '',
                    'gift_card' => false,
                    'custom_attributes' => new \stdClass(),
                    'product_name' => $item['name'] ?? '',
                    'quantity' => (float)($item['qty_ordered'] ?? 1)
                ];
            }

            if (empty($orderLineItems)) {
                throw new \Exception('No valid order items found');
            }

            $paymentState = $this->mapOrderStatus($order['status'] ?? '');
            $orderState = $this->mapOrderStatus($order['state'] ?? '');
            // Transform order data
            $orderData = [
                'status' => $orderState,
                'current_subtotal_price' => (float)($order['subtotal'] ?? 0),
                'billing_address' => $this->transformAddress($billingAddress),
                'payment_status' => $this->mapPaymentStatus($payment),
                'currency' => $order['currency_code'] ?? 'INR',
                'platform_order_id' => $order['increment_id'] ?? '',
                'name' => $order['increment_id'] ?? '',
                'current_total_tax' => (float)($order['tax_amount'] ?? 0),
                'created_at' => $order['created_at'] ?? date('Y-m-d H:i:s'),
                'custom_attributes' => (object)[
                    'cashback_amount' => 0.0,
                    'cashback_redeemption_order' => false,
                    'pos_reference' => $order['increment_id'] ?? '',
                    'card_order' => false,
                    'cashback_redeemption_amount' => 0.0,
                    'card_no' => false
                ],
                'note' => false,
                'fulfillment_status' => $orderState,
                'current_total_discounts' => (float)($order['discount_amount'] ?? 0),
                'current_total_price' => (float)($order['grand_total'] ?? 0),
                'shipping_address' => $this->transformAddress($shippingAddress)
            ];

            // For updates, we only need to send the order data
            if (!empty($data['is_update'])) {
                return $orderData;
            }

            // Transform transactions
            $transactions = [[
                'status' => $paymentState,
                'transaction_final_amount' => (float)($payment['amount_paid'] ?? $order['grand_total'] ?? 0),
                'custom_attributes' => new \stdClass(),
                'transaction_mode' => $payment['method'] ?? 'Unknown',
                'created_at' => $order['created_at'] ?? date('Y-m-d H:i:s'),
                'platform_transaction_id' => $payment['last_trans_id'] ?? $order['increment_id'] ?? '',
                'transaction_original_amount' => (float)($payment['amount_authorized'] ?? $order['grand_total'] ?? 0),
                'financial_status' => '',
                'transaction_type' => ''
            ]];

            $this->logger->info('OrderProcessor: Transformed order data', [
                'order_id' => $order['increment_id'] ?? 'unknown',
                'item_count' => count($orderLineItems),
                'is_update' => !empty($data['is_update'])
            ]);

            // For new orders, return the complete structure
            return [
                'customer' => $customer,
                'order_line_item' => $orderLineItems,
                'order' => $orderData,
                'transactions' => $transactions
            ];

        } catch (\Exception $e) {
            $this->logger->error('OrderProcessor: Error transforming order data', [
                'error' => $e->getMessage()
                //'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function extractPhoneNumber($address)
    {
        $telephone = $address['telephone'] ?? '';
        if (empty($telephone)) {
            return '';
        }

        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $telephone);

        // Ensure it starts with '91'
        if (substr($phone, 0, 2) !== '91') {
            $phone = '91' . $phone;
        }

        return $phone;
    }

    protected function transformAddress($address)
    {
        if (empty($address)) {
            return new \stdClass();
        }

        return (object)[
            'address1' => $address['street'] ?? '',
            'city' => $address['city'] ?? '',
            'province' => $address['region'] ?? '',
            'zip' => $address['postcode'] ?? '',
            'country' => $address['country_id'] ?? '',
            'phone' => $address['telephone'] ?? ''
        ];
    }

    protected function mapOrderStatus($status)
    {
        $statusMap = [
            'pending' => 'pending',
            'processing' => 'processing',
            'complete' => 'completed',
            'canceled' => 'cancelled'
        ];

        return $statusMap[strtolower($status)] ?? $status;
    }

    protected function mapPaymentStatus($payment)
    {
        if (empty($payment)) {
            return '';
        }

        if (isset($payment['amount_paid']) && $payment['amount_paid'] > 0) {
            return 'paid';
        }

        return '';
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

            $tokenCurl->get(self::TOKEN_ENDPOINT);

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
            $this->logger->error('OrderProcessor: Error getting access token', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

  /**  protected function sendToZitharaApi($data, $accessToken, $isUpdate = false)
    {
        try {
            $this->curl = new Curl();

            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('Authorization', $accessToken);
            
            
            $endpoint = $isUpdate ? self::ZITHARA_UPDATE_ORDER_ENDPOINT : self::ZITHARA_ORDER_ENDPOINT;

            $this->logger->info('OrderProcessor: Sending request to Zithara API', [
                'order_id' => $isUpdate ? ($data['platform_order_id'] ?? 'unknown') : ($data['order']['platform_order_id'] ?? 'unknown'),
                'is_update' => $isUpdate,
                'endpoint' => $endpoint
            ]);

            $requestBody = $this->jsonHelper->jsonEncode($data);

            if ($isUpdate) {
                $this->logger->info('Sending PATCH request to: ' . $endpoint);
                $this->logger->info('Request Body: ' . $requestBody);
                $this->curl->setOption(CURLOPT_VERBOSE, true);
                $this->curl->patch($endpoint, $requestBody);
            } else {
                $this->logger->info('Sending POST request to: ' . $endpoint);
                $this->logger->info('Request Body: ' . $requestBody);
                $this->curl->setOption(CURLOPT_VERBOSE, true);
                $this->curl->post($endpoint, $requestBody);
            }


            $responseStatus = $this->curl->getStatus();
            $responseBody = $this->curl->getBody();
            
            $this->logger->info('Response Status: ' . $responseStatus);
            $this->logger->info('Response Body: ' . $responseBody);

            // Check if the response is successful or not
            if ($this->curl->getStatus() == 200) {
                $this->logger->info("Success: ");
            } else {
                $this->logger->error("Error: " . $this->curl->getStatus() . " Response: ");
            }

            $responseStatus = $this->curl->getStatus();
            $responseBody = $this->curl->getBody();

            // Decode the outer JSON
            $outerJson = json_decode($responseBody, true);
            $outerStatus = $outerJson['status'] ?? $responseStatus;

            if ($outerStatus === 201 || $responseStatus === 200) {
                $this->logger->info('OrderProcessor: Successfully sent order to Zithara API', [
                    'order_id' => $isUpdate ? ($data['platform_order_id'] ?? 'unknown') : ($data['order']['platform_order_id'] ?? 'unknown'),
                    'is_update' => $isUpdate,
                    'response_status' => $outerStatus
                ]);
                return;
            }

            throw new \Exception("API request failed. Status: {$responseStatus}, Response: {$responseBody}");

        } catch (\Exception $e) {
            $this->logger->error('OrderProcessor: Error sending data to Zithara API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'is_update' => $isUpdate
            ]);
            throw $e;
        }
    } **/
    
    protected function sendToZitharaApi($data, $accessToken, $isUpdate = false)
    {
        try {
            // Initialize cURL
            $ch = curl_init();

            $endpoint = $isUpdate ? $this->apiEndpoints->getOrderUpdateEndpoint() : $this->apiEndpoints->getOrderEndpoint();

            // Set cURL options
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: ' . $accessToken
            ]);

            $requestBody = $this->jsonHelper->jsonEncode($data);

            if ($isUpdate) {
                // Configure for PATCH request
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                $this->logger->info('OrderProcessor: Sending PATCH request', [
                    'endpoint' => $endpoint,
                    'order_id' => $data['platform_order_id'] ?? 'unknown'
                ]);
            } else {
                // Configure for POST request
                curl_setopt($ch, CURLOPT_POST, true);
                $this->logger->info('OrderProcessor: Sending POST request', [
                    'endpoint' => $endpoint,
                    'order_id' => $data['order']['platform_order_id'] ?? 'unknown'
                ]);
            }

            // Set request body
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);

            // Log request details
            $this->logger->info('OrderProcessor: Request details', [
                'method' => $isUpdate ? 'PATCH' : 'POST',
                'endpoint' => $endpoint,
                'request_body' => $requestBody
            ]);

            // Execute request
            $responseBody = curl_exec($ch);
            $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            // Log response
            $this->logger->info('OrderProcessor: Response details', [
                'status' => $responseStatus,
                'body' => $responseBody
            ]);

            // Close cURL session
            curl_close($ch);

            // Handle response
            if ($responseStatus === 200 || $responseStatus === 201) {
                $this->logger->info('OrderProcessor: Successfully sent order to Zithara API', [
                    'order_id' => $isUpdate ? ($data['platform_order_id'] ?? 'unknown') : ($data['order']['platform_order_id'] ?? 'unknown'),
                    'is_update' => $isUpdate,
                    'response_status' => $responseStatus
                ]);
                return;
            }

            throw new \Exception("API request failed. Status: {$responseStatus}, Response: {$responseBody}");

        } catch (\Exception $e) {
            $this->logger->error('OrderProcessor: Error sending data to Zithara API', [
                'error' => $e->getMessage(),
                //'trace' => $e->getTraceAsString(),
                'is_update' => $isUpdate
            ]);
            throw $e;
        }
    }
}