# HabeshaList — Full Functionality Test Checklist

A go-through of every feature we've built and fixed. Tick each item as you
confirm it works. For a clean run, use FRESH Telegram accounts (never a removed/
banned one), and set the Invite & Earn "Settle window" to 0 in the admin panel
so referrals count instantly while testing.

Legend: (U) = do it as a normal user in Telegram, (A) = do it in the admin
panel, (G) = check the Telegram group, (W) = check the website.

---

## 1. Bot basics
- [ ] (U) Send `/start` — the bot replies with the welcome + main menu.
- [ ] (U) Main menu shows the buttons: Post to Website, Promote My Business,
      Business of the Week, My Dashboard, Invite & Earn.
- [ ] (U) Bot replies within about a minute (poll mode).

## 2. Registration
- [ ] (U) A brand-new user tapping "Post to Website" or "Promote" is asked to
      register first (name, phone).
- [ ] (U) After registering once, it does NOT ask again on the next action
      (registration is remembered).
- [ ] (W) The new user appears in the website admin (Users) — bot registrations
      sync to the site.
- [ ] (A/U) `/resetme` (admin only) deletes your own record so you can re-test
      first-time registration.

## 3. Post an ad to the website (classifieds)
- [ ] (U) Pick a category.
- [ ] (U) Location flow: country shows as a dropdown keyboard; state, city and
      address are each skippable; a running location summary shows as you go.
- [ ] (U) Enter description and phone.
- [ ] (U) Send up to 5 photos; a 6th shows "Maximum of 5 photos reached".
- [ ] (U) Cancel asks for confirmation before discarding.
- [ ] (U) On publish you see "Publishing your ad, please wait..." then a "Your ad
      is now live on HabeshaList.com!" message with a View link — no admin review
      step; it publishes directly.
- [ ] (W) The ad is visible on the website immediately, with its photos.

## 4. Promote My Business — packages
- [ ] (U) "Promote My Business" shows the package picker: One-Time, Monthly,
      Yearly, with the prices you set in the panel.
- [ ] (U) Pick a package — you're taken to the payment step.

### 4a. Payment — Card (Stripe)
- [ ] (U) Choose Card — you see the "Secure Card Payment" screen with a
      "Continue to Payment" button.
- [ ] (U) Continue opens the Stripe checkout page. Pay with the test card
      `4242 4242 4242 4242`, any future expiry, any CVC.
- [ ] (U) After paying you return to the bot and it confirms payment, then
      starts the business ad form.
- [ ] (U) A promo does NOT proceed if payment isn't completed.

### 4b. Payment — Manual (Zelle / Cash App)
- [ ] (U) Choose Zelle or Cash App — it shows the handle you set in the panel
      and asks you to send a screenshot to support, then "Submit Payment Proof".
- [ ] (A) The admin receives the payment proof with Approve / Reject.
- [ ] (A) The promo only activates after the admin approves the manual payment.

### 4c. Business ad form
- [ ] (U) The form collects: business name, category (buttons), description,
      phone, website, social, address, hours, logo (photo), images (photos or
      videos, up to 5), and a call-to-action.
- [ ] (U) Skip / Back / Cancel work at each step.
- [ ] (U) "Edit" from the review screen lets you change any field.
- [ ] (U) "Preview Ad" shows the exact post as it will appear in the group.

### 4d. Scheduling
- [ ] (U) One-Time: pick a date on the month calendar, then a time.
- [ ] (U) Monthly / Yearly: pick two weekdays + times (recurring).
- [ ] (U) A confirmation summary shows before you submit.
- [ ] (A) The promo arrives as "pending review" with the schedule + media.
- [ ] (A) Approve — it books immediately (see Dashboard/Scheduled Posts fill in).
- [ ] (G) At the scheduled time the bot posts the ad into the group.
- [ ] (G) It pins per the plan (Monthly first post 24h, Yearly first-of-month
      24h) and unpins when the pin time is up.
- [ ] (U) The user gets a "your ad was posted" notification.

## 5. Business of the Week
- [ ] (U) "Business of the Week" is its own button (not inside the package
      picker), priced at what you set (default $75).
- [ ] (U) Pick a start date — the plan is 7 posts, one per day for 7 days.
- [ ] (U) Dates whose 7-day span overlaps another BOTW show as unavailable
      (one business per week — exclusivity).
