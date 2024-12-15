# Plugin Documentation

## PaymentPlugin.php
`app/code/Zithara/Webhook/Plugin/PaymentPlugin.php`

### Purpose
Intercepts payment operations for webhook processing.

### Key Methods
- `afterPay()`: Processes after payment completion
- `afterPlace()`: Processes after payment placement

### Dependencies
- `ManagerInterface`
- `LoggerInterface`

### Usage Example
```php
public function afterPay(Payment $subject, $result)
{
    $this->eventManager->dispatch('sales_order_payment_pay', ['payment' => $subject]);
    return $result;
}
```