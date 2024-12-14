<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class PaymentFraud implements ObserverInterface
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
            $payment = $observer->getEvent()->getPayment();
            
            // Defensive check for payment object
            if (!$payment || !is_object($payment)) {
                $this->logger->error('PaymentFraud: Invalid payment object.');
                return;
            }

            $order = $payment->getOrder();
            
            // Defensive check for order object
            if (!$order || !is_object($order)) {
                $this->logger->error('PaymentFraud: Invalid order object.');
                return;
            }

            // Get payment data with defensive checks and remove sensitive information
            $paymentData = array_diff_key(
                $payment->getData() ?: [],
                array_flip(['cc_number', 'cc_cid'])
            );

            // Get fraud details with defensive check
            $fraudDetails = $payment->getFraudDetails() ?: [];

            // Get additional information with defensive check
            $additionalInfo = $payment->getAdditionalInformation() ?: [];

            // Prepare webhook data with defensive checks
            $webhookData = [
                'order_id' => $order->getId() ?? '',
                'payment' => $paymentData,
                'fraud_details' => $fraudDetails,
                'additional_information' => $additionalInfo
            ];

            $this->webhookHelper->sendWebhook('payment_fraud', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('PaymentFraud Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}