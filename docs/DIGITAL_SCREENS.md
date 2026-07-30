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
  TV / Raspberry Pi / Android TV box / portrait touchscreen at. Rotating loop of the
  currently-live ads, auto-refreshes every 60s, plays video muted, survives network
  drops, shows a branded idle card when nothing is booked.

### Interactive touchscreen mode (pilot default)
Screens default to **portrait** and to **interactive** mode. Here the player is a
two-mode kiosk:
- **Ad loop (passive)** — muted rotating advertisements, the "attract" state.
- **Website (interactive)** — after `attract_seconds`, or the moment someone taps
  the screen, it opens the HabeshaList site in full screen. Visitors browse local
  posts, events, listings and watch promotional videos **with sound** (sound is
  allowed because a person has touched the screen). After `idle_return_seconds`
  with no touch it slides back to the ad loop on its own. A "Tap to explore" hint
  invites interaction; a small "Back to ads" button lets a visitor return manually.

All four behaviours are per-screen and set in the admin form: the interactive
checkbox, the website to open (blank = habeshalist.com), seconds-before-open, and
seconds-idle-before-return.

> **Hosting requirement for interactive mode:** the website view is shown in an
> in-page frame, so `player.php` must be served from the **same domain** as the
> site it opens (e.g. both on `habeshalist.com`). That is already the case here.
> If you ever point it at a different domain that blocks framing, it will fall back
> to the ad loop only.

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
4. **Add a screen** (portrait + interactive are pre-selected), then click its
   **Preview** link — that URL is what you open on the physical screen's browser
   (put the browser in kiosk/full-screen mode).

### Putting content on a screen now (house ads)
Milestone 1 has no advertiser self-booking flow yet (that is Milestone 2). So
each screen's edit page has a **Content on this screen** panel: upload an image
or short video (or paste a media URL) and it plays on that screen immediately,
rotating with any future paid ads. Use it for the venue's own promos, a menu, or
a welcome slide. Uploaded files are saved next to `player.php` (in
`uploads/screens/`) and served from the same domain. Until you add at least one
piece of content, the player correctly shows the branded idle card - that black
screen with the logo means "connected, nothing booked yet", not an error.

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
