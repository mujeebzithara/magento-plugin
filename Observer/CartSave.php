<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Psr\Log\LoggerInterface;

class CartSave implements ObserverInterface
{
    protected $publisher;
    protected $jsonHelper;
    protected $logger;

    public function __construct(
        PublisherInterface $publisher,
        JsonHelper $jsonHelper,
        LoggerInterface $logger
    ) {
        $this->publisher = $publisher;
        $this->jsonHelper = $jsonHelper;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        try {
            $quote = $observer->getEvent()->getQuote();

            $this->logger->info('CartSave: Cart Data', [
                'quote' => $this->jsonHelper->jsonEncode($quote)
            ]);

            // Defensive check for quote object
            if (!$quote || !is_object($quote)) {
                $this->logger->error('CartSave: Invalid quote object.');
                return;
            }

            // Get customer data with defensive checks
            $customerData = [];
            if ($quote->getCustomerId()) {
                $customerData = [
                    'platform_customer_id' => $quote->getCustomerId(),
                    'first_name' => $quote->getCustomerFirstname(),
                    'last_name' => $quote->getCustomerLastname(),
                    'name' => trim($quote->getCustomerFirstname() . ' ' . $quote->getCustomerLastname()),
                    'whatsapp_phone_number' => $this->formatPhoneNumber($quote->getBillingAddress() ? $quote->getBillingAddress()->getTelephone() : ''),
                    'email' => $quote->getCustomerEmail() ?: false,
                    'custom_attributes' => new \stdClass()
                ];
            }

            // Prepare cart items data with defensive checks
            $cartItems = [];
            if ($quote->getAllVisibleItems()) {
                foreach ($quote->getAllVisibleItems() as $item) {
                    if ($item && is_object($item)) {
                        $cartItems[] = [
                            'platform_cart_item_id' => (string)$item->getId(),
                            'price' => (float)($item->getPrice() ?? 0),
                            'product_id' => $item->getProductId(),
                            'quantity' => (float)($item->getQty() ?? 1)
                        ];
                    }
                }
            }

            // Prepare cart data
            $cartData = [
                'currency' => $quote->getQuoteCurrencyCode() ?: 'INR',
                'platform_cart_id' => (string)$quote->getId(),
                'name' => $quote->getReservedOrderId() ?: 'CART/' . $quote->getId(),
                'created_at' => $quote->getCreatedAt() ?: date('Y-m-d H:i:s'),
                'total_tax' => (float)($quote->getShippingAddress() ? $quote->getShippingAddress()->getTaxAmount() : 0),
                'total_price' => (float)($quote->getGrandTotal() ?? 0),
                'shopify_customer_id' => $quote->getCustomerId()
            ];

            // Prepare webhook data in Zithara API format
            $webhookData = [
                'customer' => $customerData,
                'cart_item' => $cartItems,
                'cart' => $cartData
            ];

            // Log the data being sent
            $this->logger->info('CartSave: Publishing cart to queue', [
                'quote_id' => $quote->getId(),
                'customer_id' => $quote->getCustomerId(),
                'item_count' => count($cartItems),
                'webhook_data' => $this->jsonHelper->jsonEncode($webhookData)
            ]);

            // Publish to queue
            $this->publisher->publish(
                'zithara.cart.events',
                $this->jsonHelper->jsonEncode($webhookData)
            );

            $this->logger->info('CartSave: Successfully published cart to queue', [
                'quote_id' => $quote->getId()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CartSave Observer Error: ' . $e->getMessage(), [
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