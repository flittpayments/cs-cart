# Changelog

All notable changes to this module are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the module follows
[Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-09-25

### Fixed
- Server callbacks failed with HTTP 500, so orders stayed *Incomplete*. `fn_change_order_status()` was called with
  `false` as the `$force_notification` argument, but CS-Cart expects an array. Hook handlers that type-hint it, such as
  `fn_warehouses_change_order_status_before_update_product_amount()` in the bundled Warehouses add-on, threw a PHP
  `TypeError`.
- The server callback answered with a 302 redirect instead of HTTP 200. Flitt doesn't follow redirects, so it treated
  callbacks as failed and kept retrying.
- Repeated callbacks no longer change the order status or send notifications again.
- A late `declined` or `expired` callback no longer moves an already paid or held order to *Failed*.
- Multi-Vendor orders split between vendors: statuses are checked on the vendor (child) orders.
- The `change_order_status` hook ran for orders with any payment method. It also used the order ID from the request
  instead of the order being changed.
- "Send payment link" in the admin panel never worked (wrong processor name check) and was shown for orders with any
  payment method.
- Admin redirects no longer hard-code `/admin.php`, so they work with a renamed admin script.
- The *Hold* option was shown as `_hold` in the payment method settings (language variable name with capital letters).
- The capture result (or Flitt's error message and request ID) was lost for order statuses that clean up payment data,
  because CS-Cart re-saved an older copy of the payment information.
- Paying again for the same order (after a decline, or through a new payment link) failed with Flitt's
  "Duplicate order" error. Each payment attempt now gets its own Flitt order ID (`<order number>_<unix time>`).
- A customer returning from Flitt before the payment is confirmed no longer sees CS-Cart's contradictory
  "Transaction was canceled by the customer" notice. Only "Your payment is being processed" is shown.
- "Send payment link" always creates a fresh link instead of resending one that may have expired.

### Security
- Stricter verification of payment notifications from Flitt. Upgrading is strongly recommended.

### Changed
- Compatibility with current CS-Cart 4.x (tested with 4.21.2) and PHP 7.4 – 8.3.
  - Uses `Tygh::$app['session']` instead of `$_SESSION`.
  - Guards against undefined array keys on PHP 8.
- If payment creation fails, the customer sees an error notification and is sent back to checkout instead of a raw
  debug dump.
- Flitt API requests have connection and response timeouts and handle network or HTTP errors.
- The admin payment link uses the same `response_url` as checkout.
- The Flitt order ID format changed from `<order timestamp>_<order number>` to `<order number>_<unix time>`.
  Orders created by 1.0.x are still processed.
- `addon.xml`: English description, supplier information, support e-mail and a PHP version requirement.

### Added
- New fields in the order's payment information: `flitt_attempts` (all payment attempts with amount and currency),
  `flitt_amount` and `flitt_currency` (used for callback verification and capture), `order_status_flitt`,
  `capture_status`.
- Georgian translation.
- New language variables: `flitt.send_payment_link`, `flitt.payment_processing`, `flitt.payment_creation_failed`,
  `flitt.capture_failed`, `flitt.order_not_found`, `flitt.sale`, `flitt.hold`, `flitt.redirect_to_flitt`.
  They replace `Hold` and `Redirect to Flitt`.
- A README covering configuration, callback requirements and troubleshooting. This changelog.

## [1.0.2]

- Initial releases: redirect payments, Sale/Hold transaction methods, capture on order status change,
  and payment links sent from the admin panel.

[1.1.0]: https://github.com/flittpayments/cs-cart/releases/tag/v1.1.0
[1.0.2]: https://github.com/flittpayments/cs-cart/releases/tag/v1.0.2
