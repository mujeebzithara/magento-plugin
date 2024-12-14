<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class QuoteSave implements ObserverInterface
{
    protected $webhookHelper;
    protected $dateTime;
    protected $logger;

    public function __construct(
        WebhookHelper $webhookHelper,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        $this->webhookHelper = $webhookHelper;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        try {
            $quote = $observer->getEvent()->getQuote();
            
            // Defensive check for quote object
            if (!$quote || !is_object($quote)) {
                $this->logger->error('QuoteSave: Invalid quote object.');
                return;
            }

            // Only track active quotes with items
            if (!$quote->getIsActive() || !$quote->getItemsCount()) {
                return;
            }

            // Prepare items data with defensive checks
            $items = [];
            if ($quote->getAllVisibleItems()) {
                foreach ($quote->getAllVisibleItems() as $item) {
                    if ($item && is_object($item)) {
                        $items[] = [
                            'item_id' => $item->getId() ?? '',
                            'product_id' => $item->getProductId() ?? '',
                            'sku' => $item->getSku() ?? '',
                            'name' => $item->getName() ?? '',
                            'qty' => $item->getQty() ?? 0,
                            'price' => $item->getPrice() ?? 0,
                            'base_price' => $item->getBasePrice() ?? 0,
                            'row_total' => $item->getRowTotal() ?? 0,
                            'base_row_total' => $item->getBaseRowTotal() ?? 0
                        ];
                    }
                }
            }

            // Prepare customer data with defensive checks
            $customerData = [
                'customer_id' => $quote->getCustomerId() ?? '',
                'customer_email' => $quote->getCustomerEmail() ?? '',
                'customer_firstname' => $quote->getCustomerFirstname() ?? '',
                'customer_lastname' => $quote->getCustomerLastname() ?? '',
                'customer_group_id' => $quote->getCustomerGroupId() ?? ''
            ];

            // Prepare cart data with defensive checks
            $cartData = [
                'created_at' => $quote->getCreatedAt() ?? '',
                'updated_at' => $quote->getUpdatedAt() ?? '',
                'items_count' => $quote->getItemsCount() ?? 0,
                'items_qty' => $quote->getItemsQty() ?? 0,
                'grand_total' => $quote->getGrandTotal() ?? 0,
                'base_grand_total' => $quote->getBaseGrandTotal() ?? 0,
                'subtotal' => $quote->getSubtotal() ?? 0,
                'base_subtotal' => $quote->getBaseSubtotal() ?? 0,
                'currency_code' => $quote->getQuoteCurrencyCode() ?? ''
            ];

            $webhookData = [
                'quote_id' => $quote->getId() ?? '',
                'store_id' => $quote->getStoreId() ?? '',
                'customer_data' => $customerData,
                'cart_data' => $cartData,
                'items' => $items,
                'last_updated' => $this->dateTime->gmtDate('Y-m-d H:i:s')
            ];

            $this->webhookHelper->sendWebhook('quote_update', $webhookData);

        } catch (\Exception $e) {
            $this->logger->error('QuoteSave Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}