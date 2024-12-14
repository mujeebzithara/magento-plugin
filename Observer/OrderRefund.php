<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class OrderRefund implements ObserverInterface
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
            $creditmemo = $observer->getEvent()->getCreditmemo();
            
            // Defensive check for creditmemo object
            if (!$creditmemo || !is_object($creditmemo)) {
                $this->logger->error('OrderRefund: Invalid creditmemo object.');
                return;
            }

            $order = $creditmemo->getOrder();
            
            // Defensive check for order object
            if (!$order || !is_object($order)) {
                $this->logger->error('OrderRefund: Invalid order object.');
                return;
            }

            // Get order and creditmemo data with defensive checks
            $orderData = $order->getData() ?: [];
            $creditmemoData = $creditmemo->getData() ?: [];

            // Additional defensive checks for critical data
            $webhookData = [
                'order' => array_merge($orderData, [
                    'order_id' => $order->getId() ?? '',
                    'increment_id' => $order->getIncrementId() ?? '',
                    'status' => $order->getStatus() ?? '',
                    'state' => $order->getState() ?? ''
                ]),
                'creditmemo' => array_merge($creditmemoData, [
                    'creditmemo_id' => $creditmemo->getId() ?? '',
                    'increment_id' => $creditmemo->getIncrementId() ?? '',
                    'grand_total' => $creditmemo->getGrandTotal() ?? 0,
                    'created_at' => $creditmemo->getCreatedAt() ?? ''
                ])
            ];

            $this->webhookHelper->sendWebhook('order_refund', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('OrderRefund Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}