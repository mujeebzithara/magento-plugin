<?php
namespace Zithara\Webhook\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Zithara\Webhook\Model\ConfigFactory;
use Psr\Log\LoggerInterface;

class Data extends AbstractHelper
{
    protected $publisher;
    protected $serializer;
    protected $configFactory;
    protected $logger;

    public function __construct(
        Context $context,
        PublisherInterface $publisher,
        SerializerInterface $serializer,
        ConfigFactory $configFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->publisher = $publisher;
        $this->serializer = $serializer;
        $this->configFactory = $configFactory;
        $this->logger = $logger;
    }

    public function sendWebhook($eventType, $data)
    {
        try {
            // Defensive check for event type
            if (empty($eventType)) {
                $this->logger->error('WebhookHelper: Empty event type provided.');
                return false;
            }

            // Defensive check for data
            if (!is_array($data)) {
                $this->logger->error('WebhookHelper: Invalid data format. Array expected.', [
                    'event_type' => $eventType,
                    'data_type' => gettype($data)
                ]);
                return false;
            }

            $this->logger->info('WebhookHelper: Attempting to send webhook', [
                'event_type' => $eventType,
                'data_keys' => array_keys($data)
            ]);

            // Get active configuration with defensive checks
            try {
                $config = $this->configFactory->create()->getCollection()
                    ->addFieldToFilter('is_active', 1)
                    ->getFirstItem();
            } catch (\Exception $e) {
                $this->logger->error('WebhookHelper: Error loading webhook configuration', [
                    'error' => $e->getMessage()
                ]);
                return false;
            }

            // Check if configuration exists and is valid
            if (!$config->getId()) {
                $this->logger->warning('WebhookHelper: No active webhook configuration found');
                return false;
            }

            // Defensive check for event types configuration
            $eventTypes = $config->getEventTypes();
            if (empty($eventTypes)) {
                $this->logger->warning('WebhookHelper: No event types configured');
                return false;
            }

            $eventTypesArray = explode(',', $eventTypes);
            if (!in_array($eventType, $eventTypesArray)) {
                $this->logger->info('WebhookHelper: Event type not configured for webhooks', [
                    'event_type' => $eventType,
                    'configured_events' => $eventTypesArray
                ]);
                return false;
            }

            try {
                $this->logger->info('WebhookHelper: Publishing webhook message to queue', [
                    'event_type' => $eventType,
                    'config_id' => $config->getId()
                ]);

                // Prepare message with defensive checks
                $message = [
                    'config_id' => $config->getId(),
                    'event_type' => $eventType,
                    'payload' => $data,
                    'timestamp' => time()
                ];

                // Serialize message with error handling
                try {
                    $serializedMessage = $this->serializer->serialize($message);
                } catch (\Exception $e) {
                    $this->logger->error('WebhookHelper: Error serializing message', [
                        'error' => $e->getMessage(),
                        'event_type' => $eventType
                    ]);
                    return false;
                }

                // Publish message to queue
                $this->publisher->publish('zithara.webhook.events', $serializedMessage);
                
                $this->logger->info('WebhookHelper: Successfully published webhook message to queue', [
                    'event_type' => $eventType,
                    'config_id' => $config->getId()
                ]);
                
                return true;

            } catch (\Exception $e) {
                $this->logger->error('WebhookHelper: Queue Error', [
                    'event_type' => $eventType,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return false;
            }

        } catch (\Exception $e) {
            $this->logger->error('WebhookHelper: Unexpected Error', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
}