- [ ] (A) Approve — all 7 daily posts appear in the schedule.
- [ ] (G) A post goes out each day for 7 days, each pinned on its day.

## 6. My Dashboard
- [ ] (U) "My Dashboard" shows ONLY your current active plan: plan name, status,
      dates, used posts (e.g. 3/8), next post time, and pin status.
- [ ] (U) View Schedule lists your upcoming posts.
- [ ] (U) My Ads shows your current ad with an Edit button.
- [ ] (U) Payment History shows amount, date, method, status.
- [ ] (U) Select Another Slot (reschedule) — pick new date/time; the schedule
      updates immediately.
- [ ] (U) Cancel Plan — future posts are canceled (already-posted stay); no auto
      refund.
- [ ] (U) Edit Ad — change the text/media; future posts reflect the change
      (already-sent posts don't change).
- [ ] (U) A fully-used plan shows a "Completed" card.

## 7. Invite & Earn (referrals)
- [ ] (U) "Invite & Earn" shows how it works.
- [ ] (U) My Referral Link is a `t.me/<bot>?start=CODE` link with a Share button.
- [ ] (U) A friend taps your link, registers, and is prompted to join the group
      with a working Join button + an "I've Joined" button.
- [ ] (U/G) After the friend joins the group, the referral counts (with Settle
      window 0 it counts right away; auto-detected if the bot is a group admin,
      or via the "I've Joined" button).
- [ ] (U) An already-registered friend can still be referred if they're not
      already in the group and not already referred by someone else.
- [ ] (U) My Progress shows a progress-to-next-reward bar + lifetime totals +
      referral history.
- [ ] (A) Reward tiers (default 20 = Monthly, 50 = BOTW, 100 = bundle) are
      editable, including the fulfillment package per tier.
- [ ] (U) When a tier is reached the reward is earned; tap Claim.
- [ ] (A) The claim appears in "Reward Claims Awaiting Approval" — Approve or
      Reject; the user is notified either way.
- [ ] (U) After approval, tap Set Up — build the ad + pick a date; it books and
      posts like a paid promo and shows in My Dashboard.
- [ ] (U) After claiming a reward, the "progress to next reward" resets while the
      lifetime invite count stays.
- [ ] (A) A bundle/manual reward (no package) notifies the admin to arrange it.

## 8. Admin panel
- [ ] (A) Log in over HTTPS (http redirects to https); wrong password is
      rejected and rate-limited.
- [ ] (A) Dashboard shows the 6 live stat cards with real numbers.
- [ ] (A) Pending Ads — approve/reject works and notifies the user in Telegram.
- [ ] (A) Payments — the ledger lists payments.
- [ ] (A) Businesses — grouped by business with spend.
- [ ] (A) Users — list, "Invited" count, and Delete (for test cleanup).
- [ ] (A) Pricing — change a package price; the bot shows the new price next time.
- [ ] (A) Payment Methods — set Zelle / Cash App / support handles; they show on
      the bot payment screen.
- [ ] (A) Keys — set the bot token / Stripe key; the panel verifies them live
      before saving (stored encrypted).
- [ ] (A) Schedule Settings — set the group, timezone, slot times, group name,
      and use "Generate & save join link" to mint a working invite link.
- [ ] (A) Scheduled Posts — see upcoming + posted; "Reset test data" clears the
      board for a fresh test.
- [ ] (A) Calendar — monthly grid with colour-coded statuses; cancel a post from
      the calendar.
- [ ] (A) Invite & Earn admin — tiers, reward claims, referrals table, manual
      gift, audit log, settle-window and require-group-join toggles.
- [ ] (A) Ad Preview — a promo's visual preview renders with its media.

## 9. Payment security (important)
- [ ] A promo never becomes active until: Stripe confirms the card payment, OR
      an admin approves a manual payment, OR it's a legitimately earned + approved
      referral reward. Confirm you cannot activate one by tapping buttons alone.

## 10. Production / security spot-checks
- [ ] Visiting `.../bot/.env` in a browser is blocked (403/404).
- [ ] Visiting `.../bot/data/bot.sqlite` is blocked (403/404).
- [ ] No PHP error text is shown to users (`display_errors` off on the host).
- [ ] After switching to the production bot token, `/start` on the PRODUCTION
      bot replies (see OPERATIONS for the token switch).

---

If anything here doesn't behave as described, note the step number and tell me —
I'll fix it. This list matches everything built through the current version.
