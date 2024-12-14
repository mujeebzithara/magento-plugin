<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class PaymentMethodChange implements ObserverInterface
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
                $this->logger->error('PaymentMethodChange: Invalid payment object.');
                return;
            }

            $data = $observer->getEvent()->getDataByKey('data');

            // Prepare webhook data with defensive checks
            $webhookData = [
                'payment_id' => $payment->getId() ?? '',
                'method' => $payment->getMethod() ?? '',
                'additional_data' => $data && method_exists($data, 'getData') ? $data->getData() : [],
                'additional_information' => $payment->getAdditionalInformation() ?: []
            ];

            // Additional defensive check for payment method
            if (empty($webhookData['method'])) {
                $this->logger->warning('PaymentMethodChange: Payment method is empty.');
            }

            $this->webhookHelper->sendWebhook('payment_method_change', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('PaymentMethodChange Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}