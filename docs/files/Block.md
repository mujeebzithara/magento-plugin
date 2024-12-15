# Block Documentation

## Form.php
`app/code/Zithara/Webhook/Block/Adminhtml/Config/Edit/Form.php`

### Purpose
Handles the admin configuration form rendering and data management.

### Key Methods
- `_prepareForm()`: Creates and configures the admin form
- `addFormFields()`: Adds individual form fields
- `getEventTypeOptions()`: Provides event type options for selection

### Dependencies
- `Context`
- `Registry`
- `FormFactory`
- `ConfigFactory`
- `LoggerInterface`

### Usage Example
```php
$form = $this->_formFactory->create([
    'data' => [
        'id' => 'edit_form',
        'action' => $this->getUrl('*/*/save'),
        'method' => 'post'
    ]
]);
```