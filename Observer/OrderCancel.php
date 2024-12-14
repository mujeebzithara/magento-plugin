<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class OrderCancel implements ObserverInterface
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
            $order = $observer->getEvent()->getOrder();
            
            // Defensive check for order object
            if (!$order || !is_object($order)) {
                $this->logger->error('OrderCancel: Invalid order object.');
                return;
            }

            // Get order data with defensive check
            $orderData = $order->getData() ?: [];

            // Additional defensive checks for critical order data
            $webhookData = array_merge($orderData, [
                'order_id' => $order->getId() ?? '',
                'increment_id' => $order->getIncrementId() ?? '',
                'status' => $order->getStatus() ?? '',
                'state' => $order->getState() ?? '',
                'cancel_date' => $order->getUpdatedAt() ?? ''
            ]);

            $this->webhookHelper->sendWebhook('cancel_order', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('OrderCancel Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}