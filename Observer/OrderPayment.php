<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class OrderPayment implements ObserverInterface
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
            $payment = $observer->getEvent()->getPayment();
            
            // Log the event for debugging
            $this->logger->info('OrderPayment Observer: Payment event triggered', [
                'event_name' => $observer->getEvent()->getName()
            ]);
            
            // Defensive check for payment object
            if (!$payment || !is_object($payment)) {
                $this->logger->error('OrderPayment: Invalid payment object.');
                return;
            }

            $order = $payment->getOrder();

           //$this->logger->info('OrderPayment Observer: Payment Data', [
            //    'payment' => json_encode($payment->getData(), JSON_PRETTY_PRINT)
            //]);
            
            
            // Defensive check for order object
            if (!$order || !is_object($order)) {
                $this->logger->error('OrderPayment: Invalid order object.');
                return;
            }

            // Get order and payment data with defensive checks
            $orderData = $order->getData() ?: [];
            $paymentData = $payment->getData() ?: [];

            // Remove sensitive payment data
            unset($paymentData['cc_number']);
            unset($paymentData['cc_cid']);
            unset($paymentData['cc_exp_month']);
            unset($paymentData['cc_exp_year']);

            // Get additional payment information
            $additionalInfo = $payment->getAdditionalInformation() ?: [];

            // Get order items with defensive checks
            $orderItems = [];
            foreach ($order->getAllItems() as $item) {

                 $this->logger->info('OrderItem: Order item data', [
                     'item_data' => json_encode($item->getData(), JSON_PRETTY_PRINT)
                 ]);

                if ($item && is_object($item)) {
                    $orderItems[] = [
                        'item_id' => $item->getItemId() ?? '',
                        'product_id' => $item->getProductId() ?? '',
                        'sku' => $item->getSku() ?? '',
                        'name' => $item->getName() ?? '',
                        'qty_ordered' => $item->getQtyOrdered() ?? 0,
                        'qty_invoiced' => $item->getQtyInvoiced() ?? 0,
                        'qty_shipped' => $item->getQtyShipped() ?? 0,
                        'price' => $item->getPrice() ?? 0,
                        'base_price' => $item->getBasePrice() ?? 0,
                        'row_total' => $item->getRowTotal() ?? 0,
                        'base_row_total' => $item->getBaseRowTotal() ?? 0,
                        'tax_amount' => $item->getTaxAmount() ?? 0,
                        'base_tax_amount' => $item->getBaseTaxAmount() ?? 0,
                        'discount_amount' => $item->getDiscountAmount() ?? 0,
                        'base_discount_amount' => $item->getBaseDiscountAmount() ?? 0
                    ];
                }
            }

            // Get payment transaction data with defensive checks
            $transactionData = [];
            if ($payment->getTransactionId()) {
                $transaction = $payment->getTransactionById($payment->getTransactionId());
                if ($transaction && is_object($transaction)) {
                    $transactionData = [
                        'transaction_id' => $transaction->getTxnId() ?? '',
                        'transaction_type' => $transaction->getTxnType() ?? '',
                        'is_closed' => $transaction->getIsClosed() ?? false,
                        'additional_information' => $transaction->getAdditionalInformation() ?: []
                    ];
                }
            }

            // Additional defensive checks for critical data
            $webhookData = [
                'order' => array_merge($orderData, [
                    'order_id' => $order->getId() ?? '',
                    'increment_id' => $order->getIncrementId() ?? '',
                    'status' => $order->getStatus() ?? '',
                    'state' => $order->getState() ?? '',
                    'customer_id' => $order->getCustomerId() ?? '',
                    'customer_email' => $order->getCustomerEmail() ?? '',
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
                    'shipping_description' => $order->getShippingDescription() ?? ''
                ]),
                'payment' => array_merge($paymentData, [
                    'payment_id' => $payment->getId() ?? '',
                    'method' => $payment->getMethod() ?? '',
                    'amount_paid' => $payment->getAmountPaid() ?? 0,
                    'amount_authorized' => $payment->getAmountAuthorized() ?? 0,
                    'base_amount_paid' => $payment->getBaseAmountPaid() ?? 0,
                    'base_amount_authorized' => $payment->getBaseAmountAuthorized() ?? 0,
                    'last_trans_id' => $payment->getLastTransId() ?? '',
                    'additional_information' => $additionalInfo,
                    'transaction' => $transactionData
                ]),
                'items' => $orderItems,
                'billing_address' => $order->getBillingAddress() ? $order->getBillingAddress()->getData() : [],
                'shipping_address' => $order->getShippingAddress() ? $order->getShippingAddress()->getData() : []
            ];

            // Log the webhook data being sent
            $this->logger->info('OrderPayment: Preparing to send webhook', [
                'order_id' => $order->getId(),
                'payment_id' => $payment->getId(),
                'method' => $payment->getMethod()
            ]);

            $this->webhookHelper->sendWebhook('order_payment', $webhookData);

            $this->logger->info('OrderPayment: Webhook sent successfully', [
                'order_id' => $order->getId(),
                'payment_id' => $payment->getId()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('OrderPayment Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
