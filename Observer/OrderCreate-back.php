<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class OrderCreate implements ObserverInterface
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
                $this->logger->error('OrderCreate: Invalid order object.');
                return;
            }

            // Get order data with defensive check
            $orderData = $order->getData() ?: [];

            // Additional defensive checks for critical order data
            $webhookData = array_merge($orderData, [
                'order_id' => $order->getId() ?? '',
                'increment_id' => $order->getIncrementId() ?? '',
                'customer_id' => $order->getCustomerId() ?? '',
                'customer_email' => $order->getCustomerEmail() ?? '',
                'status' => $order->getStatus() ?? '',
                'state' => $order->getState() ?? '',
                'created_at' => $order->getCreatedAt() ?? '',
                'total' => $order->getGrandTotal() ?? 0,
                'currency' => $order->getOrderCurrencyCode() ?? ''
            ]);

            $this->webhookHelper->sendWebhook('create_order', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('OrderCreate Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}