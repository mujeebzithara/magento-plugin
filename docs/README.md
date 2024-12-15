# Zithara Webhook Module Documentation

## Overview
The Zithara Webhook module enables real-time data synchronization between Magento 2 and Zithara's API. It handles various events including cart operations, customer actions, and order processing.

## Features
- Real-time cart synchronization
- Customer event tracking
- Order status updates
- Secure API authentication
- Queue-based processing
- Robust error handling and logging

## Architecture

### Core Components

#### 1. Event Observers
- Cart Events (`CartSave`, `CartUpdate`, etc.)
- Customer Events (`CustomerSave`, `CustomerLogin`, etc.)
- Order Events (`OrderCreate`, `OrderUpdate`, etc.)
- Payment Events (`PaymentCapture`, `PaymentRefund`, etc.)

#### 2. Queue System
- Message Publishers
- Queue Processors
- Event-specific handlers

#### 3. API Integration
- Authentication management
- API endpoints configuration
- Request/Response handling

### Directory Structure
```
app/code/Zithara/Webhook/
├── Block/
│   └── Adminhtml/
│       └── Config/
│           └── Edit/
│               └── Form.php
├── Controller/
│   └── Adminhtml/
│       └── Config/
│           ├── Index.php
│           └── Save.php
├── Helper/
│   └── Data.php
├── Model/
│   ├── Config.php
│   ├── Config/
│   │   └── ApiEndpoints.php
│   ├── Queue/
│   │   ├── Api/
│   │   │   └── ZitharaApiClient.php
│   │   ├── CartProcessor.php
│   │   ├── CustomerProcessor.php
│   │   ├── OrderProcessor.php
│   │   └── WebhookProcessor.php
│   └── ResourceModel/
│       └── Config/
│           └── Collection.php
├── Observer/
│   ├── Cart/
│   │   ├── CartAddProduct.php
│   │   ├── CartDelete.php
│   │   ├── CartRemoveItem.php
│   │   ├── CartSave.php
│   │   └── CartUpdate.php
│   ├── Customer/
│   │   ├── CustomerAddressSave.php
│   │   ├── CustomerDelete.php
│   │   ├── CustomerEdit.php
│   │   ├── CustomerLogin.php
│   │   ├── CustomerLogout.php
│   │   └── CustomerSave.php
│   ├── Order/
│   │   ├── OrderCancel.php
│   │   ├── OrderCreate.php
│   │   ├── OrderPayment.php
│   │   ├── OrderRefund.php
│   │   └── OrderUpdate.php
│   └── Payment/
│       ├── PaymentCapture.php
│       ├── PaymentFail.php
│       └── PaymentVoid.php
└── etc/
    ├── adminhtml/
    │   ├── menu.xml
    │   └── routes.xml
    ├── communication.xml
    ├── config.xml
    ├── crontab.xml
    ├── db_schema.xml
    ├── di.xml
    ├── events.xml
    ├── module.xml
    ├── queue_consumer.xml
    ├── queue_publisher.xml
    └── queue_topology.xml
```

## Configuration

### Admin Configuration
Path: `Stores > Configuration > Zithara > Webhook Settings`

#### General Settings
- Enable/Disable Module
- API Credentials
  - Client ID
  - Client Secret
- Event Selection
- Retry Settings
  - Maximum Retries
  - Retry Delay

### Queue Configuration
- Queue Names:
  - `zithara.webhook.events`
  - `zithara.customer.events`
  - `zithara.order.events`
  - `zithara.cart.events`

## API Integration

### Authentication
The module uses OAuth2 for API authentication:
1. Obtains access token using client credentials
2. Manages token expiration and refresh
3. Securely stores credentials

### Endpoints
Base URL: `https://dev-pos-api.zithara.com/v1`

#### Available Endpoints:
- `/generate-access-token` - Authentication
- `/cart` - Cart operations
- `/customer` - Customer operations
- `/order` - Order operations

### Data Formats

#### Cart Data Structure
```json
{
    "customer": {
        "platform_customer_id": "string",
        "first_name": "string",
        "last_name": "string",
        "name": "string",
        "whatsapp_phone_number": "string",
        "email": "string|false",
        "custom_attributes": {}
    },
    "cart_item": [
        {
            "platform_cart_item_id": "string",
            "price": "float",
            "product_id": "integer",
            "quantity": "float"
        }
    ],
    "cart": {
        "currency": "string",
        "platform_cart_id": "string",
        "name": "string",
        "created_at": "datetime",
        "total_tax": "float",
        "total_price": "float",
        "shopify_customer_id": "integer"
    }
}
```

## Event Handling

### Cart Events
- `checkout_cart_save_after`
- `checkout_cart_update_items_after`
- `checkout_cart_product_add_after`
- `checkout_cart_delete`
- `sales_quote_remove_item`

### Customer Events
- `customer_save_after`
- `customer_login`
- `customer_logout`
- `customer_address_save_after`
- `customer_delete_after`

### Order Events
- `sales_order_save_after`
- `sales_order_place_after`
- `order_cancel_after`
- `sales_order_creditmemo_save_after`

### Payment Events
- `sales_order_payment_pay`
- `sales_order_payment_capture`
- `sales_order_payment_void`
- `payment_cancel`

## Error Handling

### Logging
- Location: `var/log/zithara_webhook.log`
- Log Levels:
  - ERROR: Critical failures
  - WARNING: Non-critical issues
  - INFO: Important operations
  - DEBUG: Detailed debugging

### Retry Mechanism
- Failed events are requeued
- Configurable retry attempts
- Exponential backoff

## Installation

### Requirements
- Magento 2.4.x
- PHP 7.4 or higher
- RabbitMQ/MySQL for queue management

### Steps
1. Copy module to `app/code/Zithara/Webhook`
2. Enable module:
   ```bash
   bin/magento module:enable Zithara_Webhook
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento cache:clean
   ```
3. Configure module in admin panel
4. Set up cron jobs

## Troubleshooting

### Common Issues

1. Queue Processing Issues
   - Check RabbitMQ connection
   - Verify queue consumer is running
   - Check for queue backlog

2. API Connection Issues
   - Verify credentials
   - Check API endpoint availability
   - Review SSL certificates

3. Event Processing Issues
   - Check event observer registration
   - Verify event data structure
   - Review error logs

### Debug Mode
Enable debug logging in admin configuration for detailed troubleshooting.

## Security

### Data Protection
- Sensitive data encryption
- Secure credential storage
- Token-based authentication

### Best Practices
- Input validation
- Error handling
- Rate limiting
- Audit logging

## Performance

### Optimization
- Asynchronous processing
- Batch processing
- Queue management
- Cache utilization

### Monitoring
- Queue length
- Processing time
- Error rates
- API response times