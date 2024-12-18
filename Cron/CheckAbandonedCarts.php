<?php
namespace Zithara\Webhook\Cron;

use Magento\Quote\Model\ResourceModel\Quote\Collection as QuoteCollection;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Psr\Log\LoggerInterface;

class CheckAbandonedCarts
{
    protected $quoteCollectionFactory;
    protected $dateTime;
    protected $publisher;
    protected $jsonHelper;
    protected $logger;

    public function __construct(
        QuoteCollectionFactory $quoteCollectionFactory,
        DateTime $dateTime,
        PublisherInterface $publisher,
        JsonHelper $jsonHelper,
        LoggerInterface $logger
    ) {
        $this->quoteCollectionFactory = $quoteCollectionFactory;
        $this->dateTime = $dateTime;
        $this->publisher = $publisher;
        $this->jsonHelper = $jsonHelper;
        $this->logger = $logger;
    }

    public function execute()
    {
        try {
            $this->logger->info('CheckAbandonedCarts: Starting abandoned cart check');

            // Calculate time ranges with defensive checks
            $currentTime = $this->dateTime->gmtDate('Y-m-d H:i:s');
            if (!$currentTime) {
                $this->logger->error('CheckAbandonedCarts: Unable to get current time');
                return;
            }

            // Log current time
            $this->logger->info('CheckAbandonedCarts: Current time', [
                'current_time' => $currentTime
            ]);

            // Calculate time range in minutes (default 10 minutes)
            $from = $this->dateTime->gmtDate('Y-m-d H:i:s', strtotime('-24 hours'));
            $to = $this->dateTime->gmtDate('Y-m-d H:i:s', strtotime('-2 minutes'));

            // Log time range
            $this->logger->info('CheckAbandonedCarts: Time range', [
                'from' => $from,
                'to' => $to
            ]);

            if (!$from || !$to) {
                $this->logger->error('CheckAbandonedCarts: Unable to calculate time range');
                return;
            }

            try {
                /** @var QuoteCollection $quoteCollection */
                $quoteCollection = $this->quoteCollectionFactory->create();
                $quoteCollection->addFieldToFilter('is_active', 1)
                    ->addFieldToFilter('items_count', ['gt' => 0])
                    ->addFieldToFilter('customer_email', ['notnull' => true])
                    ->addFieldToFilter('updated_at', ['from' => $from, 'to' => $to]);

                // Log collection query
                $this->logger->info('CheckAbandonedCarts: Collection Query', [
                    'sql' => $quoteCollection->getSelect()->__toString()
                ]);

                // Log total quotes found
                $this->logger->info('CheckAbandonedCarts: Total quotes found', [
                    'count' => $quoteCollection->getSize()
                ]);

            } catch (\Exception $e) {
                $this->logger->error('CheckAbandonedCarts: Error creating quote collection', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return;
            }

            $processedCount = 0;
            $errorCount = 0;

            foreach ($quoteCollection as $quote) {
                try {
                    // Defensive check for quote object
                    if (!$quote || !is_object($quote)) {
                        $this->logger->error('CheckAbandonedCarts: Invalid quote object');
                        continue;
                    }

                    // Log detailed quote information
                    $this->logger->info('CheckAbandonedCarts: Quote Details', [
                        'quote_id' => $quote->getId(),
                        'customer_id' => $quote->getCustomerId(),
                        'customer_email' => $quote->getCustomerEmail(),
                        'customer_firstname' => $quote->getCustomerFirstname(),
                        'customer_lastname' => $quote->getCustomerLastname(),
                        'store_id' => $quote->getStoreId(),
                        'created_at' => $quote->getCreatedAt(),
                        'updated_at' => $quote->getUpdatedAt(),
                        'is_active' => $quote->getIsActive(),
                        'items_count' => $quote->getItemsCount(),
                        'items_qty' => $quote->getItemsQty(),
                        'grand_total' => $quote->getGrandTotal(),
                        'base_grand_total' => $quote->getBaseGrandTotal()
                    ]);

                    // Get all items (both visible and non-visible)
                    $allItems = $quote->getAllItems();
                    $visibleItems = $quote->getAllVisibleItems();

                    // Log items count
                    $this->logger->info('CheckAbandonedCarts: Items Count', [
                        'quote_id' => $quote->getId(),
                        'all_items_count' => count($allItems),
                        'visible_items_count' => count($visibleItems)
                    ]);

                    if (!$allItems || empty($allItems)) {
                        $this->logger->warning('CheckAbandonedCarts: No items in quote', [
                            'quote_id' => $quote->getId()
                        ]);
                        continue;
                    }

                    // Prepare cart items data with defensive checks
                    $cartItems = [];
                    foreach ($allItems as $item) {
                        if ($item && is_object($item)) {
                            // Log detailed item information
                            $this->logger->info('CheckAbandonedCarts: Item Details', [
                                'quote_id' => $quote->getId(),
                                'item_id' => $item->getId(),
                                'product_id' => $item->getProductId(),
                                'sku' => $item->getSku(),
                                'name' => $item->getName(),
                                'qty' => $item->getQty(),
                                'price' => $item->getPrice(),
                                'base_price' => $item->getBasePrice(),
                                'row_total' => $item->getRowTotal(),
                                'base_row_total' => $item->getBaseRowTotal(),
                                'tax_amount' => $item->getTaxAmount(),
                                'base_tax_amount' => $item->getBaseTaxAmount(),
                                'discount_amount' => $item->getDiscountAmount(),
                                'base_discount_amount' => $item->getBaseDiscountAmount()
                            ]);

                            $itemId = $item->getId();
                            if (!$itemId) {
                                $this->logger->warning('CheckAbandonedCarts: Missing item ID', [
                                    'quote_id' => $quote->getId(),
                                    'product_id' => $item->getProductId(),
                                    'sku' => $item->getSku()
                                ]);
                                continue;
                            }

                            $cartItems[] = [
                                'platform_cart_item_id' => (string)$itemId,
                                'price' => (float)($item->getPrice() ?? 0),
                                'product_id' => $item->getProductId(),
                                'quantity' => (float)($item->getQty() ?? 0)
                            ];
                        }
                    }

                    // Skip if no valid items
                    if (empty($cartItems)) {
                        $this->logger->warning('CheckAbandonedCarts: No valid items in cart', [
                            'quote_id' => $quote->getId()
                        ]);
                        continue;
                    }

                    // Get billing address with defensive check
                    $billingAddress = $quote->getBillingAddress();
                    
                    // Log billing address details
                    $this->logger->info('CheckAbandonedCarts: Billing Address', [
                        'quote_id' => $quote->getId(),
                        'address_data' => $billingAddress ? $billingAddress->getData() : 'No billing address'
                    ]);

                    $telephone = $billingAddress && $billingAddress->getTelephone() ? $billingAddress->getTelephone() : '';

                    // Prepare customer data
                    $customerData = [
                        'platform_customer_id' => $quote->getCustomerId() ?? '',
                        'first_name' => $quote->getCustomerFirstname() ?? '',
                        'last_name' => $quote->getCustomerLastname() ?? '',
                        'name' => trim(($quote->getCustomerFirstname() ?? '') . ' ' . ($quote->getCustomerLastname() ?? '')),
                        'whatsapp_phone_number' => $this->formatPhoneNumber($telephone),
                        'email' => $quote->getCustomerEmail() ?? false,
                        'custom_attributes' => new \stdClass()
                    ];

                    // Log customer data
                    $this->logger->info('CheckAbandonedCarts: Customer Data', [
                        'quote_id' => $quote->getId(),
                        'customer_data' => $customerData
                    ]);

                    // Prepare cart data
                    $cartData = [
                        'currency' => $quote->getQuoteCurrencyCode() ?? 'INR',
                        'platform_cart_id' => (string)$quote->getId(),
                        'name' => $quote->getReservedOrderId() ?? '',
                        'created_at' => $quote->getCreatedAt() ?? '',
                        'total_tax' => (float)($quote->getShippingAddress() ? $quote->getShippingAddress()->getTaxAmount() : 0),
                        'total_price' => (float)($quote->getGrandTotal() ?? 0),
                        'shopify_customer_id' => $quote->getCustomerId()
                    ];

                    // Log cart data
                    $this->logger->info('CheckAbandonedCarts: Cart Data', [
                        'quote_id' => $quote->getId(),
                        'cart_data' => $cartData
                    ]);

                    // Prepare webhook data
                    $webhookData = [
                        'customer' => $customerData,
                        'cart_item' => $cartItems,
                        'cart' => $cartData
                    ];

                    // Log complete webhook payload
                    $this->logger->info('CheckAbandonedCarts: Webhook Payload', [
                        'quote_id' => $quote->getId(),
                        'payload' => $webhookData
                    ]);

                    // Publish to queue
                    $this->publisher->publish(
                        'zithara.cart.events',
                        $this->jsonHelper->jsonEncode($webhookData)
                    );

                    $processedCount++;
                    
                    $this->logger->info('CheckAbandonedCarts: Cart published to queue', [
                        'quote_id' => $quote->getId(),
                        'customer_id' => $quote->getCustomerId(),
                        'item_count' => count($cartItems)
                    ]);

                } catch (\Exception $e) {
                    $errorCount++;
                    $this->logger->error('CheckAbandonedCarts: Error processing quote', [
                        'quote_id' => $quote->getId() ?? 'unknown',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            $this->logger->info('CheckAbandonedCarts: Completed processing', [
                'processed' => $processedCount,
                'errors' => $errorCount,
                'total_quotes' => $quoteCollection->getSize()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CheckAbandonedCarts: Unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    protected function formatPhoneNumber($phone)
    {
        if (empty($phone)) {
            return '';
        }

        // Log original phone number
        $this->logger->info('CheckAbandonedCarts: Formatting phone number', [
            'original' => $phone
        ]);

        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Remove leading zero if present
        if (substr($phone, 0, 1) === '0') {
            $phone = substr($phone, 1);
        }

        // Ensure it starts with '91'
        if (substr($phone, 0, 2) !== '91') {
            $phone = '91' . $phone;
        }

        // Log formatted phone number
        $this->logger->info('CheckAbandonedCarts: Formatted phone number', [
            'formatted' => $phone
        ]);

        return $phone;
    }
}