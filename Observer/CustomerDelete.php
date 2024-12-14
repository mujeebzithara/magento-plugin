<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class CustomerDelete implements ObserverInterface
{
    protected $webhookHelper;
    protected $logger;

    public function __construct(
        WebhookHelper $webhookHelper,
        LoggerInterface $logger
    ) {
        $this->webhookHelper = $webhookHelper;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        try {
            $customer = $observer->getEvent()->getCustomer();

            // Defensive check for customer object
            if (!$customer || !is_object($customer)) {
                $this->logger->error('CustomerDelete: Invalid customer object.');
                return;
            }

            // Get customer data with defensive checks
            $customerData = $customer->getData();
            
            // Remove sensitive data
            if (isset($customerData['password_hash'])) {
                unset($customerData['password_hash']);
            }

            // Additional defensive checks for critical customer data
            $webhookData = [
                'customer' => array_merge($customerData, [
                    'id' => $customer->getId() ?? '',
                    'email' => $customer->getEmail() ?? '',
                    'firstname' => $customer->getFirstname() ?? '',
                    'lastname' => $customer->getLastname() ?? '',
                    'group_id' => $customer->getGroupId() ?? '',
                    'store_id' => $customer->getStoreId() ?? '',
                    'website_id' => $customer->getWebsiteId() ?? '',
                    'created_at' => $customer->getCreatedAt() ?? '',
                    'updated_at' => $customer->getUpdatedAt() ?? ''
                ])
            ];

            $this->webhookHelper->sendWebhook('delete_customer', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('CustomerDelete Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}