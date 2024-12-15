# Controller Documentation

## Index.php
`app/code/Zithara/Webhook/Controller/Adminhtml/Config/Index.php`

### Purpose
Handles the admin configuration page display.

### Key Methods
- `execute()`: Renders the configuration page
- `_isAllowed()`: Checks admin permissions

### Dependencies
- `Context`
- `PageFactory`
- `LoggerInterface`

## Save.php
`app/code/Zithara/Webhook/Controller/Adminhtml/Config/Save.php`

### Purpose
Handles saving webhook configuration data.

### Key Methods
- `execute()`: Processes and saves form data
- `_isAllowed()`: Checks admin permissions

### Dependencies
- `Context`
- `ConfigFactory`
- `LoggerInterface`