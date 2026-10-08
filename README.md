# WHMCS hooks

Small, single-file hooks for WHMCS 8 and 9, each solving one thing a hosting company runs into. Drop a file into `includes/hooks/`, edit the `$config` array at the top, done. No addon module, no database tables, no templates to patch; delete the file to remove it.

Maintained by [VPSPioneer](https://vpspioneer.com), a UK hosting company. Every hook is written against WHMCS's documented hook parameters and tested on a WHMCS 9 install.

| Hook | What it does |
| --- | --- |
| [`telegram_notifications.php`](hooks/telegram_notifications.php) | Telegram message on new order, paid invoice, new ticket, client reply, failed module action, cancellation request. |
| [`terminate_safeguard.php`](hooks/terminate_safeguard.php) | Refuses a module Terminate on an Active service that nobody asked to end. Suspend first, then terminate. |
| [`cart_promo_banner.php`](hooks/cart_promo_banner.php) | Shows a promotion code at the top of the WHMCS 9 store cart, pre-filled and one click from applied. |
| [`status_label_contrast.php`](hooks/status_label_contrast.php) | White text on ticket status labels that carry an inline background colour (fixes black on black for "Answered"). |

Looking for GA4 and Google Ads under Consent Mode v2? That one has its own repository: [whmcs-ga4-consent-mode](https://github.com/ahmetboz/whmcs-ga4-consent-mode).

## Install

1. Copy the hook file you want into `<whmcs>/includes/hooks/`.
2. Open it and fill in the `$config` array near the top. Each key is commented.
3. Reload any page. WHMCS loads hooks on every request; there is no cache to clear.

Hooks are independent. Install one or all.

## telegram_notifications.php

Create a bot with [@BotFather](https://t.me/BotFather), add it to the chat or group that should receive alerts, and find the chat id (send the bot a message, then open `https://api.telegram.org/bot<TOKEN>/getUpdates`). Put both in `$config`, switch events on or off, and set `admin_url` to your admin area so messages link to the order, invoice, ticket or service.

Each message is short: what happened, who, how much, and a link. The hook uses the WHMCS events `AfterShoppingCartCheckout`, `InvoicePaid`, `TicketOpen`, `TicketUserReply`, the `AfterModule*Failed` family and `CancellationRequest`. Delivery has a 5-second timeout; a failed delivery is written to the activity log and never interrupts the action that triggered it.

## terminate_safeguard.php

In WHMCS the same module Terminate runs when an order is cancelled with "Run module terminate" ticked, when an admin presses the button on the service page, when a cancellation request falls due and when an automation rule fires. For a VPS or hosting provider that is the customer's data. This hook listens on `PreModuleTerminate` and aborts the call when the service is still **Active**, its order is not Cancelled or Fraud, it has no cancellation request, and it was not suspended within the last hour. The refusal is logged and, optionally, emailed to the admins.

To terminate an Active service on purpose: suspend it, then terminate within the window (`suspended_within_minutes`, default 60). Services that are Suspended, Cancelled, Pending or Fraud are never blocked. Restrict the hook to certain modules with the `modules` list if you only want it on, say, your VPS module.

## cart_promo_banner.php

The WHMCS 9 store cart ("Nexus") ignores a `?promocode=` in the URL, so a campaign link alone does not apply a code. This hook renders a box above the cart's own promotion field with your code and a Copy button that also fills the field. Once the code is on the cart, the box becomes a confirmation with a remove button. Texts and the accent colour are in `$config`. The cart renders inside a shadow root; the hook inserts into it and reads the applied state from the cart's own API, so it keeps working while the cart re-renders.

## status_label_contrast.php

Ticket statuses carry their colour inline from Setup → Support → Ticket Statuses, and "Answered" ships as `#000000`. Themes that give labels dark text then show black on black. One CSS rule fixes every label with an inline background colour.

## Compatibility

Written against WHMCS 9.0 on PHP 8.3; the hook points used exist since WHMCS 8. The cart promo banner targets the WHMCS 9 store cart only.

## Contributing

Issues and pull requests are welcome. A new hook should be one file, solve one problem, carry its configuration at the top and fail quietly: a hook must never break the page or the action it listens to.

## License

MIT, see [LICENSE](LICENSE). WHMCS is a trademark of WHMCS Ltd; Telegram is a trademark of Telegram FZ-LLC. This project is not affiliated with either.
