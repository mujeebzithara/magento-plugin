# API Documentation

## Authentication

### Generate Access Token
- **Endpoint**: `POST /generate-access-token`
- **Headers**:
  ```
  client_id: YOUR_CLIENT_ID
  client_secret: YOUR_CLIENT_SECRET
  ```
- **Response**:
  ```json
  {
    "access_token": "string",
    "expires_in": "integer"
  }
  ```

## Cart Operations

### Create/Update Cart
- **Endpoint**: `POST /cart`
- **Headers**:
  ```
  Authorization: ACCESS_TOKEN
  Content-Type: application/json
  ```
- **Request Body**:
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
- **Response**:
  ```json
  {
    "status": "integer",
    "message": "string",
    "data": {}
  }
  ```

## Error Codes

### HTTP Status Codes
- 200: Success
- 201: Created
- 400: Bad Request
- 401: Unauthorized
- 403: Forbidden
- 404: Not Found
- 500: Internal Server Error

### Error Response Format
```json
{
  "status": "integer",
  "error": "string",
  "message": "string"
}
```