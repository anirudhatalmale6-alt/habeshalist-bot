# HabeshaList Bot — Operations & Production Guide

Everything you need to run, back up, monitor, and recover the HabeshaList
Telegram bot and its web admin panel in production. Written to be read by a
non-developer. Anything that needs a command is given as a copy-paste one-liner.

---

## 1. How the system is structured

There are two independent pieces that share ONE database:

```
public_html/website_eff65c78/
├── bot/          ← the Telegram bot (this repo)
│   ├── config/config.php     app settings (NO secrets — reads them from .env)
│   ├── .env                  the real secrets (NOT in git, never uploaded to GitHub)
│   ├── webhook.php           all bot logic + message/callback handlers
│   ├── poll.php              the runner: pulls messages from Telegram (cron)
│   ├── scheduler.php         standalone scheduler entrypoint (optional 2nd cron)
│   ├── includes/             engine: database, referral, promotion, scheduler, stripe, telegram
│   ├── data/bot.sqlite       ← THE DATABASE (all users, ads, promotions, payments, referrals)
│   └── data/                 logs + lock/offset files (blocked from web access)
└── bizadmin/     ← the web admin panel (the admin/ folder in this repo)
    ├── signin.php            login page
    ├── lib.php               shared helpers, auth guard, forces HTTPS
    └── *.php                 dashboard, pricing, payments, users, invite, etc.
```

Key idea: the admin panel reads and writes the SAME `data/bot.sqlite` the bot
uses, so any change you make in the browser (a price, a payment handle, a
reward tier) takes effect on the very next Telegram message. No redeploy needed.

Stack: plain PHP 8 + SQLite. No frameworks, no Composer, no build step, no
external services except Telegram and (optionally) Stripe.

---

## 2. Where the source code is hosted

- GitHub: **https://github.com/anirudhatalmale6-alt/habeshalist-bot** (branch `master`).
- The live server is a copy of this repo. The `.env` file (secrets) and
  `data/bot.sqlite` (live data) exist ONLY on the server — they are never in
  GitHub by design (see `.gitignore`).

---

## 3. Database & how to back it up

The entire system state is a single SQLite file: **`bot/data/bot.sqlite`**.
Backing that one file up = backing up everything (users, ads, promotions,
payments, referrals, settings, admin login).

### Back it up (run over SSH / cPanel Terminal, from the bot folder)

Safe, consistent snapshot (uses SQLite's own backup so it can't catch a
half-written file even while the bot is live):

```
sqlite3 data/bot.sqlite ".backup 'data/backup-$(date +%F-%H%M).sqlite'"
```

Then download that `data/backup-*.sqlite` file to your computer (via cPanel File
Manager → Download, or SFTP). That downloaded file IS your full backup.

No SSH? In cPanel: **File Manager → `bot/data/` → select `bot.sqlite` →
Compress → download the zip.** Do this when traffic is low; it's still fine live
because SQLite is in WAL mode.

### Recommended cadence

- Daily automated copy (see the optional backup cron in section 6), kept for a
  couple of weeks, plus a manual download before every deployment.

### Restore a backup

```
cp data/backup-YYYY-MM-DD-HHMM.sqlite data/bot.sqlite
```

(Do this while the bot is briefly stopped — see section 9 — then start it again.)

---

## 4. Production deployment steps

First-time deploy (or moving from test to production):

1. **Create the production bot** in @BotFather and copy its token. Use a NEW
   token for production so test and live never share state.
2. **Upload the code.** Pull the repo on the server, or upload the `bot/` and
   `admin/` folders via cPanel. Do NOT overwrite `data/` or `.env`.
3. **Create `.env`** in the `bot/` folder (copy `.env.example` → `.env`) and
   fill in the real values:
   - `TELEGRAM_BOT_TOKEN` — the production bot token
   - `WEBHOOK_SECRET` — any long random string
   - `API_SECRET` — any long random string (shared with `bot-bridge.php`)
   - `STRIPE_KEY` — your Stripe secret key (only if using Stripe)
   - `HL_APP_KEY` — `php -r "echo base64_encode(random_bytes(32));"` (enables the
     encrypted Keys page in the admin panel; optional)
