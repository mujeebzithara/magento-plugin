<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;

class CartUpdate implements ObserverInterface
{
    protected $webhookHelper;
    protected $logger;
    protected $jsonHelper;
    protected $checkoutSession;

    public function __construct(
        WebhookHelper $webhookHelper,
        LoggerInterface $logger,
        JsonHelper $jsonHelper,
        \Magento\Checkout\Model\Session $checkoutSession
    ) {
        $this->webhookHelper = $webhookHelper;
        $this->logger = $logger;
        $this->jsonHelper = $jsonHelper;
        $this->checkoutSession = $checkoutSession;
    }

    public function execute(Observer $observer)
    {
        try {
        
            $quote = $this->checkoutSession->getQuote();
            if (!$quote || !$quote->getId()) {
                $this->logger->error('CartUpdate: Unable to retrieve the quote.');
                return;
            }
                        
            // Log all available event data
            //$eventData = $observer->getQuote();
            $this->logger->info('CartUpdate Event Data:', ['quote' => $this->jsonHelper->jsonEncode($quote)]);

            // Get the quote object from the event
            //$quote = $observer->getEvent()->getQuote();

            if (!$quote || !is_object($quote)) {
                $this->logger->error('CartUpdate: Quote object is missing or invalid.');
                return;
            }

            // Log quote data
            $this->logger->info('CartUpdate: Quote Data', [
                'quote_id' => $quote->getId(),
                'subtotal' => $quote->getSubtotal(),
                'grand_total' => $quote->getGrandTotal(),
                'customer_id' => $quote->getCustomerId(),
                'customer_email' => $quote->getCustomerEmail(),
            ]);

            // Get and log cart items
            $cartItems = [];
            if ($quote->getAllVisibleItems()) {
                foreach ($quote->getAllVisibleItems() as $item) {
                    if ($item && is_object($item)) {
                        $cartItems[] = [
                            'item_id' => $item->getId(),
                            'product_id' => $item->getProductId(),
                            'sku' => $item->getSku(),
                            'name' => $item->getName(),
                            'qty' => $item->getQty(),
                            'price' => $item->getPrice(),
                            'row_total' => $item->getRowTotal(),
                        ];
                    }
                }
            }
            $this->logger->info('CartUpdate: Cart Items', ['cart_items' => $this->jsonHelper->jsonEncode($cartItems)]);

            // Prepare webhook data
            $webhookData = [
                'quote_id' => $quote->getId(),
                'cart_items' => $cartItems,
                'cart_totals' => [
                    'subtotal' => $quote->getSubtotal(),
                    'grand_total' => $quote->getGrandTotal(),
                ],
                'customer' => [
                    'id' => $quote->getCustomerId(),
                    'email' => $quote->getCustomerEmail(),
                    'group_id' => $quote->getCustomerGroupId(),
                ],
                'created_at' => $quote->getCreatedAt(),
                'updated_at' => $quote->getUpdatedAt(),
            ];

            // Log webhook data for debugging
            $this->logger->info('CartUpdate: Webhook Data', [
                'webhook_data' => $this->jsonHelper->jsonEncode($webhookData),
            ]);

            // Send the webhook
            $this->webhookHelper->sendWebhook('cart_update', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('CartUpdate Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
