<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Psr\Log\LoggerInterface;
use Magento\Sales\Model\Order;

class OrderCreate implements ObserverInterface
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
            $order = $observer->getEvent()->getOrder();
            
            // Defensive check for order object
            if (!$order || !is_object($order)) {
                $this->logger->error('OrderCreate: Invalid order object.');
                return;
            }

            // Get order data with defensive check
            $orderData = $order->getData() ?: [];
            
            // Convert the order data to JSON for better readability
            $orderJson = json_encode($orderData, JSON_PRETTY_PRINT);

            // Log the order data as JSON
            $this->logger->info('Complete Order Data:', [
              'order_data' => $orderJson
            ]);

            // Get payment data with defensive checks
            $payment = $order->getPayment();
            $paymentData = [];
            if ($payment && is_object($payment)) {
                $paymentData = $payment->getData() ?: [];
                // Remove sensitive data
                unset($paymentData['cc_number']);
                unset($paymentData['cc_cid']);
                unset($paymentData['cc_exp_month']);
                unset($paymentData['cc_exp_year']);

                // Get additional payment information
                $additionalInfo = $payment->getAdditionalInformation() ?: [];
                $paymentData['additional_information'] = $additionalInfo;
            }
            
            
            $this->logger->info('Payment and Additional Info',[
              'payment'=>$this->jsonHelper->jsonEncode($payment),
              'additional_info'=>$this->jsonHelper->jsonEncode($payment->getAdditionalInformation())
            ]);

            // Get order items with defensive checks
            $orderItems = [];
            foreach ($order->getAllItems() as $item) {
                if ($item && is_object($item)) {
                    // Log item data for debugging
                    $this->logger->info('OrderCreate: Processing order item', [
                        'item_id' => $item->getItemId(),
                        'order_item_id' => $item->getOrderItemId(),
                        'quote_item_id' => $item->getQuoteItemId(),
                        'product_id' => $item->getProductId()
                    ]);

                    // Use the appropriate ID with fallbacks
                    $itemId = $item->getId() ?? $item->getOrderItemId() ?? $item->getQuoteItemId();
                    
                    if (!$itemId) {
                        $this->logger->warning('OrderCreate: Missing item ID', [
                            'product_id' => $item->getProductId(),
                            'sku' => $item->getSku()
                        ]);
                        continue;
                    }

                    $orderItems[] = [
                        'item_id' => $itemId,
                        'product_id' => $item->getProductId() ?? '',
                        'sku' => $item->getSku() ?? '',
                        'name' => $item->getName() ?? '',
                        'qty_ordered' => $item->getQtyOrdered() ?? 0,
                        'price' => $item->getPrice() ?? 0,
                        'base_price' => $item->getBasePrice() ?? 0,
                        'row_total' => $item->getRowTotal() ?? 0,
                        'base_row_total' => $item->getBaseRowTotal() ?? 0,
                        'tax_amount' => $item->getTaxAmount() ?? 0,
                        'base_tax_amount' => $item->getBaseTaxAmount() ?? 0,
                        'discount_amount' => $item->getDiscountAmount() ?? 0,
                        'base_discount_amount' => $item->getBaseDiscountAmount() ?? 0,
                        'product_type' => $item->getProductType() ?? '',
                        'product_options' => $item->getProductOptions() ?: []
                    ];
                }
            }

            // Verify we have items
            if (empty($orderItems)) {
                $this->logger->error('OrderCreate: No valid order items found', [
                    'order_id' => $order->getId(),
                    'increment_id' => $order->getIncrementId()
                ]);
                return;
            }

            // Check if this is a new order or an update
            $isUpdate = !$this->isNewOrder($order);

            $this->logger->info('OrderCreate: Order state check', [
                'order_id' => $order->getId(),
                'state' => $order->getState(),
                'is_update' => $isUpdate
            ]);

            // Prepare webhook data
            $webhookData = [
                'order' => array_merge($orderData, [
                    'order_id' => $order->getId() ?? '',
                    'increment_id' => $order->getIncrementId() ?? '',
                    'customer_id' => $order->getCustomerId() ?? '',
                    'customer_email' => $order->getCustomerEmail() ?? '',
                    'customer_firstname' => $order->getCustomerFirstname() ?? '',
                    'customer_lastname' => $order->getCustomerLastname() ?? '',
                    'status' => $order->getStatus() ?? '',
                    'state' => $order->getState() ?? '',
                    'created_at' => $order->getCreatedAt() ?? '',
                    'updated_at' => $order->getUpdatedAt() ?? '',
                    'total_paid' => $order->getTotalPaid() ?? 0,
                    'base_total_paid' => $order->getBaseTotalPaid() ?? 0,
                    'grand_total' => $order->getGrandTotal() ?? 0,
                    'base_grand_total' => $order->getBaseGrandTotal() ?? 0,
                    'subtotal' => $order->getSubtotal() ?? 0,
                    'base_subtotal' => $order->getBaseSubtotal() ?? 0,
                    'tax_amount' => $order->getTaxAmount() ?? 0,
                    'base_tax_amount' => $order->getBaseTaxAmount() ?? 0,
                    'discount_amount' => $order->getDiscountAmount() ?? 0,
                    'base_discount_amount' => $order->getBaseDiscountAmount() ?? 0,
                    'shipping_amount' => $order->getShippingAmount() ?? 0,
                    'base_shipping_amount' => $order->getBaseShippingAmount() ?? 0,
                    'shipping_method' => $order->getShippingMethod() ?? '',
                    'shipping_description' => $order->getShippingDescription() ?? '',
                    'currency_code' => $order->getOrderCurrencyCode() ?? ''
                ]),
                'payment' => $paymentData,
                'items' => $orderItems,
                'billing_address' => $order->getBillingAddress() ? $order->getBillingAddress()->getData() : [],
                'shipping_address' => $order->getShippingAddress() ? $order->getShippingAddress()->getData() : [],
                'is_update' => $isUpdate
            ];

            // Log the data being sent
            $this->logger->info('OrderCreate: Publishing order to queue', [
                'order_id' => $order->getId(),
                'increment_id' => $order->getIncrementId(),
                'item_count' => count($orderItems),
                'is_update' => $isUpdate
            ]);

            // Publish to queue
            $this->publisher->publish(
                'zithara.order.events',
                $this->jsonHelper->jsonEncode($webhookData)
            );

            $this->logger->info('OrderCreate: Successfully published order to queue', [
                'order_id' => $order->getId(),
                'is_update' => $isUpdate
            ]);

        } catch (\Exception $e) {
            $this->logger->error('OrderCreate Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Determine if this is a new order by checking the order state
     *
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     */
    protected function isNewOrder($order)
    {
        try {
            if ($order->getOrigData()) { 
              return false;
            }else{
              return true;
            }
        } catch (\Exception $e) {
            $this->logger->error('OrderCreate: Error checking order state', [
                'error' => $e->getMessage(),
                'order_id' => $order->getId()
            ]);
            // Default to treating it as an update if we can't determine
            return false;
        }
    }
}