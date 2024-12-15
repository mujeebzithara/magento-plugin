# Resource Model Documentation

## Config.php
`app/code/Zithara/Webhook/Model/ResourceModel/Config.php`

### Purpose
Manages database operations for webhook configuration.

### Key Methods
- `_construct()`: Initializes resource model
- `_beforeSave()`: Pre-save processing
- `_afterLoad()`: Post-load processing

### Dependencies
- `AbstractDb`
- `LoggerInterface`

## Collection.php
`app/code/Zithara/Webhook/Model/ResourceModel/Config/Collection.php`

### Purpose
Manages collections of webhook configurations.

### Key Methods
- `_construct()`: Initializes collection
- `addActiveFilter()`: Filters active configurations
- `addEventTypeFilter()`: Filters by event type