# Helper Documentation

## Data.php
`app/code/Zithara/Webhook/Helper/Data.php`

### Purpose
Provides common utility functions for webhook operations.

### Key Methods
- `sendWebhook()`: Sends webhook data to queue
- `validateData()`: Validates webhook data
- `formatData()`: Formats data for API

### Dependencies
- `PublisherInterface`
- `SerializerInterface`
- `ConfigFactory`
- `LoggerInterface`

### Usage Example
```php
$webhookHelper->sendWebhook('cart_update', $data);
```