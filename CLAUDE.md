# Bank of Georgia WooCommerce Payment Gateway Plugin

## Overview
This plugin integrates Bank of Georgia's payment API with WooCommerce, allowing merchants to accept payments through BOG's secure payment system.

## Implementation Plan

### 1. Plugin Structure
```
bog-payment-gateway/
├── bog-payment-gateway.php          # Main plugin file
├── includes/
│   ├── class-bog-api-client.php    # API client for BOG authentication and requests
│   ├── class-bog-payment-gateway.php # Main payment gateway class
│   └── class-bog-callback-handler.php # Webhook handler for payment notifications
├── assets/
│   └── bog-logo.png                # Bank logo for checkout
└── languages/                      # Translation files
```

### 2. Key Components

#### A. Authentication System
- OAuth 2.0 implementation using client credentials flow
- Endpoint: `https://oauth2.bog.ge/auth/realms/bog/protocol/openid-connect/token`
- Store and manage JWT tokens with expiration handling
- Auto-refresh tokens before expiration

#### B. Payment Gateway Class
- Extends `WC_Payment_Gateway`
- Configurable settings:
  - Client ID (required)
  - Client Secret (required)
  - Test/Live mode toggle
  - Debug logging toggle

#### C. Order Processing Flow
1. Customer proceeds to checkout
2. Plugin creates order request with BOG API
3. Customer redirected to BOG payment page
4. After payment, callback received with status
5. Order status updated in WooCommerce

#### D. API Endpoints Used
- Authentication: `POST /auth/realms/bog/protocol/openid-connect/token`
- Create Order: `POST /payments/v1/ecommerce/orders`
- Get Payment Details: `GET /payments/v1/receipt/{order_id}`

### 3. Technical Requirements

#### Security
- HTTPS only for callbacks
- Validate callback signatures using SHA256withRSA
- Secure storage of API credentials
- Never expose client_secret in frontend

#### Payment Configuration
- Default capture mode: `automatic`
- Default language: `ka` (Georgian)
- Default currency: `GEL`
- Support for success/fail redirect URLs

#### Error Handling
- Comprehensive logging for debugging
- Graceful fallback for API failures
- Clear error messages for admin and customers

### 4. Implementation Steps

1. **Plugin Bootstrap**
   - Register with WooCommerce payment gateways
   - Load necessary classes
   - Set up activation/deactivation hooks

2. **API Client Class**
   - Handle OAuth authentication
   - Manage token lifecycle
   - Provide methods for API requests
   - Implement retry logic for failed requests

3. **Payment Gateway Integration**
   - Admin settings interface
   - Checkout form customization
   - Order processing logic
   - Redirect handling

4. **Callback Handler**
   - Register webhook endpoint
   - Validate signatures
   - Process payment notifications
   - Update order status

5. **Testing Requirements**
   - Test authentication flow
   - Test successful payment
   - Test failed payment
   - Test callback processing
   - Test refund scenarios

### 5. Database Requirements
- Store BOG order IDs with WooCommerce orders
- Cache authentication tokens
- Log transaction history

### 6. Admin Features
- View payment logs
- Test API connection
- Manual payment status check
- Credential validation

### 7. Customer Experience
- Seamless checkout flow
- Clear payment status messages
- Support for saved cards (future enhancement)
- Mobile-responsive payment page

## API Integration Details

### Authentication Token Management
```php
// Token request format
$auth_data = [
    'grant_type' => 'client_credentials'
];
// Basic auth with client_id:client_secret
```

### Order Request Structure
```php
$order_data = [
    'callback_url' => 'https://site.com/wc-api/bog_callback',
    'redirect_urls' => [
        'success' => $order->get_checkout_order_received_url(),
        'fail' => wc_get_checkout_url()
    ],
    'purchase_units' => [
        'total_amount' => $order->get_total(),
        'currency' => 'GEL',
        'basket' => [] // Line items
    ],
    'capture' => 'automatic',
    'intent' => 'capture',
    'locale' => 'ka'
];
```

### Callback Processing
- Verify signature with public key
- Parse payment status
- Update WooCommerce order
- Send HTTP 200 response

## Testing Checklist
- [ ] Plugin activates without errors
- [ ] Settings page loads and saves credentials
- [ ] Authentication with BOG API works
- [ ] Payment redirect works
- [ ] Successful payment updates order
- [ ] Failed payment handled correctly
- [ ] Callbacks processed properly
- [ ] Logs generated for debugging

## Security Considerations
- API credentials encrypted in database
- All API calls over HTTPS
- Callback signature validation mandatory
- No sensitive data in logs
- PCI compliance through BOG hosted payment page

## Future Enhancements
- Saved card functionality
- Partial refunds
- Multi-currency support
- Apple Pay/Google Pay integration
- Installment payments