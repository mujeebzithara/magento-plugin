<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class PaymentCapture implements ObserverInterface
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
                $this->logger->error('PaymentCapture: Invalid payment object.');
                return;
            }

            $order = $payment->getOrder();
            
            // Defensive check for order object
            if (!$order || !is_object($order)) {
                $this->logger->error('PaymentCapture: Invalid order object.');
                return;
            }

            // Get amount with defensive check
            $amount = $observer->getEvent()->getAmount();
            if (!is_numeric($amount)) {
                $this->logger->error('PaymentCapture: Invalid amount value.');
                return;
            }

            // Get payment data with defensive checks and remove sensitive information
            $paymentData = array_diff_key(
                $payment->getData() ?: [],
                array_flip(['cc_number', 'cc_cid'])
            );

            // Get capture transaction data with defensive checks
            $captureTransactionData = null;
            if ($payment->getCreatedTransaction() && is_object($payment->getCreatedTransaction())) {
                $captureTransactionData = $payment->getCreatedTransaction()->getData() ?: [];
            }

            // Get additional information with defensive check
            $additionalInfo = $payment->getAdditionalInformation() ?: [];

            // Prepare webhook data
            $webhookData = [
                'order_id' => $order->getId() ?? '',
                'amount' => $amount,
                'payment' => $paymentData,
                'capture_transaction' => $captureTransactionData,
                'additional_information' => $additionalInfo
            ];

            $this->webhookHelper->sendWebhook('payment_capture', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('PaymentCapture Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}