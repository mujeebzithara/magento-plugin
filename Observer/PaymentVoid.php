<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class PaymentVoid implements ObserverInterface
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
                $this->logger->error('PaymentVoid: Invalid payment object.');
                return;
            }

            $order = $payment->getOrder();
            
            // Defensive check for order object
            if (!$order || !is_object($order)) {
                $this->logger->error('PaymentVoid: Invalid order object.');
                return;
            }

            // Get void transaction data with defensive checks
            $voidTransactionData = null;
            if ($payment->getVoidTransaction() && is_object($payment->getVoidTransaction())) {
                $voidTransactionData = $payment->getVoidTransaction()->getData() ?: [];
            }

            // Get additional information with defensive check
            $additionalInfo = $payment->getAdditionalInformation() ?: [];

            // Prepare webhook data with defensive checks
            $webhookData = [
                'order_id' => $order->getId() ?? '',
                'increment_id' => $order->getIncrementId() ?? '',
                'payment_id' => $payment->getId() ?? '',
                'method' => $payment->getMethod() ?? '',
                'amount' => $payment->getAmountPaid() ?? 0,
                'transaction_id' => $payment->getLastTransId() ?? '',
                'void_transaction' => $voidTransactionData,
                'additional_information' => $additionalInfo
            ];

            $this->webhookHelper->sendWebhook('payment_void', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('PaymentVoid Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}