<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

class CustomerLogout implements ObserverInterface
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
                $this->logger->error('CustomerLogout: Invalid customer object.');
                return;
            }

            // Defensive check for required customer ID
            if (!$customer->getId()) {
                $this->logger->error('CustomerLogout: Customer ID is missing.');
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
                'logout_at' => $this->dateTime->gmtDate('Y-m-d H:i:s')
            ];

            $this->webhookHelper->sendWebhook('customer_logout', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('CustomerLogout Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}