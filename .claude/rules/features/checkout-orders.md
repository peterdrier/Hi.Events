---
paths:
  - "backend/app/Services/Application/Handlers/Order/Checkout/**"
  - "backend/app/Http/Actions/Orders/CreateCheckoutOrderAction.php"
  - "backend/app/Http/Request/Order/CreateCheckoutOrderRequest.php"
  - "backend/tests/Feature/Http/Actions/Orders/CreateCheckoutOrderActionTest.php"
---

# Checkout orders (API-created, caller-priced)

- `POST /events/{event_id}/orders/checkout` lets an integration (the Nobodies "Humans" app) sell at prices it decides: a RESERVED order, one unit per item, buyer and attendee names filled in, `payment_link_url` to pay on the normal payment page. It skips promo codes, availability checks and the platform fee on purpose; the caller owns capacity and pricing. Seated products are refused (it takes no seats).
- `external_reference` is unique per event and makes the call idempotent: a repeat returns the existing order, or 409 if its total differs. A repeat is matched on the total only, and one for an order no longer reserved comes back without `payment_link_url`.
- Each item's `event_occurrence_id` must be one of this event's occurrences (422 otherwise); left out, a non-recurring event's single occurrence is used, as in the public checkout.
- Buyer names follow `PersonNameRule` (50 characters, the `orders` column); attendee names may be up to 255, so a full legal name fits.
- The order total must equal the sum of item prices, so products used here carry no taxes or fees; otherwise 422 and the transaction rolls back.
- Refunds accept an optional `idempotency_key`: it becomes the Stripe idempotency key and is stored in the refund's metadata, and a key already recorded in `order_refunds` (other than a failed or canceled refund, which may be retried) refunds nothing. A retry while the order is REFUND_PENDING is matched against the payment intent's open Stripe refunds by that metadata and refunds nothing; any other refund is still refused while one is pending.
- `return_url` (optional) is where the payment return page sends the buyer once the order completes, instead of the Hi.Events order summary. It is shown on the public order resource. It can point anywhere http(s), which is fine on an install where only one trusted integration holds a key; a shared install would need it limited to an allowed domain before this is upstreamed.
- `send_buyer_emails: false` stores `orders.send_buyer_emails = false`, and every automatic buyer or attendee email for that order (summary, tickets, failed, cancelled, refunded, refunded-after-expiry) checks it. Organizer and team notifications are unaffected; an organizer's explicit "resend confirmation" still sends.
- A payment that succeeds on a checkout order (one with `external_reference`) is always kept: the order completes even if it expired, was cancelled or abandoned, or its occurrence closed, and cancelled attendees come back. The cancel's `statistics_decremented_at` marker is cleared so a later cancel decrements the statistics again. The caller decides what to do with a late sale; Hi.Events never auto-refunds it. A second payment for an already paid order is still refunded.
- `POST /events/{event_id}/orders/{order_id}/anonymize` is how the caller carries out an erasure request. It refuses (409) orders without `external_reference`, then runs account deletion's `OrderAnonymizer` on that one order (raw tables, so soft-deleted attendees too), scrubs the `stripe_customers` row with the buyer's email (matched by email alone, which is right only on an install with one account; account deletion matches by Stripe account id because the table has no account key), and deletes the account's `webhook_logs` whose payload is that order or one of its attendees. Amounts, statuses and dates stay; idempotent. Any new place that stores a buyer's or attendee's name or email must be added here.
