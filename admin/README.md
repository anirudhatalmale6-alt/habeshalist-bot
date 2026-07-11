# HabeshaList Web Admin Panel

A small, secure web panel to manage the bot's configuration from a browser -
no Telegram needed. It reads and writes the **same** database the bot uses, so
any change (a price, a payment handle) takes effect on the very next message.

This is browser-only and completely separate from the Telegram webhook, so it
is **not affected** by the ModSecurity / polling situation on the bot side.

## What it does (this milestone)

- Secure login (username + password, stored only as a secure hash in the DB).
- Edit all package prices: One-Time, Monthly, Yearly, Business of the Week.
- Edit payment handles: Zelle, Cash App, Support contact.
- Dashboard stats: users, ads, promotions, pending review, approved revenue.
- Recent promotions list (read-only for now).

Everything is plain PHP + SQLite - the same stack the bot already runs on. No
libraries, no build step, no external services.

## Install (2 minutes)

1. Upload the whole `admin` folder to your website, **next to** the bot folder.
   Recommended - rename it to something non-obvious, e.g. `bizadmin`:

   ```
   public_html/website_eff65c78/bot/        <- the bot (already there)
   public_html/website_eff65c78/bizadmin/   <- this folder
   ```

   The database path is auto-detected for this layout. If you put it somewhere
   else and it can't find the database, open `lib.php` and set the full path:

   ```php
   define('BOT_DB_PATH', '/home/USER/public_html/website_eff65c78/bot/data/bot.sqlite');
   ```

2. Visit it in your browser, e.g. `https://www.habeshalist.com/bizadmin/`.
   The first visit shows a one-time **Create your admin login** page. Pick a
   username and a strong password. That's it - you're in.

3. From then on, that URL asks you to log in.

## Security notes

- The password is never stored in a file - only a `password_hash()` value in
  the database (which is already blocked from public download).
- Login is CSRF-protected and rate-limited.
- The panel does not expose the database or any secrets over the web.
- Forgot the password? Run `php reset-password.php` over SSH (or ask me for a
  one-liner), then set a new one via the setup page.

## Coming next (optional future milestones)

- Approve / reject promotions directly from the web (with the user notified in
  Telegram automatically).
- Users & ads browser, search, and export.
- Editing business categories and package descriptions.
- Scheduling calendar view once the auto-posting engine is built.
