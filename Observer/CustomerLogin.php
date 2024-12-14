<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

class CustomerLogin implements ObserverInterface
{
    protected $webhookHelper;
    protected $dateTime;
    protected $logger;

    public function __construct(
        WebhookHelper $webhookHelper,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        $this->webhookHelper = $webhookHelper;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        try {
            $customer = $observer->getEvent()->getCustomer();
            
            // Defensive check for customer object
            if (!$customer || !is_object($customer)) {
                $this->logger->error('CustomerLogin: Invalid customer object.');
                return;
            }

            // Defensive check for required customer ID
            if (!$customer->getId()) {
                $this->logger->error('CustomerLogin: Customer ID is missing.');
                return;
            }

            // Prepare webhook data with defensive checks
            $webhookData = [
                'customer_id' => $customer->getId(),
                'email' => $customer->getEmail() ?? '',
                'firstname' => $customer->getFirstname() ?? '',
                'lastname' => $customer->getLastname() ?? '',
                'group_id' => $customer->getGroupId() ?? '',
                'store_id' => $customer->getStoreId() ?? '',
                'website_id' => $customer->getWebsiteId() ?? '',
                'created_at' => $customer->getCreatedAt() ?? '',
                'login_at' => $this->dateTime->gmtDate('Y-m-d H:i:s')
            ];

            $this->webhookHelper->sendWebhook('customer_login', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('CustomerLogin Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}