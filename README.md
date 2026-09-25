# Flitt payment module for CS-Cart 4.x

Accept card payments (Visa, Mastercard and more) in your CS-Cart or Multi-Vendor store with [Flitt](https://flitt.com).
The customer is redirected to the Flitt hosted payment page, and the order status is updated automatically
by the Flitt server callback.

Current version: **1.1.0**. See [CHANGELOG.md](CHANGELOG.md).

## Features

- Redirect (hosted payment page) checkout
- **Sale** (immediate charge) and **Hold** (pre-authorization with capture on order status change) transaction methods
- Automatic order status update by the Flitt server callback, with signature, merchant, order and amount verification
- Payment in the store's primary currency or in a fixed currency
- "Send payment link" button in the admin order details for open orders
- Test mode with Flitt test credentials
- English, Georgian and Russian translations

## Requirements

| Component | Supported versions |
|---|---|
| CS-Cart / Multi-Vendor | 4.x (tested with **4.21.2**, the latest release at the time of writing) |
| PHP | 7.4 – 8.3 (whatever your CS-Cart version supports; tested with 8.2) |
| PHP extensions | `curl`, `json` |

Your store must be reachable from the Internet over HTTPS so that Flitt can deliver callbacks.

## Installation

1. Download `flitt-cscart-<version>.zip` from the [Releases](https://github.com/flittpayments/cs-cart/releases) page.
   To build it yourself from a checkout: `git archive --format=zip -o flitt-cscart.zip HEAD app design var`.
2. In the admin panel, go to **Add-ons → Manage add-ons** (`admin.php?dispatch=addons.manage`).
3. Click the **+** (Upload & install add-on) button and upload the archive.
4. Make sure the **Flitt Payment Provider** add-on is **Active**.

## Configuration

1. Go to **Administration → Payment methods** (`admin.php?dispatch=payments.manage`) and add a payment method.
2. On the **General** tab:
   - **Name**: e.g. *Visa / Mastercard (Flitt)*
   - **Processor**: *Flitt Payment Provider*
3. On the **Configure** tab:

   | Field | Description |
   |---|---|
   | Test mode | *Yes* fills in the Flitt test credentials (merchant `1549901`, key `test`). Set it to *No* for live payments. |
   | Merchant ID | Your Flitt merchant ID |
   | Password | Your merchant payment secret key from the Flitt merchant portal |
   | Language | Language of the Flitt payment page |
   | Payment type | *Redirect to Flitt* |
   | Transaction method | *Sale* charges immediately. *Hold* only blocks the funds (see below). |
   | Order status with hold funds | Status set after a successful pre-authorization (Hold only) |
   | Paid order status | Status set after a successful payment, e.g. *Processed* or *Paid* |
   | Currency | *Primary currency* (the customer's selected currency) or a fixed currency |

4. Click **Create**.

### Hold (pre-authorization) and capture

With the *Hold* method, a successful payment moves the order to **Order status with hold funds**.
When you change the order status to **Paid order status**, the module asks Flitt to capture the funds.
If the capture fails, the order is moved to *Failed* and an error is shown with the reason from Flitt.

### Sending a payment link

On the details page of an *Open* order paid with Flitt, click **Send payment link**.
The module creates a new Flitt payment and emails its link to the customer. Each click creates a fresh link, and
links sent earlier keep working until Flitt expires them.

### Payment attempts

Every payment attempt (checkout, paying again after a decline, payment link) gets its own Flitt order ID in the
format `<order number>_<unix time>`, for example `98_1790291538`. Flitt doesn't accept the same order ID twice.
A payment confirmed for any attempt of the order is accepted. The attempts are listed in the order's payment
information.

## How callbacks work

The module sends each payment to Flitt with:

- `server_callback_url`: `https://<your-store>/index.php?dispatch=payment_notification.ok&payment=flitt&order_id=<ID>`
- `response_url`: `https://<your-store>/index.php?dispatch=payment_notification.response&payment=flitt&order_id=<ID>`

The server callback is the source of truth for the order status. The handler:

1. Verifies the signature and the merchant ID. It also checks that the Flitt order ID is one of this order's payment
   attempts, and (for orders created by 1.1.0+) that the amount and currency match that attempt.
2. Maps the Flitt `order_status` to a store status:

   | Flitt `order_status` | Store status |
   |---|---|
   | `approved` | Paid order status (Sale) or Order status with hold funds (Hold) |
   | `declined`, `expired` | Failed, unless the order is already paid or held |
   | `created`, `processing`, `reversed` | Not changed; details are saved in the payment information |

3. Replies with **HTTP 200 `OK`**. Flitt treats any other response, including redirects, as a failure and retries.
   A repeated callback doesn't change the status or send emails again.

Invalid callbacks are answered with HTTP 400, and callbacks for unknown orders with HTTP 404. Neither changes the order.

## Troubleshooting

**Orders stay in the *Incomplete* status or callbacks fail in the Flitt merchant portal**

- Upgrade to 1.1.0 or later. Versions 1.0.x answer callbacks with HTTP 500 (a PHP `TypeError` in
  `fn_change_order_status`, for example when the Warehouses add-on is active) or with a 302 redirect.
  Flitt doesn't count either as delivered.
- Allow incoming requests from the Flitt callback servers in your firewall, WAF, CDN or bot protection:
  `54.154.216.60` and `3.75.125.89`.
- Make sure `index.php?dispatch=payment_notification.ok` is reachable without authentication, captcha or
  "store closed" mode.
- Check that **Merchant ID** and **Password** match the merchant portal. A wrong key makes every callback fail
  with HTTP 400 `Flitt_error_signature`.

**"Unable to create a Flitt payment" at checkout**

The message after the colon comes from Flitt (for example, a wrong signature or currency). The Flitt `request_id` is
stored in the order's payment information for support requests.

**The capture fails for Hold orders**

Flitt allows only one capture per order, and only while the pre-authorization is still valid. See the error message
in the order's payment information.

## Upgrading from 1.0.x

Upload the new archive over the old one (or reinstall the add-on). Payment method settings are kept.
Then clear the cache (**Administration → Storage → Clear cache**) so that the new texts appear.
Orders created before the upgrade are still processed. For them, the amount and currency check is skipped because
1.0.x didn't store these values.

## Languages

The module's own texts are translated into English, Georgian and Russian. CS-Cart itself doesn't include Georgian:
if you add Georgian in **Administration → Languages**, CS-Cart's own storefront and email texts stay in English
until you translate them there.

## Support

support@flitt.com
