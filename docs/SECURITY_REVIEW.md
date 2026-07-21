# HabeshaList — Pre-Production Security Review

Date: 2026-07-21. Scope: the Telegram bot, the OSClass website bridge
(`bot-bridge.php`), and the web admin panel. Every point you listed is covered
below, with what was found and what was changed.

Summary: the system was already in good shape — secrets in `.env`, prepared SQL
statements, hashed admin password, CSRF, forced HTTPS, and payment activation
that can't be bypassed. The review found ONE important issue (a shared secret
committed to the repo) and a few smaller hardening items. All are now fixed.
Two of the fixes need a one-time action from you on the server (marked ACTION).

---

## 1. Secrets stored securely / not exposed in source

- Bot token, webhook secret, API secret and Stripe key are read only from the
  `.env` file (git-ignored) — never hardcoded. The bot refuses to start if a
  required secret is missing.
- `.htaccess` blocks web download of `.env`, the database, and logs. Confirmed
  no `.env`/database/log file is tracked in git.
- Optionally, the admin Keys page can store the bot token / Stripe key
  encrypted at rest (AES-256-GCM).

FOUND (important): `bot-bridge.php` had the shared API secret hardcoded in the
file, so it lived in the repo history. Anyone with the code could have called
the website bridge.

FIXED: `bot-bridge.php` now reads the secret from the environment or from a
git-ignored `bridge-config.php`. The literal is removed and the comparison is
now timing-safe (`hash_equals`). Added `bridge-config.example.php` as a template
and added `bridge-config.php` to `.gitignore`.

ACTION REQUIRED — rotate the secret: because the old value was in the repo, it
should be replaced. Pick a new random secret and set it in TWO places so they
match:
1. The bot's `.env`: `API_SECRET=<new value>`
2. On the OSClass site, copy `bridge-config.example.php` to `bridge-config.php`
   and put the same `<new value>` in it.
Generate one with: `php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"`

## 2. Input validation (SQL injection, XSS, file uploads)

- SQL injection — SECURE. All bot and admin database access uses prepared
  statements with bound parameters. The few inline queries only ever interpolate
  integer IDs.
- XSS — SECURE. The admin panel escapes all user-supplied output (business
  names, descriptions, phones, referral names) through an `htmlspecialchars`
  helper. The ad-preview page (highest risk) escapes everything.
- File uploads — HARDENED. Payment screenshots are not stored on disk at all
  (only a Telegram file reference is kept). Ad photos/videos are downloaded by
  the website bridge; that path now enforces an extension allowlist
  (jpg/jpeg/png/webp/gif, mp4/mov/webm), a 24 MB size cap, and drops a
  `.htaccess` into the uploads folder so nothing there can execute as a script.
- The website bridge's OSClass text inserts now use the database driver's own
  charset-aware escaper instead of `addslashes`.

## 3. Passwords securely hashed

- SECURE. The admin password is stored only as a bcrypt hash
  (`password_hash`), verified with `password_verify`, with the username compared
  in constant time and a 1-second throttle on failed logins. No password or hash
  is ever kept in a file.

## 4. HTTPS/SSL on all production pages

- SECURE. The admin panel force-redirects http → https on every request and
  sets clickjacking / MIME-sniffing / referrer protections. The session cookie
  is HttpOnly + Secure + SameSite=Lax, with the session id regenerated on login.
- HARDENED: the cookie's Secure flag now uses the same robust HTTPS detection as
  the redirect, so it stays set even behind a TLS-terminating proxy.
- Note: HTTPS for the whole domain is provided by your host's SSL certificate —
  make sure it's active for `habeshalist.com` (it is, for the live site).

## 5. No paid service without payment or admin approval

- SECURE. A promotion can only become active through one of three
  server-verified paths:
  1. A Stripe payment that Stripe itself confirms (the bot verifies the session
     outbound when the user taps "I've paid").
  2. An admin manually approving a manual (Zelle/Cash App) payment — the approve
     action is admin-only.
  3. A legitimately earned referral reward, which is single-use and only
     activates after admin approval.
- A user cannot self-activate a promotion by tapping buttons or manipulating
  state — every approval entry point is behind an admin check, and manual
  payments still require review after payment.

## 6. Uploaded payment screenshots / files restricted to safe types & sizes

- HARDENED (see item 2). Screenshots themselves stay on Telegram (not written to
  our server). Ad media that IS written now has type + size enforcement and a
  no-execute uploads folder.

## 7. Error messages don't expose sensitive information

- SECURE (bot + admin). No production file turns on `display_errors`; errors go
  to the server log via `error_log`, and no log line contains the bot token,
  API keys, or card data.
- FIXED: the website bridge previously echoed raw exception text back to the
  caller. It now returns a generic "Server error" and logs the detail
  server-side.
- ACTION (verify on host): confirm `display_errors` is Off in the production
  `php.ini`, and do not upload the `test_*.php` files to the live server.

---

## Fixes applied in this review

1. Removed the hardcoded API secret from `bot-bridge.php`; it now loads from env
   / git-ignored `bridge-config.php`; added timing-safe comparison. (item 1)
2. Added upload extension allowlist + 24 MB size cap + no-execute `.htaccess`
   in the bridge's media handler. (items 2, 6)
3. Bridge now returns generic errors and logs details instead of echoing raw
   exception text. (item 7)
4. Bridge user-text inserts use the driver's charset-aware escaper. (item 2)
5. Admin session cookie Secure flag hardened for proxy setups. (item 5)

## Your one-time actions before launch

- [ ] Rotate `API_SECRET` and set the matching `bridge-config.php` on the
      website (item 1).
- [ ] Confirm `display_errors` is Off on the production host, and don't deploy
      the `test_*.php` scripts (item 7).
- [ ] Confirm the SSL certificate is active for the live domain (item 4).

Everything else (SQL injection defense, XSS escaping, password hashing, payment
activation, CSRF protection, admin-page authentication) was already implemented
correctly and needs no change.
