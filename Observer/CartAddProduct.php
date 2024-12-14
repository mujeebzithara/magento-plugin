<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Psr\Log\LoggerInterface;

class CartAddProduct implements ObserverInterface
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
        
            
            $product = $observer->getEvent()->getProduct();
            $cart = $observer->getEvent()->getCart();
            $quoteItem = $observer->getEvent()->getQuoteItem();
            
            $this->logger->info('CartAddProduct: Cart Data', [
               'product_json' => $this->jsonHelper->jsonEncode($product->getData()),
               'quoteItem' => $this->jsonHelper->jsonEncode($quoteItem->getData())
            ]);

            // Defensive checks
            if (!$product || !is_object($product)) {
                $this->logger->error('CartAddProduct: Invalid product object.');
                return;
            }

            if (!$cart || !is_object($cart)) {
                $this->logger->error('CartAddProduct: Invalid cart object.');
                return;
            }

            $quote = $cart->getQuote();
            if (!$quote || !is_object($quote)) {
                $this->logger->error('CartAddProduct: Invalid quote object.');
                return;
            }

            // Get customer data with defensive checks
            $customerData = [];
            if ($quote->getCustomerId()) {
                $customerData = [
                    'customer_id' => $quote->getCustomerId(),
                    'email' => $quote->getCustomerEmail(),
                    'firstname' => $quote->getCustomerFirstname(),
                    'lastname' => $quote->getCustomerLastname(),
                    'telephone' => $quote->getBillingAddress() ? $quote->getBillingAddress()->getTelephone() : ''
                ];
            }

            // Prepare cart items data with defensive checks
            $cartItems = [];
            foreach ($quote->getAllVisibleItems() as $item) {
                if ($item && is_object($item)) {
                    $cartItems[] = [
                        'item_id' => $item->getId() ?? '',
                        'product_id' => $item->getProductId() ?? '',
                        'sku' => $item->getSku() ?? '',
                        'qty' => $item->getQty() ?? 0,
                        'price' => $item->getPrice() ?? 0,
                        'name' => $item->getName() ?? ''
                    ];
                }
            }

            // Prepare webhook data
            $webhookData = [
                'quote' => $quote->getData(),
                'items' => $cartItems,
                'customer' => $customerData,
                'product_added' => [
                    'product_id' => $product->getId() ?? '',
                    'sku' => $product->getSku() ?? '',
                    'name' => $product->getName() ?? '',
                    'price' => $product->getPrice() ?? 0
                ]
            ];

            // Log the data being sent
            $this->logger->info('CartAddProduct: Publishing cart to queue', [
                'quote_id' => $quote->getId(),
                'customer_id' => $quote->getCustomerId(),
                'product_id' => $product->getId(),
                'item_count' => count($cartItems)
            ]);

            // Publish to queue
            $this->publisher->publish(
                'zithara.cart.events',
                $this->jsonHelper->jsonEncode($webhookData)
            );

            $this->logger->info('CartAddProduct: Successfully published cart to queue', [
                'quote_id' => $quote->getId()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CartAddProduct Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}