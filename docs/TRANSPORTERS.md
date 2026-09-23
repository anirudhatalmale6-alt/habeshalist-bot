# HabeshaList Verified Transporter — Phase 1

A self-contained module that lets community members travelling with spare
luggage space become **Verified Transporters**, advertise their available space
to the HabeshaList Telegram group, and be rated by customers — with a public
directory on the website.

It owns three tables of its own (`transporters`, `transporter_posts`,
`transporter_ratings`) and never touches a table the classifieds bot, the
promotions engine or the Digital Screens module uses. Switching it off changes
nothing else.

---

## 1. Files and where they go

| File | Goes in | What it is |
|---|---|---|
| `includes/transporters.php` | bot folder `includes/` | Data + rules layer. Shared by the bot, the panel and the website. |
| `includes/transport.php` | bot folder `includes/` | The Telegram flows (menu, apply, username, post, find, rate). |
| `webhook.php` | bot folder | Routing, the new menu button, state/photo handling. |
| `admin/transporters.php` | admin panel folder | Admin → Transporters (the five tabs + settings). |
| `admin/view.php` | admin panel folder | Adds “Transporters” to the sidebar. |
| `transporters.php` | **website root** | The public directory and profile pages. |

The bot folder is the one containing `webhook.php` and `data/bot.sqlite`.
The website file finds the bot folder by itself, so it works whether the bot
sits in a subfolder, beside the web root, or in it.

No database work is needed — the tables are created automatically on first load.

---

## 2. The journey

```
Apply in the bot
   → Admin approves
      → Transporter creates a public @username
         → Website profile is created automatically
            → Post available space
               → Preview
                  → Free pilot post, or pay the advertising fee
                     → Published to the Telegram group
                        → Customers rate them
                           → Website average updates
                              → 3 distinct low ratings → Admin review
```

### Two identifiers, on purpose

- **`transporters.id`** — the permanent *internal* transporter id. Every post,
  rating and admin action is keyed on this. It never changes.
- **`transporters.username`** — the public `@handle`. It is what customers type
  and what the website URL uses, and it **can be changed** (with admin approval)
  without orphaning a single row of history.

---

## 3. Bot menu

Main menu gains **🧳 Luggage & Transport**, which opens:

| Button | What it does |
|---|---|
| Become a Verified Transporter | The application, one question at a time, ending in the agreement. |
| Post Available Space | Create an advertisement (blocked unless eligible). |
| Find a Transporter | Ask From / To, return matching profiles. |
| Rate a Transporter | Enter an `@username`, give 1–5 stars, optionally comment. |
| Safety Notice | The platform safety and disclaimer copy. |

The first button adapts: an approved transporter with no username sees
**Create My Public Username**; a fully set-up one sees **My Transporter Profile**.

### The application collects (spec section 3)

Full name · phone number · public contact method · profile photo · usual origin ·
usual destination. The Telegram user id, `@username` and display name are
captured **automatically** and never asked for.

Nothing is saved until the transporter accepts the agreement, which restates
that HabeshaList provides advertising and community exposure only.

### Eligibility gate on posting (spec section 8)

| Status | Bot behaviour |
|---|---|
| Not verified | Blocked; sent to the application. |
| Pending | “Your application is under review.” No posting. |
| Rejected | Blocked; may re-apply. |
| Suspended | Blocked. |
| Approved, no username | Must create a username first. |
| Approved + username | Allowed. |

---

## 4. Public usernames

Rules enforced: unique (case-insensitive), no spaces, letters/numbers/underscore
only, 4–24 characters, at least one letter, and reserved or inappropriate names
are blocked. Uniqueness is enforced by a database index, so two people claiming
the same handle at the same moment cannot both win.

**Changing a username requires admin approval.** The request is stored in
`username_pending`; the current handle stays live until an admin approves it,
from Telegram or from the panel.

---

## 5. Admin → Transporters

Tabs: **Pending | Approved | Rejected | Suspended | Needs Review**.

- **Pending** — full application details, then Approve or Reject.
- **Needs Review** — raised by the low-rating rule. Shows the ratings and
  comments that caused it, and the three allowed decisions:
  **Keep Active · Send Warning · Suspend**. Nothing is ever suspended
  automatically.
- **Payments awaiting verification** (top of the page) — posts paid by
  screenshot. **Approve & Publish** sends the advertisement to the group.

Suspending blocks new posts and hides the website profile. It **never** deletes
past posts, ratings or history, and a suspended transporter can be reinstated.

### Settings → Transporter Advertising (spec section 10)

