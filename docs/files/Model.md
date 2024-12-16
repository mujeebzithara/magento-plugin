# Model Documentation

## Config.php
`app/code/Zithara/Webhook/Model/Config.php`

### Purpose
Manages webhook configuration data and validation.

### Key Methods
- `validateBeforeSave()`: Validates configuration before saving
- `_beforeSave()`: Pre-save processing
- `_afterLoad()`: Post-load processing

### Dependencies
- `AbstractModel`
- `LoggerInterface`

## ApiEndpoints.php
`app/code/Zithara/Webhook/Model/Config/ApiEndpoints.php`

### Purpose
Centralizes API endpoint management.

### Constants
- `BASE_URL`
- `TOKEN_ENDPOINT`
- `CART_ENDPOINT`
- `CUSTOMER_ENDPOINT`
- `ORDER_ENDPOINT`

## Queue Processors

### CartProcessor.php
`app/code/Zithara/Webhook/Model/Queue/CartProcessor.php`

#### Purpose
Processes cart events and sends to Zithara API.

#### Key Methods
- `process()`: Main processing method
- `transformCartData()`: Transforms cart data
- `sendToZitharaApi()`: Sends data to API

#### Dependencies
- `Curl`
- `JsonHelper`
- `ConfigFactory`
- `LoggerInterface`

### CustomerProcessor.php
Similar structure for customer events.

### OrderProcessor.php
Similar structure for order events.