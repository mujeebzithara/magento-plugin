# Observer Documentation

## Cart Observers

### 1. CartAddProduct.php
**Purpose**: Observes when products are added to cart
**Event**: `checkout_cart_product_add_after`
**Key Functions**:
- Captures product addition details
- Formats cart data with new product
- Publishes to cart events queue

### 2. CartDelete.php
**Purpose**: Observes cart deletion events
**Event**: `checkout_cart_delete`
**Key Functions**:
- Handles cart deletion
- Cleans up associated data
- Notifies about cart removal

### 3. CartRemoveItem.php
**Purpose**: Observes item removal from cart
**Event**: `sales_quote_remove_item`
**Key Functions**:
- Tracks item removal
- Updates cart totals
- Sends updated cart data

### 4. CartSave.php
**Purpose**: Observes cart save operations
**Event**: `checkout_cart_save_after`
**Key Functions**:
- Captures cart updates
- Formats data for API
- Handles customer associations

### 5. CartUpdate.php
**Purpose**: Observes cart update events
**Event**: `checkout_cart_update_items_after`
**Key Functions**:
- Tracks quantity changes
- Updates pricing
- Manages cart modifications

## Customer Observers

### 1. CustomerAddressSave.php
**Purpose**: Observes customer address changes
**Event**: `customer_address_save_after`
**Key Functions**:
- Captures address updates
- Validates address data
- Updates customer profile

### 2. CustomerDelete.php
**Purpose**: Observes customer deletion
**Event**: `customer_delete_after`
**Key Functions**:
- Handles customer removal
- Cleans associated data
- Notifies systems

### 3. CustomerEdit.php
**Purpose**: Observes customer profile edits
**Event**: `customer_save_after_data_object`
**Key Functions**:
- Tracks profile changes
- Updates customer data
- Syncs modifications

### 4. CustomerLogin.php
**Purpose**: Observes customer login events
**Event**: `customer_login`
**Key Functions**:
- Tracks login activity
- Updates last login
- Handles session data

### 5. CustomerLogout.php
**Purpose**: Observes customer logout events
**Event**: `customer_logout`
**Key Functions**:
- Handles session end
- Updates activity logs
- Cleans up session data

### 6. CustomerSave.php
**Purpose**: Observes customer data saves
**Event**: `customer_save_after`
**Key Functions**:
- Validates customer data
- Updates profile information
- Syncs customer changes

## Order Observers

### 1. OrderCancel.php
**Purpose**: Observes order cancellations
**Event**: `order_cancel_after`
**Key Functions**:
- Handles cancellation logic
- Updates inventory
- Notifies systems

### 2. OrderCreate.php
**Purpose**: Observes new order creation
**Event**: `sales_order_place_after`
**Key Functions**:
- Processes new orders
- Validates order data
- Initiates fulfillment

### 3. OrderPayment.php
**Purpose**: Observes order payment events
**Event**: `sales_order_payment_pay`
**Key Functions**:
- Tracks payment status
- Updates order state
- Handles payment processing

### 4. OrderRefund.php
**Purpose**: Observes order refunds
**Event**: `sales_order_creditmemo_save_after`
**Key Functions**:
- Processes refunds
- Updates order status
- Handles inventory returns

### 5. OrderUpdate.php
**Purpose**: Observes order updates
**Event**: `sales_order_save_after`
**Key Functions**:
- Tracks order changes
- Updates status
- Syncs modifications

## Payment Observers

### 1. PaymentAuthorization.php
**Purpose**: Observes payment authorizations
**Event**: `payment_authorize`
**Key Functions**:
- Handles authorization
- Validates payment data
- Updates order status

### 2. PaymentCancel.php
**Purpose**: Observes payment cancellations
**Event**: `payment_cancel`
**Key Functions**:
- Processes cancellations
- Updates payment status
- Handles refunds

### 3. PaymentCapture.php
**Purpose**: Observes payment captures
**Event**: `sales_order_payment_capture`
**Key Functions**:
- Handles payment capture
- Updates transaction data
- Finalizes payment

### 4. PaymentFail.php
**Purpose**: Observes payment failures
**Event**: `payment_fail`
**Key Functions**:
- Handles failures
- Updates order status
- Notifies systems

### 5. PaymentFraud.php
**Purpose**: Observes fraudulent payments
**Event**: `payment_fraud`
**Key Functions**:
- Detects fraud
- Handles suspicious activity
- Updates security measures

### 6. PaymentMethodChange.php
**Purpose**: Observes payment method changes
**Event**: `payment_method_change`
**Key Functions**:
- Updates payment method
- Validates new method
- Updates order data

### 7. PaymentReview.php
**Purpose**: Observes payment reviews
**Event**: `payment_review`
**Key Functions**:
- Handles review process
- Updates payment status
- Manages approvals

### 8. PaymentVoid.php
**Purpose**: Observes payment voids
**Event**: `payment_void`
**Key Functions**:
- Processes void requests
- Updates payment status
- Handles cancellations

## Visitor Observers

### 1. NewVisitor.php
**Purpose**: Observes new visitor sessions
**Event**: `visitor_init`
**Key Functions**:
- Tracks new visitors
- Initializes session
- Captures visit data

### 2. VisitorActivity.php
**Purpose**: Observes visitor activities
**Event**: `visitor_activity`
**Key Functions**:
- Tracks user actions
- Updates activity logs
- Monitors behavior

### 3. VisitorInit.php
**Purpose**: Observes visitor initialization
**Event**: `visitor_init`
**Key Functions**:
- Initializes tracking
- Sets up session
- Captures initial data

## Quote Observer

### 1. QuoteSave.php
**Purpose**: Observes quote saves
**Event**: `sales_quote_save_after`
**Key Functions**:
- Tracks quote changes
- Updates pricing
- Manages cart data
