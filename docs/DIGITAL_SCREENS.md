# Digital Screen Advertising — Milestone 1 (foundation)

A self-contained module for booking ads onto physical digital screens. It is
**additive**: it only creates its own three tables and never touches the
classifieds bot or the promotions engine. Disable it and nothing else changes.

## What Milestone 1 delivers
- **Data + logic** (`includes/screens.php`) — screens, per-screen/default pricing,
  bookings, availability checks, and the "what plays now" playlist.
- **Admin page** (`admin/screens.php`) — add / edit / pause / delete screens, set
  the default and per-screen price, and get each screen's public player link.
  Appears in the sidebar as **Digital Screens**.
- **Player page** (`player.php`) — the public per-screen playout page you point a
  TV / Raspberry Pi / Android TV box at. Rotating loop of the currently-live ads,
  auto-refreshes every 60s, plays video muted, survives network drops, shows a
  branded idle card when nothing is booked.

## Files to upload
| File | Where it goes |
|------|----------------|
| `includes/screens.php` | bot folder → `includes/` |
| `player.php` | anywhere web-served (e.g. the bot folder). Its public URL is the screen link. |
| `admin/screens.php` | admin panel folder → `admin/` |
| `admin/view.php` | admin panel folder → `admin/` (adds the sidebar link) |

No changes to `webhook.php`, `bot-bridge.php`, or the database schema of the
existing tables. The three new tables are created automatically on first load.

## First-time setup (2 minutes)
1. Open **Admin → Digital Screens**.
2. Set the **Player URL base** to the public address of `player.php`
   (e.g. `https://habeshalist.com/bot/player.php`).
3. Set a **Default price**.
4. **Add a screen**, then click its **Preview** link — that URL is what you open
   on the physical screen's browser (put the browser in kiosk/full-screen mode).

## How it plays (why it works on this host)
The TV **pulls** the playlist from us over plain GET requests — nothing has to
reach *in* to the server, so it runs fine even though inbound webhooks are
blocked here (the same reason the bot polls). An ad only reaches a screen after
it is **paid and approved**; pending/rejected ads never appear.

## Coming next (Milestone 2 / 3)
- M2: the in-bot booking flow (pick screen → dates → upload flyer → pay) and the
  admin approval queue for screen bookings.
- M3: the scheduler auto-flips bookings live on their start date and expired
  after the end date (rides the existing cron); optional Xibo/OptiSigns playout.
