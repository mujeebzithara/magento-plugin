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

            // Calculate time range in minutes (default 10 minutes)
            $from = $this->dateTime->gmtDate('Y-m-d H:i:s', strtotime('-24 hours'));
            $to = $this->dateTime->gmtDate('Y-m-d H:i:s', strtotime('-2 minutes'));

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
                $this->logger->info('CheckAbandonedCarts: quoteCollection details: ' . json_encode($quoteCollection));
            } catch (\Exception $e) {
                $this->logger->error('CheckAbandonedCarts: Error creating quote collection', [
                    'error' => $e->getMessage()
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

                    // Log the quote data for debugging
                    $this->logger->info('CheckAbandonedCarts: Processing quote', [
                        'quote_id' => $quote->getId(),
                        'customer_id' => $quote->getCustomerId(),
                        'item_count' => $quote->getItemsCount()
                    ]);

                    // Get all visible items
                    $items = $quote->getAllVisibleItems();
                    if (!$items || empty($items)) {
                        $this->logger->warning('CheckAbandonedCarts: No visible items in quote', [
                            'quote_id' => $quote->getId()
                        ]);
                        continue;
                    }

                    // Prepare cart items data with defensive checks
                    $cartItems = [];
                    foreach ($items as $item) {
                        if ($item && is_object($item)) {
                            $itemId = $item->getId();
                            if (!$itemId) {
                                $this->logger->warning('CheckAbandonedCarts: Missing item ID', [
                                    'quote_id' => $quote->getId(),
                                    'product_id' => $item->getProductId()
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

                    // Prepare webhook data
                    $webhookData = [
                        'customer' => $customerData,
                        'cart_item' => $cartItems,
                        'cart' => $cartData
                    ];

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
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->logger->info('CheckAbandonedCarts: Completed processing', [
                'processed' => $processedCount,
                'errors' => $errorCount
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

        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Ensure it starts with '91'
        if (substr($phone, 0, 2) !== '91') {
            $phone = '91' . $phone;
        }

        return $phone;
    }
}