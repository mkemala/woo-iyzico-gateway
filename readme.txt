=== iyzico Payment Gateway for WooCommerce ===
Contributors: mkemala
Tags: iyzico, woocommerce, payment gateway, turkey, 3d secure
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A from-scratch, minimal WooCommerce payment gateway for iyzico's hosted Checkout Form, with mandatory 3D Secure and a built-in health check system.

== Description ==

This plugin connects WooCommerce to iyzico's hosted Checkout Form (Turkey's leading payment service provider), using iyzico's own official PHP SDK (bundled, no Composer required). Card data never touches your server — the customer is redirected to iyzico's own payment page for card entry and 3D Secure verification, then redirected back once the payment result is confirmed server-to-server.

**Why this plugin exists**

iyzico's own official WooCommerce plugin has a low rating on WordPress.org and inconsistent update history. This plugin is a minimal, readable, fully auditable alternative built around the same official SDK.

**Features**

* Hosted Checkout Form flow — minimal PCI-DSS scope, mandatory 3D Secure (`forceThreeDS`)
* Server-to-server payment verification — the plugin never trusts a client-supplied token, it independently confirms the result with iyzico using your own secret key
* Basic refunds, right from the WooCommerce order screen
* Brand-matchable checkout UI (colors, trust badges) configurable from the settings screen
* Optional payment icon: choose one of three built-in, brand-neutral icons, or upload your own image — the plugin bundles no logos of its own
* Auto-detection panel for the callback URL and your server's outbound IP, ready to paste into the iyzico merchant panel
* Built-in health check system: verifies callback reachability, connectivity to iyzico, and API key presence — on demand or automatically once a day, with email alerts if something breaks (or recovers)
* Optional TCKN (Turkish national ID) field at checkout, independently toggleable as shown/hidden and required/optional
* Fully translatable: English by default, with a complete Turkish translation included

= External services =

This plugin communicates with iyzico's payment API (api.iyzipay.com, or sandbox-api.iyzipay.com in test mode) to initialize and verify payments and process refunds. This is essential to the plugin's core function as a payment gateway. See [iyzico's Privacy Policy](https://www.iyzico.com/gizlilik-politikasi) and [Terms of Service](https://www.iyzico.com/kullanici-sozlesmesi) for details on how iyzico handles this data. No other external service is contacted by this plugin.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install directly from the WordPress Plugins screen.
2. Activate the plugin. WooCommerce must already be active.
3. Go to **WooCommerce > Settings > Payments > iyzico** to configure your API keys.
4. Start in **Sandbox Mode** — get test keys from iyzico's sandbox panel and confirm a full test order works before going live.
5. Add the plugin's Callback URL and detected server IP to the iyzico merchant panel under IP/Back URL Management, and submit for approval.
6. Once approved, turn off Sandbox Mode and enter your live keys.

== Frequently Asked Questions ==

= Does this store card data on my site? =

No. The customer is redirected to iyzico's own hosted payment page for card entry; card numbers never pass through your server.

= Can I issue refunds? =

Yes, full refunds are supported directly from the WooCommerce order screen. Partial refunds are not yet supported in this version.

= Where can I get iyzico API keys? =

Register as an iyzico merchant at iyzico.com. Sandbox (test) keys are available immediately from the sandbox panel; live keys require merchant approval.

= Is this plugin affiliated with iyzico? =

No. This is an independently developed, open-source integration built on iyzico's own public PHP SDK. It is not officially endorsed by iyzico.

== Screenshots ==

1. Payment settings screen — API keys, sandbox mode, and appearance options.
2. Health check panel showing connectivity and configuration status.

== Changelog ==

= 1.4.0 =
* Added basic refund support from the WooCommerce order screen.
* Added optional built-in payment icons (brand-neutral) and custom icon upload.
* Added complete English translation.
* Added optional TCKN (Turkish national ID) field at checkout.
* Fixed a load-order bug affecting the admin settings screen.

== Upgrade Notice ==

= 1.4.0 =
Adds refunds, optional TCKN collection, and full English translation. No breaking changes for existing installs — all new settings default to off/unchanged behavior.
