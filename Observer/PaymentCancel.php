<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class PaymentCancel implements ObserverInterface
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
                $this->logger->error('PaymentCancel: Invalid payment object.');
                return;
            }

            $order = $payment->getOrder();
            
            // Defensive check for order object
            if (!$order || !is_object($order)) {
                $this->logger->error('PaymentCancel: Invalid order object.');
                return;
            }

            // Get payment data with defensive checks and remove sensitive information
            $paymentData = array_diff_key(
                $payment->getData() ?: [],
                array_flip(['cc_number', 'cc_cid'])
            );

            // Get cancel transaction data with defensive checks
            $cancelTransactionData = null;
            if ($payment->getCreatedTransaction() && is_object($payment->getCreatedTransaction())) {
                $cancelTransactionData = $payment->getCreatedTransaction()->getData() ?: [];
            }

            // Get additional information with defensive check
            $additionalInfo = $payment->getAdditionalInformation() ?: [];

            // Prepare webhook data
            $webhookData = [
                'order_id' => $order->getId() ?? '',
                'payment' => $paymentData,
                'cancel_transaction' => $cancelTransactionData,
                'additional_information' => $additionalInfo
            ];

            $this->webhookHelper->sendWebhook('payment_cancel', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('PaymentCancel Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}