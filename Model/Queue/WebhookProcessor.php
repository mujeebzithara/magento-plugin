<?php
namespace Zithara\Webhook\Model\Queue;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Zithara\Webhook\Model\ConfigFactory;
use Psr\Log\LoggerInterface;
use Magento\Framework\DataObject;
use Zithara\Webhook\Model\Config\ApiEndpoints;

class WebhookProcessor
{
    const MAX_RETRIES = 3;
    const RETRY_DELAY = 5; // seconds

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

    public function process($message)
    {
        try {
            if (empty($message)) {
                $this->logger->error('WebhookProcessor: Empty message received.');
                return;
            }

            $this->logger->info('WebhookProcessor: Processing webhook message');

            try {
                $data = $this->jsonHelper->jsonDecode($message, true);
            } catch (\Exception $e) {
                $this->logger->error('WebhookProcessor: Invalid JSON message format', [
                    'error' => $e->getMessage(),
                    'message' => substr($message, 0, 255)
                ]);
                return;
            }

            // Convert custom attributes array to object if present
            if (isset($data['payload']['custom_attributes']) && is_array($data['payload']['custom_attributes'])) {
                $data['payload']['custom_attributes'] = new DataObject($data['payload']['custom_attributes']);
            }

            if (!$this->validateMessageData($data)) {
                return;
            }

            try {
                $config = $this->configFactory->create()->load($data['config_id']);
            } catch (\Exception $e) {
                $this->logger->error('WebhookProcessor: Error loading configuration', [
                    'error' => $e->getMessage(),
                    'config_id' => $data['config_id'] ?? null
                ]);
                return;
            }

            if (!$this->validateConfig($config)) {
                return;
            }

            $accessToken = $this->getAccessTokenWithRetry($config);
            if (!$accessToken) {
                return;
            }

            $this->sendWebhookWithRetry($config, $data, $accessToken);

        } catch (\Exception $e) {
            $this->logger->error('WebhookProcessor: Unexpected error', [
                'error' => $e->getMessage(),
                //'trace' => $e->getTraceAsString()
            ]);
        }
    }

    protected function validateMessageData($data)
    {
        if (!is_array($data)) {
            $this->logger->error('WebhookProcessor: Message data must be an array');
            return false;
        }

        $requiredFields = ['config_id', 'event_type', 'payload'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $this->logger->error("WebhookProcessor: Missing required field: {$field}");
                return false;
            }
        }

        if (!is_array($data['payload'])) {
            $this->logger->error('WebhookProcessor: Payload must be an array');
            return false;
        }

        return true;
    }

    protected function validateConfig($config)
    {
        if (!$config->getId()) {
            $this->logger->error('WebhookProcessor: Configuration not found');
            return false;
        }

        if (!$config->getIsActive()) {
            $this->logger->error('WebhookProcessor: Configuration is inactive');
            return false;
        }

        if (!$config->getWebhookUrl()) {
            $this->logger->error('WebhookProcessor: Webhook URL is not configured');
            return false;
        }

        if (!$config->getClientId() || !$config->getClientSecret()) {
            $this->logger->error('WebhookProcessor: Missing client credentials');
            return false;
        }

        return true;
    }

    protected function getAccessTokenWithRetry($config)
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $token = $this->getAccessToken($config);
                if ($token) {
                    return $token;
                }
            } catch (\Exception $e) {
                $this->logger->warning("WebhookProcessor: Token attempt {$attempt} failed", [
                    'error' => $e->getMessage()
                ]);
                if ($attempt < self::MAX_RETRIES) {
                    sleep(self::RETRY_DELAY);
                }
            }
        }

        $this->logger->error('WebhookProcessor: Failed to obtain access token after retries');
        return null;
    }

    protected function sendWebhookWithRetry($config, $data, $accessToken)
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $webhookCurl = new Curl();

                $headers = [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => $accessToken,
                    'X-Webhook-Event' => $data['event_type']
                ];

                foreach ($headers as $name => $value) {
                    if (!empty($value)) {
                        $webhookCurl->addHeader($name, $value);
                    }
                }

                try {
                    $payload = $this->jsonHelper->jsonEncode($data['payload']);
                } catch (\Exception $e) {
                    $this->logger->error('WebhookProcessor: Error encoding payload', [
                        'error' => $e->getMessage()
                    ]);
                    return;
                }

                $webhookCurl->post($config->getWebhookUrl(), $payload);

                $responseStatus = $webhookCurl->getStatus();
                $responseBody = $webhookCurl->getBody();

                if ($responseStatus === 200) {
                    $this->logger->info('WebhookProcessor: Webhook sent successfully', [
                        'event_type' => $data['event_type'],
                        'status' => $responseStatus
                    ]);
                    return;
                }

                $this->logger->error('WebhookProcessor: Webhook request failed', [
                    'attempt' => $attempt,
                    'status' => $responseStatus,
                    'response' => substr($responseBody, 0, 255),
                    'event_type' => $data['event_type']
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    sleep(self::RETRY_DELAY);
                }

            } catch (\Exception $e) {
                $this->logger->error("WebhookProcessor: Webhook attempt {$attempt} failed", [
                    'error' => $e->getMessage(),
                    //'trace' => $e->getTraceAsString()
                ]);
                if ($attempt < self::MAX_RETRIES) {
                    sleep(self::RETRY_DELAY);
                }
            }
        }
    }

    protected function getAccessToken($config)
    {
        if ($config->getAccessToken() && $config->getTokenExpiry() &&
            strtotime($config->getTokenExpiry()) > time()) {
            return $config->getAccessToken();
        }

        $tokenCurl = new Curl();

        // Log request details for debugging
        $this->logger->info('WebhookProcessor: Token request details', [
            'client_id' => $config->getClientId(),
            'endpoint' => $this->apiEndpoints->getTokenEndpoint()
        ]);

        // Set headers as per API requirements
        $tokenCurl->addHeader('Content-Type', 'application/json');
        $tokenCurl->addHeader('Accept', 'application/json');
        $tokenCurl->addHeader('client_id', $config->getClientId());
        $tokenCurl->addHeader('client_secret', $config->getClientSecret());

        $tokenCurl->get($this->apiEndpoints->getTokenEndpoint());

        $responseStatus = $tokenCurl->getStatus();
        $responseBody = $tokenCurl->getBody();

        // Log response for debugging
        $this->logger->info('WebhookProcessor: Token response details', [
            'status' => $responseStatus,
            'body' => $responseBody
        ]);

        if ($responseStatus !== 200) {
            $this->logger->error('WebhookProcessor: Token request failed', [
                'status' => $responseStatus,
                'response' => substr($responseBody, 0, 255)
            ]);
            return null;
        }

        try {
            $response = $this->jsonHelper->jsonDecode($responseBody, true);
        } catch (\Exception $e) {
            $this->logger->error('WebhookProcessor: Invalid token response format', [
                'error' => $e->getMessage()
            ]);
            return null;
        }

        if (!isset($response['access_token'])) {
            $this->logger->error('WebhookProcessor: Access token not found in response');
            return null;
        }

        try {
            $config->setAccessToken($response['access_token']);
            $expiresIn = isset($response['expires_in']) && is_numeric($response['expires_in'])
                ? (int)$response['expires_in']
                : 3600;
            $config->setTokenExpiry(date('Y-m-d H:i:s', time() + $expiresIn));
            $config->save();
        } catch (\Exception $e) {
            $this->logger->error('WebhookProcessor: Error saving token configuration', [
                'error' => $e->getMessage()
            ]);
            return null;
        }

        return $response['access_token'];
    }
}
