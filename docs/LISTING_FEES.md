# Listing fees (paid posting through the bot)

Classified ads posted through the Telegram bot can cost a posting fee, per
category and per subcategory. The ad is sent to HabeshaList.com only after
Stripe confirms the card payment.

## Files

Bot folder (the folder with `webhook.php`):
- `webhook.php` (updated)
- `includes/listing_fees.php` (new)
- `includes/stripe.php` (updated: closes an unpaid checkout when the user cancels)

Admin panel folder (bizadmin):
- `listing-fees.php` (new page: Settings > Listing Fees)
- `payments.php` (updated: "Listing fees" section)
- `view.php` (updated: menu entry)

## Setting it up

1. Upload the files above.
2. Wait a minute (or send /start to the bot) so the bot shares its category list with the panel.
3. Admin panel > Listing Fees: tick "Charge a posting fee for paid categories", set categories to Paid with a price, and Save.

Stripe uses the same key as the other card payments (panel > Keys).

## How it behaves

- Free category, or the main switch off: the ad is published at once, exactly as before.
- Paid category: after "Publish" the user sees the fee and a "Pay by card" button. Nothing is published until Stripe confirms the payment (amount, currency and payment id are checked with Stripe, never taken from the user coming back).
- The user can edit the ad from the payment screen, cancel (not charged, checkout closed), or leave and pay later: the next time they tap "Post to Website" the bot reminds them.
- If the website is down after a payment, the payment is kept and "Try Again" publishes the same ad without charging again.
- A subcategory can use its category's fee ("Same as category"), be free, or have its own price. Minimum fee $0.50 (Stripe's minimum).
- Paid listing fees appear in panel > Payments > Listing fees.

Tests: `php test_listing_fees.php`
