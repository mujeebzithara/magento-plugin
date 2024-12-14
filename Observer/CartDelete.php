<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;

class CartDelete implements ObserverInterface
{
    protected $webhookHelper;
    protected $logger;
    protected $jsonHelper;

    public function __construct(
        WebhookHelper $webhookHelper,
        LoggerInterface $logger,
        JsonHelper $jsonHelper
    ) {
        $this->webhookHelper = $webhookHelper;
        $this->logger = $logger;
        $this->jsonHelper = $jsonHelper;
    }

    public function execute(Observer $observer)
    {
        try {
            $cart = $observer->getEvent()->getCart();
           
            $this->logger->info('CartDelete: Cart Data', [
               'cart' => $this->jsonHelper->jsonEncode($cart)
            ]);

            // Defensive checks
            if (!$cart || !is_object($cart)) {
                $this->logger->error('CartDelete: Invalid cart object.');
                return;
            }

            if (!$cart->getQuote()) {
                $this->logger->error('CartDelete: Invalid quote in cart.');
                return;
            }

            $quote = $cart->getQuote();
            
            // Prepare webhook data with defensive checks
            $webhookData = [
                'quote_id' => $quote->getId() ?? '',
                'customer_id' => $quote->getCustomerId() ?? '',
                'store_id' => $quote->getStoreId() ?? ''
            ];

            $this->webhookHelper->sendWebhook('cart_delete', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('CartDelete Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}