<?php
namespace Zithara\Webhook\Plugin;

use Magento\Sales\Model\Order\Payment;
use Magento\Framework\Event\ManagerInterface;
use Psr\Log\LoggerInterface;

class PaymentPlugin
{
    protected $eventManager;
    protected $logger;

    public function __construct(
        ManagerInterface $eventManager,
        LoggerInterface $logger
    ) {
        $this->eventManager = $eventManager;
        $this->logger = $logger;
    }

    public function afterPay(Payment $subject, $result)
    {
        try {
            $this->logger->info('PaymentPlugin: Payment completed, dispatching event');
            
            // Dispatch the event
            $this->eventManager->dispatch('sales_order_payment_pay', ['payment' => $subject]);
            
            // Fetch the order from the payment
            $order = $subject->getOrder();
            
            if ($order) {
                // Convert the order to an array for easier logging
                $orderArray = $this->orderToArray($order);
                
                // Log the complete order as JSON
                //$this->logger->info('PaymentPlugin: Order details', [
                  //  'payment_id' => $subject->getId(),
                    //'order_id' => $order->getId(),
                   // 'order_details' => json_encode($orderArray, JSON_PRETTY_PRINT)
               // ]);
            } else {
                $this->logger->info('PaymentPlugin: No associated order found');
            }
            
            $this->logger->info('PaymentPlugin: Event dispatched successfully', [
                'payment_id' => $subject->getId(),
                'order_id' => $order ? $order->getId() : null
            ]);
        } catch (\Exception $e) {
            $this->logger->error('PaymentPlugin Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $result;
    }


    public function afterPlace(Payment $subject, $result)
    {
        try {
            $this->logger->info('PaymentPlugin: Payment placed, dispatching events');
            
            // Dispatch start event
            $this->eventManager->dispatch('sales_order_payment_place_start', ['payment' => $subject]);
            
            // Dispatch end event
            $this->eventManager->dispatch('sales_order_payment_place_end', ['payment' => $subject]);
            
            $this->logger->info('PaymentPlugin: Place events dispatched successfully', [
                'payment_id' => $subject->getId(),
                'order_id' => $subject->getOrder() ? $subject->getOrder()->getId() : null
            ]);
        } catch (\Exception $e) {
            $this->logger->error('PaymentPlugin Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $result;
    }

    private function orderToArray(\Magento\Sales\Model\Order $order)
    {
        // Convert the order to an array, including nested entities
        $orderData = $order->getData(); // This includes the basic order data
        $itemsData = [];

        // Extract order items if needed
        foreach ($order->getAllItems() as $item) {
            $itemsData[] = $item->getData();
        }

        // Add items to the order data
        $orderData['items'] = $itemsData;

        // Optionally, you can extract more nested data such as billing/shipping address, etc.
        $orderData['billing_address'] = $order->getBillingAddress() ? $order->getBillingAddress()->getData() : null;
        $orderData['shipping_address'] = $order->getShippingAddress() ? $order->getShippingAddress()->getData() : null;

        return $orderData;
    }
}