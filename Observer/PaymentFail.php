<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class PaymentFail implements ObserverInterface
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
                $this->logger->error('PaymentFail: Invalid payment object.');
                return;
            }

            $order = $payment->getOrder();
            
            // Defensive check for order object
            if (!$order || !is_object($order)) {
                $this->logger->error('PaymentFail: Invalid order object.');
                return;
            }

            // Get error message with defensive check
            $errorMessage = $observer->getEvent()->getMessage();
            if (empty($errorMessage)) {
                $this->logger->warning('PaymentFail: Error message is empty.');
            }

            // Get payment data with defensive checks and remove sensitive information
            $paymentData = array_diff_key(
                $payment->getData() ?: [],
                array_flip(['cc_number', 'cc_cid'])
            );

            // Get additional information with defensive check
            $additionalInfo = $payment->getAdditionalInformation() ?: [];

            // Prepare webhook data
            $webhookData = [
                'order_id' => $order->getId() ?? '',
                'payment' => $paymentData,
                'error_message' => $errorMessage ?? '',
                'additional_information' => $additionalInfo
            ];

            $this->webhookHelper->sendWebhook('payment_fail', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('PaymentFail Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}