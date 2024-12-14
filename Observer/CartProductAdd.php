<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class CartProductAdd implements ObserverInterface
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
            $product = $observer->getEvent()->getProduct();
            $quoteItem = $observer->getEvent()->getQuoteItem();

            // Defensive checks
            if (!$product || !is_object($product)) {
                $this->logger->error('CartProductAdd: Invalid product object.');
                return;
            }

            if (!$quoteItem || !is_object($quoteItem)) {
                $this->logger->error('CartProductAdd: Invalid quote item object.');
                return;
            }

            $quote = $quoteItem->getQuote();
            if (!$quote || !is_object($quote)) {
                $this->logger->error('CartProductAdd: Invalid quote object.');
                return;
            }

            // Prepare product data with defensive checks
            $productData = [
                'id' => $product->getId() ?? '',
                'sku' => $product->getSku() ?? '',
                'name' => $product->getName() ?? '',
                'price' => $product->getPrice() ?? 0,
                'final_price' => $product->getFinalPrice() ?? 0,
                'type_id' => $product->getTypeId() ?? '',
                'status' => $product->getStatus() ?? '',
                'visibility' => $product->getVisibility() ?? '',
                'created_at' => $product->getCreatedAt() ?? '',
                'updated_at' => $product->getUpdatedAt() ?? ''
            ];

            // Prepare quote item data with defensive checks
            $quoteItemData = [
                'item_id' => $quoteItem->getId() ?? '',
                'qty' => $quoteItem->getQty() ?? 0,
                'price' => $quoteItem->getPrice() ?? 0,
                'base_price' => $quoteItem->getBasePrice() ?? 0,
                'row_total' => $quoteItem->getRowTotal() ?? 0,
                'base_row_total' => $quoteItem->getBaseRowTotal() ?? 0
            ];

            // Prepare quote data with defensive checks
            $quoteData = [
                'id' => $quote->getId() ?? '',
                'customer_id' => $quote->getCustomerId() ?? '',
                'customer_email' => $quote->getCustomerEmail() ?? '',
                'customer_group_id' => $quote->getCustomerGroupId() ?? '',
                'store_id' => $quote->getStoreId() ?? '',
                'created_at' => $quote->getCreatedAt() ?? '',
                'updated_at' => $quote->getUpdatedAt() ?? '',
                'items_count' => $quote->getItemsCount() ?? 0,
                'items_qty' => $quote->getItemsQty() ?? 0,
                'grand_total' => $quote->getGrandTotal() ?? 0,
                'base_grand_total' => $quote->getBaseGrandTotal() ?? 0,
                'subtotal' => $quote->getSubtotal() ?? 0,
                'base_subtotal' => $quote->getBaseSubtotal() ?? 0
            ];

            $this->webhookHelper->sendWebhook('cart_product_add', [
                'quote_id' => $quote->getId() ?? '',
                'product' => $productData,
                'quote_item' => $quoteItemData,
                'quote' => $quoteData
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CartProductAdd Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}