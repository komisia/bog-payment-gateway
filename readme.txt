=== Bank of Georgia Payment Gateway ===
Contributors: yourname
Tags: woocommerce, payment gateway, bank of georgia, bog, georgia
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments through Bank of Georgia payment system in WooCommerce.

== Description ==

This plugin integrates Bank of Georgia's payment API with WooCommerce, allowing merchants to accept secure online payments through BOG's payment system.

= Features =

* Secure OAuth 2.0 authentication
* Automatic payment capture
* Support for Georgian Lari (GEL) currency
* Real-time payment status updates via callbacks
* Test mode for development
* Comprehensive logging for debugging
* Admin panel for easy configuration

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/bog-payment-gateway` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to WooCommerce > Settings > Payments
4. Enable "Bank of Georgia" payment method
5. Configure your API credentials (Client ID and Client Secret)
6. Save settings

== Configuration ==

1. **Client ID & Client Secret**: Obtain these from Bank of Georgia
2. **Test Mode**: Enable for testing with test credentials
3. **Debug Mode**: Enable to log API interactions for troubleshooting

= Required Settings =

* Currency must be set to Georgian Lari (GEL)
* SSL certificate required for production use
* HTTPS required for callback URLs

== Frequently Asked Questions ==

= How do I get API credentials? =

Contact Bank of Georgia to register as a merchant and receive your Client ID and Client Secret.

= Is test mode available? =

Yes, you can enable test mode and use test credentials to simulate payments without processing real transactions.

= What currency is supported? =

Currently, only Georgian Lari (GEL) is supported.

= Are refunds supported? =

Refund notifications are processed automatically via callbacks. Manual refunds should be processed through the Bank of Georgia portal.

== Changelog ==

= 1.0.0 =
* Initial release
* OAuth 2.0 authentication
* Automatic payment capture
* Callback handling for payment notifications
* Test mode support
* Debug logging

== Requirements ==

* WordPress 5.0 or higher
* WooCommerce 4.0 or higher
* PHP 7.2 or higher
* SSL certificate (for production)
* Georgian Lari (GEL) as store currency