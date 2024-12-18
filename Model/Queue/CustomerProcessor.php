<?php
namespace Zithara\Webhook\Model\Queue;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Zithara\Webhook\Model\ConfigFactory;
use Psr\Log\LoggerInterface;
use Magento\Framework\DataObject;
use Zithara\Webhook\Model\Config\ApiEndpoints;

class CustomerProcessor
{
    const MAX_RETRIES = 1;
    const RETRY_DELAY = 1; // seconds

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
        $this->configFactory = $configFactory;
        $this->logger = $logger;
        $this->apiEndpoints = $apiEndpoints;
    }

    /**
     * Processes a customer message by validating required fields, converting custom
     * attributes, loading webhook configuration, obtaining a fresh access token,
     * and sending the customer data to the Zithara API.
     *
     * @param string $message JSON string containing customer data
     * @return void
     */
    public function process($message)
    {
        try {
            if (empty($message)) {
                $this->logger->error('CustomerProcessor: Empty message received');
                return;
            }

            $this->logger->info('CustomerProcessor: Processing customer message');

            try {
                $data = $this->jsonHelper->jsonDecode($message, true);
            } catch (\Exception $e) {
                $this->logger->error('CustomerProcessor: Invalid JSON message format', [
                    'error' => $e->getMessage(),
                    'message' => substr($message, 0, 255)
                ]);
                return;
            }

            // Validate required fields
            if (!$this->validateCustomerData($data)) {
                return;
            }

            // Convert custom attributes array to object
            if (isset($data['custom_attributes']) && is_array($data['custom_attributes'])) {
                $data['custom_attributes'] = new DataObject($data['custom_attributes']);
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

            $this->sendToZitharaApi($data, $config, $accessToken);

        } catch (\Exception $e) {
            $this->logger->error('CustomerProcessor: Unexpected error', [
                'error' => $e->getMessage()
                //'trace' => $e->getTraceAsString()
            ]);
        }
    }

    protected function validateCustomerData($data)
    {
        $requiredFields = ['platform_customer_id', 'email'];

        // Phone number is mandatory for updates
        if (!empty($data['is_update'])) {
            $requiredFields[] = 'phone_number';
        }

        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $this->logger->error('CustomerProcessor: Missing required field', [
                    'field' => $field,
                    'customer_id' => $data['platform_customer_id'] ?? 'unknown'
                ]);
                return false;
            }
        }

        return true;
    }

    protected function getAccessToken($config)
    {
        try {
            // Check if we have a valid token
            if ($config->getAccessToken() && $config->getTokenExpiry() &&
                strtotime($config->getTokenExpiry()) > time()) {
                return $config->getAccessToken();
            }

            // Create new curl instance for token request
            $tokenCurl = new Curl();

            // Log request details
            $this->logger->info('CustomerProcessor: Token request details', [
                'client_id' => $config->getClientId(),
                'endpoint' => $this->apiEndpoints->getTokenEndpoint()
            ]);

            // Set headers
            $tokenCurl->addHeader('Content-Type', 'application/json');
            $tokenCurl->addHeader('Accept', 'application/json');
            $tokenCurl->addHeader('client_id', $config->getClientId());
            $tokenCurl->addHeader('client_secret', $config->getClientSecret());

            // Make token request
            $tokenCurl->get($this->apiEndpoints->getTokenEndpoint());

            $responseStatus = $tokenCurl->getStatus();
            $responseBody = $tokenCurl->getBody();

            // Log response
            $this->logger->info('CustomerProcessor: Token response details', [
                'status' => $responseStatus,
                'body' => $responseBody
            ]);

            if ($responseStatus !== 200) {
                throw new \Exception("Token request failed with status {$responseStatus}: {$responseBody}");
            }

            try {
                $response = $this->jsonHelper->jsonDecode($responseBody, true);
            } catch (\Exception $e) {
                throw new \Exception("Invalid token response format: " . $e->getMessage());
            }

            if (!isset($response['access_token'])) {
                throw new \Exception('Access token not found in response: ' . $responseBody);
            }

            // Update config with new token
            $config->setAccessToken($response['access_token']);
            $expiresIn = isset($response['expires_in']) ? (int)$response['expires_in'] : 3600;
            $config->setTokenExpiry(date('Y-m-d H:i:s', time() + $expiresIn));
            $config->save();

            $this->logger->info('CustomerProcessor: New access token obtained and saved');

            return $response['access_token'];

        } catch (\Exception $e) {
            $this->logger->error('CustomerProcessor: Error getting access token', [
                'error' => $e->getMessage()
                //'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    protected function sendToZitharaApi($data, $config, $accessToken, $attempt = 1)
    {
        try {
            // Reset CURL object for fresh request
            $this->curl = new Curl();

            // Set headers
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('Authorization', $accessToken);

            // Log the request
            $this->logger->info('CustomerProcessor: Sending request to Zithara API', [
                'endpoint' => $this->apiEndpoints->getCustomerEndpoint(),
                'attempt' => $attempt,
                'customer_id' => $data['platform_customer_id'] ?? 'unknown',
                'is_update' => !empty($data['is_update'])
            ]);

            // Remove internal flags before sending
            unset($data['is_update']);

            // Prepare request body
            $requestBody = $this->jsonHelper->jsonEncode($data);

            // Send request
            $this->curl->post($this->apiEndpoints->getCustomerEndpoint(), $requestBody);

            $responseStatus = $this->curl->getStatus();
            $responseBody = $this->curl->getBody();

            // Log the response
            $this->logger->info('CustomerProcessor: Received response from Zithara API', [
                'status' => $responseStatus,
                'customer_id' => $data['platform_customer_id'] ?? 'unknown',
                'attempt' => $attempt
            ]);

            // Handle unauthorized/forbidden responses
            if ($responseStatus === 401 || $responseStatus === 403) {
                if ($attempt >= self::MAX_RETRIES) {
                    throw new \Exception("Authentication failed after {$attempt} attempts");
                }

                $this->logger->info('CustomerProcessor: Token expired, getting new token');
                $newToken = $this->getAccessToken($config);
                if (!$newToken) {
                    throw new \Exception('Failed to obtain new access token');
                }

                // Retry with new token
                return $this->sendToZitharaApi($data, $config, $newToken, $attempt + 1);
            }

            if ($responseStatus === 200) {
                $this->logger->info('CustomerProcessor: Successfully sent customer data to Zithara API', [
                    'customer_id' => $data['platform_customer_id'] ?? 'unknown'
                ]);
                return;
            }

            // Handle other errors
            if ($attempt < self::MAX_RETRIES) {
                $this->logger->warning('CustomerProcessor: Request failed, retrying', [
                    'attempt' => $attempt,
                    'status' => $responseStatus,
                    'response' => $responseBody
                ]);

                sleep(self::RETRY_DELAY * $attempt);
                return $this->sendToZitharaApi($data, $config, $accessToken, $attempt + 1);
            }

            throw new \Exception("API request failed after {$attempt} attempts. Last response: {$responseBody}");

        } catch (\Exception $e) {
            $this->logger->error('CustomerProcessor: Error sending data to Zithara API', [
                'error' => $e->getMessage(),
                //'trace' => $e->getTraceAsString(),
                'customer_id' => $data['platform_customer_id'] ?? 'unknown',
                'attempt' => $attempt
            ]);
            throw $e;
        }
    }
}
