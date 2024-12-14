<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;

class CartRemoveItem implements ObserverInterface
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
            $quoteItem = $observer->getEvent()->getQuoteItem();
            
            $this->logger->info('CartRemoveItem: Cart Data', [
               'quoteItem' => $this->jsonHelper->jsonEncode($quoteItem)
            ]);
            
            // Defensive check for quote item
            if (!$quoteItem || !is_object($quoteItem)) {
                $this->logger->error('CartRemoveItem: Invalid quote item object.');
                return;
            }

            $quote = $quoteItem->getQuote();
            
            // Defensive check for quote
            if (!$quote || !is_object($quote)) {
                $this->logger->error('CartRemoveItem: Invalid quote object.');
                return;
            }

            // Prepare removed item data with defensive checks
            $removedItemData = [
                'item_id' => $quoteItem->getId() ?? '',
                'product_id' => $quoteItem->getProductId() ?? '',
                'sku' => $quoteItem->getSku() ?? '',
                'qty' => $quoteItem->getQty() ?? 0,
                'price' => $quoteItem->getPrice() ?? 0,
                'name' => $quoteItem->getName() ?? ''
            ];

            // Prepare remaining items data with defensive checks
            $remainingItems = [];
            foreach ($quote->getAllVisibleItems() as $item) {
                if ($item && is_object($item)) {
                    $remainingItems[] = [
                        'item_id' => $item->getId() ?? '',
                        'product_id' => $item->getProductId() ?? '',
                        'sku' => $item->getSku() ?? '',
                        'qty' => $item->getQty() ?? 0,
                        'price' => $item->getPrice() ?? 0,
                        'name' => $item->getName() ?? ''
                    ];
                }
            }

            // Prepare cart totals with defensive checks
            $cartTotals = [
                'subtotal' => $quote->getSubtotal() ?? 0,
                'grand_total' => $quote->getGrandTotal() ?? 0
            ];

            $this->webhookHelper->sendWebhook('cart_remove_item', [
                'quote_id' => $quote->getId() ?? '',
                'removed_item' => $removedItemData,
                'remaining_items' => $remainingItems,
                'cart_totals' => $cartTotals
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CartRemoveItem Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}