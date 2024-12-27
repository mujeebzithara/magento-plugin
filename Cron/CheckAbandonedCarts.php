<?php
namespace Zithara\Webhook\Cron;

use Magento\Quote\Model\ResourceModel\Quote\Collection as QuoteCollection;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Psr\Log\LoggerInterface;

class CheckAbandonedCarts
{
    protected $quoteCollectionFactory;
    protected $dateTime;
    protected $publisher;
    protected $jsonHelper;
    protected $customerRepository;
    protected $logger;

    public function __construct(
        QuoteCollectionFactory $quoteCollectionFactory,
        DateTime $dateTime,
        PublisherInterface $publisher,
        JsonHelper $jsonHelper,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger
    ) {
        $this->quoteCollectionFactory = $quoteCollectionFactory;
        $this->dateTime = $dateTime;
        $this->publisher = $publisher;
        $this->jsonHelper = $jsonHelper;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    public function execute()
    {
        try {
            $this->logger->info('CheckAbandonedCarts: Starting abandoned cart check');

            // Get time ranges with defensive checks
            $timeRanges = $this->getTimeRanges();
            if (!$timeRanges) {
                return;
            }

            // Get abandoned quotes
            $quoteCollection = $this->getAbandonedQuotes($timeRanges);
            if (!$quoteCollection) {
                return;
            }

            $processedCount = 0;
            $errorCount = 0;

            foreach ($quoteCollection as $quote) {
                try {
                    // Process each quote
                    if ($this->processQuote($quote)) {
                        $processedCount++;
                    } else {
                        $errorCount++;
                    }
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

    protected function getTimeRanges()
    {
        try {
            $currentTime = $this->dateTime->gmtDate('Y-m-d H:i:s');
            if (!$currentTime) {
                $this->logger->error('CheckAbandonedCarts: Unable to get current time');
                return null;
            }

            $from = $this->dateTime->gmtDate('Y-m-d H:i:s', strtotime('-24 hours'));
            $to = $this->dateTime->gmtDate('Y-m-d H:i:s', strtotime('-30 minutes'));

            if (!$from || !$to) {
                $this->logger->error('CheckAbandonedCarts: Unable to calculate time range');
                return null;
            }

            $this->logger->info('CheckAbandonedCarts: Time range', [
                'current_time' => $currentTime,
                'from' => $from,
                'to' => $to
            ]);

            return ['from' => $from, 'to' => $to];
        } catch (\Exception $e) {
            $this->logger->error('CheckAbandonedCarts: Error getting time ranges', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    protected function getAbandonedQuotes($timeRanges)
    {
        try {
            if (!isset($timeRanges['from']) || !isset($timeRanges['to'])) {
                throw new \Exception('Invalid time ranges provided');
            }

            /** @var QuoteCollection $quoteCollection */
            $quoteCollection = $this->quoteCollectionFactory->create();
            $quoteCollection->addFieldToFilter('is_active', 1)
                ->addFieldToFilter('items_count', ['gt' => 0])
                ->addFieldToFilter('customer_email', ['notnull' => true])
                ->addFieldToFilter('updated_at', [
                    'from' => $timeRanges['from'],
                    'to' => $timeRanges['to']
                ]);

            $this->logger->info('CheckAbandonedCarts: Collection Query', [
                'sql' => $quoteCollection->getSelect()->__toString(),
                'size' => $quoteCollection->getSize()
            ]);

            return $quoteCollection;

        } catch (\Exception $e) {
            $this->logger->error('CheckAbandonedCarts: Error creating quote collection', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    protected function processQuote($quote)
    {
        try {
            if (!$quote || !is_object($quote)) {
                throw new \Exception('Invalid quote object');
            }

            $this->logQuoteDetails($quote);

            // Get customer data
            $customer = $this->getCustomerData($quote);
            
            // Get cart items
            $cartItems = $this->getCartItems($quote);
            if (empty($cartItems)) {
                return false;
            }

            // Get phone number
            $phoneNumber = $this->getCustomerPhoneNumber($customer, $quote->getBillingAddress());

            // Prepare webhook data
            $webhookData = $this->prepareWebhookData($quote, $customer, $cartItems, $phoneNumber);

            // Send to queue
            $this->publisher->publish(
                'zithara.cart.events',
                $this->jsonHelper->jsonEncode($webhookData)
            );

            $this->logger->info('CheckAbandonedCarts: Cart published to queue', [
                'quote_id' => $quote->getId(),
                'customer_id' => $quote->getCustomerId(),
                'item_count' => count($cartItems)
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('CheckAbandonedCarts: Error processing quote', [
                'quote_id' => $quote->getId() ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    protected function getCustomerData($quote)
    {
        try {
            if (!$quote->getCustomerId()) {
                return null;
            }

            $customer = $this->customerRepository->getById($quote->getCustomerId());
            
            $this->logger->info('CheckAbandonedCarts: Retrieved customer', [
                'customer_id' => $customer->getId()
            ]);

            return $customer;

        } catch (\Exception $e) {
            $this->logger->error('CheckAbandonedCarts: Error retrieving customer', [
                'customer_id' => $quote->getCustomerId(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    protected function getCartItems($quote)
    {
        try {
            $allItems = $quote->getAllItems();
            if (!$allItems || empty($allItems)) {
                $this->logger->warning('CheckAbandonedCarts: No items in quote', [
                    'quote_id' => $quote->getId()
                ]);
                return [];
            }

            $cartItems = [];
            foreach ($allItems as $item) {
                if (!$item || !is_object($item)) {
                    continue;
                }

                $this->logItemDetails($quote->getId(), $item);

                $itemId = $item->getId();
                if (!$itemId) {
                    continue;
                }

                $cartItems[] = [
                    'platform_cart_item_id' => (string)$itemId,
                    'price' => (float)($item->getPrice() ?? 0),
                    'product_id' => $item->getProductId(),
                    'quantity' => (float)($item->getQty() ?? 0)
                ];
            }

            return $cartItems;

        } catch (\Exception $e) {
            $this->logger->error('CheckAbandonedCarts: Error getting cart items', [
                'quote_id' => $quote->getId(),
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    protected function getCustomerPhoneNumber($customer, $billingAddress = null)
    {
        $phoneNumber = '';

        // First try billing address
        if ($billingAddress && $billingAddress->getTelephone()) {
            $phoneNumber = $billingAddress->getTelephone();
            $this->logger->info('CheckAbandonedCarts: Found phone in billing address', [
                'customer_id' => $customer ? $customer->getId() : 'unknown',
                'phone' => $phoneNumber
            ]);
            return $this->formatPhoneNumber($phoneNumber);
        }

        if (!$customer) {
            return '';
        }

        // Try custom attributes
        try {
            $customAttributes = [];
            if (method_exists($customer, 'getCustomAttributes') && $customer->getCustomAttributes()) {
                foreach ($customer->getCustomAttributes() as $attribute) {
                    if ($attribute && is_object($attribute)) {
                        $customAttributes[$attribute->getAttributeCode()] = $attribute->getValue();
                    }
                }
            }

            // Check mobilenumber in custom attributes
            if (!empty($customAttributes) && isset($customAttributes['mobilenumber']) && !empty($customAttributes['mobilenumber'])) {
                $phoneNumber = str_replace(' ', '', $customAttributes['mobilenumber']);
                $this->logger->info('CheckAbandonedCarts: Found phone in custom attributes', [
                    'customer_id' => $customer->getId(),
                    'phone' => $phoneNumber
                ]);
                return $this->formatPhoneNumber($phoneNumber);
            }

            // Check mobilenumber in customer data
            if (method_exists($customer, 'getData')) {
                $customerData = $customer->getData();
                if (!empty($customerData) && isset($customerData['mobilenumber']) && !empty($customerData['mobilenumber'])) {
                    $phoneNumber = str_replace(' ', '', $customerData['mobilenumber']);
                    $this->logger->info('CheckAbandonedCarts: Found phone in customer data', [
                        'customer_id' => $customer->getId(),
                        'phone' => $phoneNumber
                    ]);
                    return $this->formatPhoneNumber($phoneNumber);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('CheckAbandonedCarts: Error getting customer phone number', [
                'customer_id' => $customer->getId(),
                'error' => $e->getMessage()
            ]);
        }

        return $this->formatPhoneNumber($phoneNumber);
    }

    protected function prepareWebhookData($quote, $customer, $cartItems, $phoneNumber)
    {
        // Prepare customer data
        $customerData = [
            'platform_customer_id' => $quote->getCustomerId() ?? '',
            'first_name' => $customer ? ($customer->getFirstname() ?? '') : ($quote->getCustomerFirstname() ?? ''),
            'last_name' => $customer ? ($customer->getLastname() ?? '') : ($quote->getCustomerLastname() ?? ''),
            'name' => $customer ? 
                trim(($customer->getFirstname() ?? '') . ' ' . ($customer->getLastname() ?? '')) :
                trim(($quote->getCustomerFirstname() ?? '') . ' ' . ($quote->getCustomerLastname() ?? '')),
            'phone_number' => $phoneNumber,
            'whatsapp_phone_number' => $phoneNumber,
            'email' => $customer ? ($customer->getEmail() ?? false) : ($quote->getCustomerEmail() ?? false),
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

        return [
            'customer' => $customerData,
            'cart_item' => $cartItems,
            'cart' => $cartData
        ];
    }

    protected function formatPhoneNumber($phone)
    {
        if (empty($phone)) {
            return '';
        }

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

        $this->logger->info('CheckAbandonedCarts: Formatted phone number', [
            'formatted' => $phone
        ]);

        return $phone;
    }

    protected function logQuoteDetails($quote)
    {
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
    }

    protected function logItemDetails($quoteId, $item)
    {
        $this->logger->info('CheckAbandonedCarts: Item Details', [
            'quote_id' => $quoteId,
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
    }
}