| Setting | Key | Notes |
|---|---|---|
| Luggage & Transport is open | `tr_module_enabled` | Master on/off. |
| Charge for posts | `tr_ads_enabled` | Uncheck to run a free pilot. |
| Single post advertising fee | `tr_ad_fee` | Any decimal amount. **Never hard-coded.** |
| Currency | `tr_currency` | USD initially. |
| Free introductory posts | `tr_free_posts` | Per transporter, for the pilot. |
| Website base URL | `tr_site_url` | Used to build profile links. |

**The fee is frozen at checkout.** Whatever the amount was when the transporter
paid is stored on that transaction, so changing the price later never rewrites
an existing one.

---

## 6. Payment and publishing

Payment options are **Pay by Card** (Stripe) and **Submit Payment Screenshot**.

- **Card succeeds** → `payment_status = approved` → publishes automatically.
- **Card fails / unconfirmed** → nothing is published.
- **Screenshot** → `payment_status = pending_review` → an admin approves, and it
  publishes then.
- **Free pilot** → publishes immediately, and the free allowance is only counted
  once the post is actually saved.

### Duplicate publication is impossible

Publishing writes the Telegram message id in a single statement that only
matches a row with **no** message id yet. A double tap, a retried payment or an
admin clicking Approve twice can never produce two posts.

If the group send genuinely fails on a **paid** post, the post is kept, the
transporter is reassured, and the admins get a **Retry publishing** button — a
customer can never pay and silently get nothing.

---

## 7. Ratings and the low-rating rule

A customer enters an `@username`, confirms who they are rating, gives 1–5 stars,
and is **always** offered an optional comment.

Only **1- and 2-star** ratings count as low. The rule counts **distinct Telegram
users**, so three 1-star ratings from the same person do nothing. When the
**third different** customer leaves a low rating, `admin_review_required` is set
and the admins are notified. There is **no automatic suspension** — the decision
is always an admin's.

Averages are recalculated on every rating and stored on the transporter row, so
the website is never stale. There is no sync job to fall behind.

---

## 8. The website page

Upload `transporters.php` to the **website root**. It works immediately at:

```
https://habeshalist.com/transporters.php                  (directory)
https://habeshalist.com/transporters.php?u=AbebeTravel    (profile)
```

### Pretty URLs (optional)

To get `/transporters` and `/transporters/AbebeTravel`, add this to the site's
`.htaccess` **above** any existing rewrite rules:

```apache
RewriteEngine On
RewriteRule ^transporters/?$            /transporters.php            [L,QSA]
RewriteRule ^transporters/([^/]+)/?$    /transporters.php?u=$1       [L,QSA]
```

### What the page shows

- **Directory** — a card per approved transporter: profile photo, Verified
  Transporter badge, `@username`, usual route, average rating and count, and a
  **View Profile** button. Plus a route/name search box.
- **Profile** — display name, photo, `@username`, usual route, rating and count,
  contact method, current advertised trip when there is one, recent comments,
  and the HabeshaList disclaimer.

### Privacy

The page renders only `hl_tr_public()`, which deliberately drops the **Telegram
user id, the internal transporter id, admin notes, the phone number and every
payment field**. A suspended, pending or rejected transporter has no public
profile — their URL returns “Profile not available”, not their details.

Profile photos live inside the bot folder and are served through the page itself
(`transporters.php?photo=…`), so they work no matter where the file is dropped.
The admin panel serves its thumbnails the same way, behind the login.

---

## 9. Out of scope for Phase 1

Customer shipping payments, escrow, shipment tracking, insurance, automatic
dispute resolution, verification tiers, advanced route/date matching,
subscriptions and analytics are deliberately **not** built yet.

---

## 10. Testing

```bash
php test_transporter.php
```

Drives the real bot code with a mock Telegram and a temporary database —
184 checks, including every developer acceptance test in section 19 of the
specification:

| Test | Expected |
|---|---|
| Unverified user tries to post | Blocked and directed to verification |
| Pending user tries to post | Pending message; no posting |
| Approved user without username | Username creation required |
| Approved user creates post | Preview generated correctly |
| Card payment succeeds | Post publishes once |
| Screenshot payment submitted | Waits for admin approval |
| Admin approves screenshot | Post publishes once |
| Transporter is suspended | New posts blocked; website profile hidden |
| Customer rates `@username` | Rating saves and website average updates |
| 3 low ratings from 3 users | Admin review flag created; no automatic suspension |

---

## 11. Running the pilot (spec section 17)

Recruit about 5 established transporters, approve them, let them create their
usernames, and leave **Charge for posts** unchecked (or set 1–2 free
introductory posts). Measure whether the posts generate serious customer
enquiries before switching charging on.

The metric that matters is **repeat paid promotion**, not total registrations.