4. **Point the group.** In the admin panel → Settings, set the production
   Telegram group and confirm the bot is an admin of it.
5. **Start the runner cron** (section 6). Within a minute the bot is live.
6. **Create the admin login.** Visit `https://www.habeshalist.com/bizadmin/`,
   it prompts you once to create a username + strong password.
7. **Smoke test:** message the bot `/start`, post a test ad, run one promotion
   through payment, and confirm it appears in the admin dashboard.

Updating later (new code): pull `master` on the server (or re-upload changed
files). `.env` and `data/bot.sqlite` are untouched. Then restart the runner
(section 9).

---

## 5. Important configuration files & environment variables

| File | What it holds |
|------|---------------|
| `bot/.env` | ALL secrets. Never in git. The only file with real tokens/keys. |
| `bot/.env.example` | Template showing which variables are needed (no values). |
| `bot/config/config.php` | Non-secret settings: website URL, package structure, categories, admin Telegram IDs. Reads secrets from `.env`. |
| `bot/.htaccess` | Blocks web access to `.env`, the database, logs; keeps rewrite off. |
| `bot/data/.htaccess` | Blocks all web access to the data folder. |
| `admin/lib.php` | Admin panel bootstrap: DB path, session, forces HTTPS, auth guard. |

Environment variables (all live in `bot/.env`):

- `TELEGRAM_BOT_TOKEN` — from @BotFather. **Required.**
- `WEBHOOK_SECRET` — proves inbound requests really came from Telegram. **Required.**
- `API_SECRET` — shared secret between the bot and the website bridge. **Required.**
- `STRIPE_KEY` — Stripe secret key. Optional (only if taking card payments).
- `PAYMENT_PROVIDER_TOKEN` — Telegram-native payments token. Optional.
- `HL_APP_KEY` — 32-byte base64 master key that encrypts tokens stored via the
  admin Keys page. Optional; if blank the bot just uses the plain `.env` values.

The bot refuses to start (HTTP 500 + a line in the error log) if any of the
three Required variables is missing — so a misconfigured deploy fails loudly
instead of running half-broken.

---

## 6. Cron jobs / scheduled tasks

The bot runs from cron because this host's firewall blocks Telegram's inbound
webhook POSTs. Instead of Telegram pushing messages in, the server reaches OUT
and pulls them. This is set in **cPanel → Cron Jobs.**

**Required — the runner (every 15 minutes):**

```
*/15 * * * * /usr/local/bin/php /home/USER/public_html/website_eff65c78/bot/poll.php >/dev/null 2>&1
```

What it does: each run stays alive for ~14 minutes, continuously pulling
messages from Telegram AND ticking the scheduler every 60 seconds (so scheduled
promo posts go out on time) AND settling referrals every 5 minutes. A file lock
guarantees only ONE runner is ever alive, so overlapping cron ticks can't
double-post. The cron is really just a watchdog that restarts the runner if it
ever died. (Replace `USER` with your cPanel username; adjust the interval to
whatever minimum your host allows.)

**Optional — daily database backup (recommended):**

```
5 3 * * * cd /home/USER/public_html/website_eff65c78/bot && sqlite3 data/bot.sqlite ".backup 'data/backup-$(date +\%F).sqlite'" && find data -name 'backup-*.sqlite' -mtime +14 -delete
```

Makes a dated snapshot at 03:05 daily and keeps the last 14 days.

**Optional — separate scheduler cron:** normally NOT needed because `poll.php`
already ticks the scheduler internally. Only add it if you ever switch the bot
to true webhook mode:

```
*/5 * * * * /usr/local/bin/php /home/USER/.../bot/scheduler.php >> /home/USER/.../bot/data/scheduler.log 2>&1
```

---

## 7. Telegram bot & webhook setup

The bot currently runs in **polling mode** (via the `poll.php` cron above) —
this is what makes it work despite the host firewall. There is nothing else to
configure for messages to flow; the cron IS the setup.

- The bot must be an **admin of the group** it posts to (needed to post,
  pin, and detect who joins for the referral program).
- Group and posting settings are managed in the admin panel → Settings.

**If the host ever disables the firewall (ModSecurity) for `/bot/`** you can
switch to instant webhook mode (optional, faster):

