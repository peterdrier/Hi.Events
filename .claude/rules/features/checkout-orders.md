---
paths:
  - "backend/app/Services/Application/Handlers/Order/Checkout/**"
  - "backend/app/Http/Actions/Orders/CreateCheckoutOrderAction.php"
  - "backend/app/Http/Request/Order/CreateCheckoutOrderRequest.php"
  - "backend/tests/Feature/Http/Actions/Orders/CreateCheckoutOrderActionTest.php"
---

# Checkout orders (API-created, caller-priced)

- `POST /events/{event_id}/orders/checkout` lets an integration (the Nobodies "Humans" app) sell at prices it decides: a RESERVED order, one unit per item, buyer and attendee names filled in, `payment_link_url` to pay on the normal payment page. It skips promo codes, availability checks and the platform fee on purpose; the caller owns capacity and pricing. Seated products are refused (it takes no seats).
- `external_reference` is unique per event and makes the call idempotent: a repeat returns the existing order, or 409 if its total differs.
- The order total must equal the sum of item prices, so products used here carry no taxes or fees; otherwise 422 and the transaction rolls back.
- Refunds accept an optional `idempotency_key`: it becomes the Stripe idempotency key and is stored in the refund's metadata, and a key already recorded in `order_refunds` refunds nothing. A retry while the order is REFUND_PENDING is matched against the payment intent's open Stripe refunds by that metadata and refunds nothing; any other refund is still refused while one is pending.
