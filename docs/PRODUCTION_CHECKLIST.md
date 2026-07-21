# HabeshaList — Production Launch Checklist

Work top to bottom. Tick each item before you announce the bot to real users.
Details for any step are in `OPERATIONS.md`.

## A. Secrets & configuration
- [ ] Production bot token created in @BotFather (separate from the test bot).
- [ ] `bot/.env` created on the server with real `TELEGRAM_BOT_TOKEN`,
      `WEBHOOK_SECRET`, `API_SECRET` (and `STRIPE_KEY` if using Stripe).
- [ ] `.env` is NOT in GitHub (confirm: it's listed in `.gitignore`).
- [ ] `HL_APP_KEY` generated and set if you want the encrypted Keys page.
- [ ] Confirmed the bot starts (message `/start` → it replies).

## B. Group & bot permissions
- [ ] Bot added to the PRODUCTION Telegram group.
- [ ] Bot is an ADMIN of the group with "post", "pin", and "invite via link"
      rights (needed for promos + referral join detection).
- [ ] Production group set in the admin panel → Settings.
- [ ] Invite & Earn "Settle window" set to your preferred value (0 = counts the
      moment a friend joins).

## C. Runner & scheduling
- [ ] `poll.php` cron installed in cPanel (every 15 min) — see OPERATIONS §6.
- [ ] Sent a message and confirmed the bot responds within a minute.
- [ ] Booked a test promo and confirmed it auto-posts at the scheduled time.
- [ ] (Optional) Daily database backup cron installed.

## D. Payments
- [ ] Manual payment handles (Zelle / Cash App / support) set in the panel, OR
- [ ] Stripe key set and a test payment completed end-to-end.
- [ ] Verified a promo does NOT activate until payment is confirmed or an admin
      approves it.
- [ ] Package prices reviewed and correct in the admin panel → Pricing.

## E. Admin panel
- [ ] Panel uploaded next to the bot (recommend a non-obvious folder name).
- [ ] First-visit setup done: strong admin username + password created.
- [ ] Panel loads over HTTPS (http:// redirects to https://).
- [ ] Dashboard shows real stats; pending/approve flows work.

## F. Security (see SECURITY_REVIEW.md)
- [ ] `.env`, database, and logs are not downloadable over the web (test:
      visiting `.../bot/.env` and `.../bot/data/bot.sqlite` must be blocked).
- [ ] `display_errors` is OFF in production (no PHP errors shown to users).
- [ ] Admin password is hashed (it is — `password_hash`), login is rate-limited
      and CSRF-protected.
- [ ] Payment screenshots / uploads are size- and type-checked.

## G. Backup & rollback readiness
- [ ] Took a fresh database backup and downloaded it off the server.
- [ ] Confirmed you know how to restore it (OPERATIONS §3) and roll back code
      (OPERATIONS §11).

## H. Go live
- [ ] Final smoke test: /start, post an ad, run a promo, claim a referral reward.
- [ ] Announce the bot to users.
- [ ] Watch the error log for the first day (OPERATIONS §10).