```
# turn ON webhook (and stop the poll.php cron):
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://www.habeshalist.com/bot/webhook.php&secret_token=<WEBHOOK_SECRET>"

# to go back to polling: delete the webhook and re-enable the poll.php cron:
curl "https://api.telegram.org/bot<TOKEN>/deleteWebhook"
```

Polling and webhook are mutually exclusive — use one or the other, never both.

---

## 8. Stripe setup

Stripe is **optional** (the bot also supports manual payment via Zelle/Cash App
handles set in the admin panel). If you use Stripe:

1. Put your Stripe **secret key** in `.env` as `STRIPE_KEY=sk_live_...` (or set
   it from the admin Keys page, which stores it encrypted).
2. That's it — **no Stripe webhook needed.** The bot uses a pull-based design:
   when a user taps "I've paid", the bot calls Stripe OUTBOUND to verify the
   Checkout Session was actually paid before activating anything. This is
   deliberate so it works on this firewalled host.
3. Test with a `sk_test_...` key and Stripe's test cards first, then swap to the
   live key.

A promotion can only become active through (a) a Stripe session Stripe itself
confirms as paid, (b) an admin manually approving it, or (c) a legitimately
earned referral reward. A user can never self-activate a paid promo.

---

## 9. How to restart / redeploy the system

The "server" is really just the `poll.php` cron. To restart cleanly:

- **Restart the runner:** kill the live poller; the next cron tick (within 15
  min) starts a fresh one. To restart immediately, clear the lock and let cron
  pick it up, or run it once by hand:
  ```
  pkill -f poll.php ; cd /home/USER/.../bot && /usr/local/bin/php poll.php >/dev/null 2>&1 &
  ```
- **Redeploy new code:** upload/pull the changed files, then restart the runner
  as above. `.env` and `data/bot.sqlite` are never touched by a code update.
- **The admin panel** needs no restart — it's plain PHP served on each request.

---

## 10. How to check error logs

Errors are written with PHP's `error_log()`. Where to look:

1. **PHP error log** — the main place. In cPanel → **Metrics → Errors**, or the
   `error_log` file that appears in the `bot/` folder / your home directory.
   Bot handler errors, scheduler hiccups, and "missing env var" messages land
   here, each prefixed (e.g. `poll.php handler error on update ...`).
2. **Scheduler log** (only if you run the optional scheduler cron):
   `bot/data/scheduler.log` — one line per run showing what was posted.
3. **Admin actions** are recorded in the database's audit trail (referral/
   config changes), viewable in the panel.

Quick check over SSH:

```
tail -n 100 ~/public_html/website_eff65c78/bot/error_log
```

Nothing sensitive is written to these logs (no tokens, no card data) — they're
safe to read and share when troubleshooting.

---

## 11. How to roll back to a previous version

Code and data roll back separately.

- **Roll back CODE** (a bad update): every change is a git commit, so on the
  server:
  ```
  cd /home/USER/.../bot && git log --oneline -10        # find the good commit
  git checkout <commit-hash> -- .                        # restore those files
  ```
  Or re-upload the previous files. Then restart the runner (section 9). Because
  `.env` and the database live outside git, a code rollback never loses data.
- **Roll back DATA** (e.g. a bad import): restore a database backup as in
  section 3 while the runner is briefly stopped.

Always take a fresh database backup (section 3) BEFORE any deployment so you
have a known-good point to roll back to.

---

## 12. Accounts, services & third-party tools the system depends on

- **Telegram Bot API** — the bot itself (@BotFather token). Core dependency.
- **Bluehost shared hosting (cPanel)** — runs the PHP + SQLite app and the cron.
- **GitHub** (`anirudhatalmale6-alt/habeshalist-bot`) — source code hosting.
- **Stripe** — optional, card payments only. Not required if using manual
  Zelle / Cash App payment handles.
- **The website** (`habeshalist.com`) — hosts the admin panel and the
  `bot-bridge.php` endpoint the bot talks to.

No other paid services, APIs, or libraries are required. PHP's built-in SQLite,
cURL, and OpenSSL extensions are the only runtime dependencies, and they're
standard on the host.
