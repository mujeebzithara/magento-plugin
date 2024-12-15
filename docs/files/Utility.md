# Utility Classes Documentation

## Data Transformers

### CartDataTransformer.php
`app/code/Zithara/Webhook/Model/Queue/Transformer/CartDataTransformer.php`

### Purpose
Transforms cart data for API compatibility.

### Key Methods
- `transform()`: Main transformation method
- `transformCustomerData()`: Transforms customer data
- `transformCartItems()`: Transforms cart items
- `transformCartData()`: Transforms cart data

## Validators

### CartDataValidator.php
`app/code/Zithara/Webhook/Model/Queue/Validator/CartDataValidator.php`

### Purpose
Validates cart data before processing.

### Key Methods
- `validate()`: Main validation method
- `validateCart()`: Validates cart data
- `validateCustomer()`: Validates customer data
- `validateCartItems()`: Validates cart items