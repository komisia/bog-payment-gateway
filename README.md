# WooCommerce Payment Gateway for Bank of Georgia Online Payment API

[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-4.0%2B-96588A.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.2%2B-777BB4.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPLv2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A secure and reliable WooCommerce payment gateway plugin that integrates Bank of Georgia's Online Payment API, enabling Georgian merchants to accept online payments through BOG's payment system.

## 📋 Description

This plugin provides seamless integration between WooCommerce and Bank of Georgia's payment gateway, allowing e-commerce stores in Georgia to process payments securely through BOG's OAuth 2.0 authenticated API.

### 🌟 Key Features

- **Secure OAuth 2.0 Authentication** - Industry-standard secure authentication
- **Automatic Payment Capture** - Streamlined payment processing
- **Georgian Lari (GEL) Support** - Native support for Georgian currency
- **Real-time Payment Updates** - Instant callback notifications for payment status
- **Test Mode** - Safe testing environment for development
- **Comprehensive Logging** - Detailed debug logs for troubleshooting
- **Easy Admin Configuration** - User-friendly settings interface

## 📚 Official Documentation

For Bank of Georgia API documentation and integration guidelines, please visit:
**[Bank of Georgia Payment API Documentation](https://api.bog.ge/docs/en/payments/introduction)**

## 🚀 Installation

### WordPress Admin Installation

1. Download the plugin ZIP file
2. Navigate to WordPress Admin → Plugins → Add New
3. Click "Upload Plugin" and select the ZIP file
4. Click "Install Now" and then "Activate"

### Manual Installation

1. Upload the plugin files to `/wp-content/plugins/bog-payment-gateway/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to WooCommerce → Settings → Payments
4. Enable "Bank of Georgia" payment method
5. Configure your API credentials

## ⚙️ Configuration

### Required Settings

1. **API Credentials**
   - **Client ID**: Provided by Bank of Georgia
   - **Client Secret**: Provided by Bank of Georgia
   
2. **Environment Settings**
   - **Test Mode**: Enable for development/testing
   - **Debug Mode**: Enable for detailed logging

### Prerequisites

- ✅ WordPress 5.0 or higher
- ✅ WooCommerce 4.0 or higher
- ✅ PHP 7.2 or higher
- ✅ SSL Certificate (required for production)
- ✅ Store currency set to Georgian Lari (GEL)

## 🔧 API Integration

### Authentication
The plugin uses OAuth 2.0 client credentials flow for secure authentication with BOG's API.

### Endpoints Used
- **Authentication**: `POST /auth/realms/bog/protocol/openid-connect/token`
- **Create Order**: `POST /payments/v1/ecommerce/orders`
- **Payment Status**: `GET /payments/v1/receipt/{order_id}`

### Callback URL
The plugin automatically registers a callback endpoint:
```
https://your-site.com/wc-api/bog_callback
```

## 🔒 Security Features

- **HTTPS Only** - All API communications over secure connections
- **Signature Validation** - SHA256withRSA signature verification for callbacks
- **Encrypted Credentials** - API credentials stored securely in database
- **PCI Compliance** - Payment processing on BOG's secure hosted pages

## 📖 Usage

### For Merchants

1. **Setup**: Configure API credentials in WooCommerce settings
2. **Testing**: Enable test mode and perform test transactions
3. **Production**: Disable test mode and start accepting real payments

### For Customers

1. Select "Bank of Georgia" at checkout
2. Redirect to BOG's secure payment page
3. Complete payment with preferred method
4. Return to merchant site with order confirmation

## 🐛 Debugging

Enable debug mode in settings to log:
- API authentication requests
- Order creation requests
- Callback notifications
- Error messages

Logs are stored in: `/wp-content/uploads/wc-logs/`

## ❓ Frequently Asked Questions

### How do I get API credentials?
Contact Bank of Georgia's merchant services to register and receive your Client ID and Client Secret.

### Is test mode available?
Yes, enable test mode in settings to use test credentials for development.

### What currencies are supported?
Currently, only Georgian Lari (GEL) is supported.

### How are refunds handled?
Refund notifications are processed automatically via callbacks. Manual refunds should be initiated through the Bank of Georgia merchant portal.

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📝 Changelog

### Version 1.0.0 (Current)
- Initial release
- OAuth 2.0 authentication implementation
- Automatic payment capture
- Callback handling for payment notifications
- Test mode support
- Comprehensive debug logging

## 📄 License

This plugin is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.
```

## 🏦 About Bank of Georgia

Bank of Georgia is the leading banking institution in Georgia, providing comprehensive payment solutions for businesses. Learn more at [bankofgeorgia.ge](https://bankofgeorgia.ge)

## 🔍 Keywords

WooCommerce Payment Gateway, Bank of Georgia, BOG Payment Integration, Georgian Payment Gateway, GEL Payment Processing, Georgia E-commerce, WooCommerce BOG Plugin, Bank of Georgia API, Georgian Online Payments, საქართველოს ბანკი, ონლაინ გადახდები

## 🌐 Links

- [Bank of Georgia API Documentation](https://api.bog.ge/docs/en/payments/introduction)
- [WooCommerce Documentation](https://woocommerce.com/documentation/)
- [WordPress Plugin Guidelines](https://developer.wordpress.org/plugins/)

---

**For Support**: Please open an issue in this repository or contact Bank of Georgia merchant support.

**Developed for**: Georgian e-commerce merchants using WooCommerce
**API Version**: Bank of Georgia Payment API v1