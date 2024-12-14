<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class OrderPayment implements ObserverInterface
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
            // Log the event for debugging
            $this->logger->info('OrderPayment Observer: Payment event triggered', [
                'event_name' => $observer->getEvent()->getName()
            ]);

            $payment = $observer->getEvent()->getPayment();
            
            // Defensive check for payment object
            if (!$payment || !is_object($payment)) {
                $this->logger->error('OrderPayment: Invalid payment object.');
                return;
            }

            $order = $payment->getOrder();
            
            // Defensive check for order object
            if (!$order || !is_object($order)) {
                $this->logger->error('OrderPayment: Invalid order object.');
                return;
            }

            // Get order and payment data with defensive checks
            $orderData = $order->getData() ?: [];
            $paymentData = $payment->getData() ?: [];

            // Remove sensitive payment data
            unset($paymentData['cc_number']);
            unset($paymentData['cc_cid']);
            unset($paymentData['cc_exp_month']);
            unset($paymentData['cc_exp_year']);

            // Get additional payment information
            $additionalInfo = $payment->getAdditionalInformation() ?: [];

            // Additional defensive checks for critical data
            $webhookData = [
                'order' => array_merge($orderData, [
                    'order_id' => $order->getId() ?? '',
                    'increment_id' => $order->getIncrementId() ?? '',
                    'status' => $order->getStatus() ?? '',
                    'state' => $order->getState() ?? '',
                    'customer_id' => $order->getCustomerId() ?? '',
                    'customer_email' => $order->getCustomerEmail() ?? '',
                    'created_at' => $order->getCreatedAt() ?? '',
                    'updated_at' => $order->getUpdatedAt() ?? ''
                ]),
                'payment' => array_merge($paymentData, [
                    'payment_id' => $payment->getId() ?? '',
                    'method' => $payment->getMethod() ?? '',
                    'amount_paid' => $payment->getAmountPaid() ?? 0,
                    'amount_authorized' => $payment->getAmountAuthorized() ?? 0,
                    'base_amount_paid' => $payment->getBaseAmountPaid() ?? 0,
                    'base_amount_authorized' => $payment->getBaseAmountAuthorized() ?? 0,
                    'last_trans_id' => $payment->getLastTransId() ?? '',
                    'additional_information' => $additionalInfo
                ])
            ];

            // Log the webhook data being sent
            $this->logger->info('OrderPayment: Preparing to send webhook', [
                'order_id' => $order->getId(),
                'payment_id' => $payment->getId(),
                'method' => $payment->getMethod()
            ]);

            $this->webhookHelper->sendWebhook('order_payment', $webhookData);

            $this->logger->info('OrderPayment: Webhook sent successfully', [
                'order_id' => $order->getId(),
                'payment_id' => $payment->getId()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('OrderPayment Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
