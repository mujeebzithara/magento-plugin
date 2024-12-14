<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class PaymentAuthorization implements ObserverInterface
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
                $this->logger->error('PaymentAuthorization: Invalid payment object.');
                return;
            }

            $order = $payment->getOrder();
            
            // Defensive check for order object
            if (!$order || !is_object($order)) {
                $this->logger->error('PaymentAuthorization: Invalid order object.');
                return;
            }

            // Get payment data with defensive checks
            $paymentData = $payment->getData() ?: [];
            
            // Remove sensitive data
            unset($paymentData['cc_number']);
            unset($paymentData['cc_cid']);

            // Get authorization transaction data with defensive checks
            $authTransactionData = null;
            if ($payment->getAuthorizationTransaction() && is_object($payment->getAuthorizationTransaction())) {
                $authTransactionData = $payment->getAuthorizationTransaction()->getData() ?: [];
            }

            // Get additional information with defensive check
            $additionalInfo = $payment->getAdditionalInformation() ?: [];

            // Prepare webhook data
            $webhookData = [
                'order_id' => $order->getId() ?? '',
                'payment' => $paymentData,
                'authorization_transaction' => $authTransactionData,
                'additional_information' => $additionalInfo
            ];

            $this->webhookHelper->sendWebhook('payment_authorization', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('PaymentAuthorization Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}