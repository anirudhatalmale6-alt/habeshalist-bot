<?php
// ============================================================
// PROMOTE MY BUSINESS  (Phase 2 - M1)
// Paid promotion into the HabeshaList Telegram Group.
// Flow: pick package -> pay -> business ad form (11 steps) ->
//       preview -> submit -> admin approval.
// Scheduling/auto-posting to the group are handled in later milestones.
// ============================================================

// ---- Package / price helpers ----

function promoPackage($key) {
    global $config;
    return $config['promo_packages'][$key] ?? null;
}

function promoPrice($key) {
    global $config, $db;
    $pkg = promoPackage($key);
    if (!$pkg) return 0;
    $stored = $db->getSetting("price_{$key}", null);
    return ($stored !== null && $stored !== '') ? (float)$stored : (float)$pkg['default_price'];
}

function promoFmtPrice($p) {
    $s = number_format((float)$p, 2, '.', ',');
    if (substr($s, -3) === '.00') $s = substr($s, 0, -3);
    return '$' . $s;
}

function promoFormOrder() {
    return ['business_name', 'business_category', 'description', 'phone',
            'website', 'social', 'address', 'hours', 'logo', 'images', 'cta'];
}

function promoFieldLabel($field) {
    $labels = [
        'business_name' => 'Business Name',
        'business_category' => 'Business Category',
        'description' => 'Description',
        'phone' => 'Phone Number',
        'website' => 'Website',
        'social' => 'Social Media',
        'address' => 'Address',
        'hours' => 'Business Hours',
        'logo' => 'Logo',
        'images' => 'Additional Images',
        'cta' => 'Call to Action',
    ];
    return $labels[$field] ?? $field;
}

function promoFieldRequired($field) {
    return in_array($field, ['business_name', 'business_category', 'description', 'phone'], true);
}

function promoNextField($field) {
    $order = promoFormOrder();
    $i = array_search($field, $order, true);
    if ($i === false || $i + 1 >= count($order)) return 'review';
    return $order[$i + 1];
}

function promoPrevField($field) {
    $order = promoFormOrder();
    $i = array_search($field, $order, true);
    if ($i === false || $i <= 0) return null;
    return $order[$i - 1];
}

function promoIsEdit($state) {
    return strpos($state['state'] ?? '', 'promo_edit_') === 0;
}

// ============================================================
// ENTRY: package picker & details
// ============================================================

function promoStart($userId) {
    global $tg, $db, $config;

    $buttons = [];
    foreach ($config['promo_package_order'] as $key) {
        $pkg = promoPackage($key);
        if (!$pkg) continue;
        $label = $pkg['emoji'] . ' ' . $pkg['name'] . ' - ' . promoFmtPrice(promoPrice($key));
        $buttons[] = [['text' => $label, 'callback_data' => 'promopkg_' . $key]];
    }
    $buttons[] = [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']];

    $db->setState($userId, 'promo_pick', []);
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x93\xA2 <b>Promote Your Business</b>\n\n" .
        "Reach thousands of active members in the official HabeshaList Telegram Group.\n\n" .
        "Choose a promotion package that fits your needs:",
        $buttons
    );
}

// Entry point for the Business of the Week main-menu button. It reuses the
// exact same package-details -> payment -> form -> approval flow; the only
// difference is which "package" it selects and that Back returns to the main
// menu (there is no package picker behind it).
function promoStartBotw($userId) {
    promoSelectPackage($userId, 'botw');
}

function promoSelectPackage($userId, $key) {
    global $tg, $db;

    $pkg = promoPackage($key);
    if (!$pkg) { promoStart($userId); return; }

    $price = promoPrice($key);
    $data = [
        'package_key' => $key,
        'price' => $price,
        'posts_total' => $pkg['posts_total'],
    ];
    $db->setState($userId, 'promo_package', $data);

    $features = '';
    foreach ($pkg['features'] as $f) {
        $features .= "\xE2\x9C\x85 {$f}\n";
    }

    // Business of the Week has no package picker behind it, so Back goes home.
    $backCb = ($key === 'botw') ? 'main_menu' : 'promo_start';

    $tg->sendInlineButtons($userId,
        $pkg['emoji'] . " <b>{$pkg['name']} - " . promoFmtPrice($price) . "</b>\n\n" .
        $pkg['summary'] . "\n\n" .
        $features . "\n" .
        "\xE2\x9A\xA0\xEF\xB8\x8F <i>No Refund Policy - refunds are not issued once a promotion is approved and scheduled.</i>",
        [
            [['text' => "\xE2\x9C\x85 Continue", 'callback_data' => 'promo_continue']],
            [
                ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => $backCb],
                ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'promo_cancel'],
            ],
        ]
    );
}

// ============================================================
// PAYMENT
// ============================================================

function promoShowPayment($userId, $stateData) {
    global $tg, $db;

    $db->setState($userId, 'promo_payment', $stateData);
    $price = promoFmtPrice($stateData['price'] ?? 0);
    $pkg = promoPackage($stateData['package_key'] ?? '');
    $name = $pkg['name'] ?? 'Promotion';

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x92\xB3 <b>Payment</b>\n\n" .
        "Package: <b>{$name}</b>\n" .
        "Amount: <b>{$price}</b>\n\n" .
        "Choose how you'd like to pay:",
        [
            [['text' => "\xF0\x9F\x92\xB3 Pay with Card", 'callback_data' => 'promopay_card']],
            [['text' => "\xF0\x9F\x8F\xA6 Pay with Zelle", 'callback_data' => 'promopay_zelle']],
            [['text' => "\xF0\x9F\x92\xB5 Pay with Cash App", 'callback_data' => 'promopay_cashapp']],
            [
                ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'promopkg_' . ($stateData['package_key'] ?? '')],
                ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'promo_cancel'],
            ],
        ]
    );
}

function promoHandlePayMethod($userId, $method, $state) {
    global $tg, $db, $config;

    $data = $state['data'];
    $price = promoFmtPrice($data['price'] ?? 0);

    if ($method === 'card') {
        $key = preg_replace('/\s+/', '', (string) $db->getSetting('stripe_key', $config['stripe_key']));
        // No key, or the Stripe helper file isn't present yet -> manual fallback.
        if (empty($key) || !function_exists('hl_stripe_create_session')) {
            $db->setState($userId, 'promo_payment', $data);
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x92\xB3 <b>Card payments are being set up.</b>\n\n" .
                "For now, please pay with Zelle or Cash App and send a screenshot - your promotion will be reviewed and scheduled right after.",
                [
                    [['text' => "\xF0\x9F\x8F\xA6 Pay with Zelle", 'callback_data' => 'promopay_zelle']],
                    [['text' => "\xF0\x9F\x92\xB5 Pay with Cash App", 'callback_data' => 'promopay_cashapp']],
                    [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'promo_cancel']],
                ]
            );
            return;
        }

        $amountCents = (int) round(((float) ($data['price'] ?? 0)) * 100);
        // Stripe's minimum charge is 50 cents. A free/near-zero package skips
        // payment entirely and goes straight to the ad form.
        if ($amountCents < 50) {
            $data['payment_method'] = 'card';
            $data['payment_status'] = 'paid';
            $data['receipt'] = 'HL-FREE-' . strtoupper(substr(md5($userId . microtime()), 0, 4));
            promoCardPaidProceed($userId, $data, "\xE2\x9C\x85 <b>No payment required.</b>");
            return;
        }

        $pkg = promoPackage($data['package_key'] ?? '');
        // After paying, Stripe sends the user back into THIS Telegram chat via a
        // deep link, so they land back in the bot conversation (not stranded on
        // the payment site). The bot then auto-confirms the payment on return.
        $successUrl = promoReturnLink('paid');
        $cancelUrl  = promoReturnLink('paycancel');
        $sess = hl_stripe_create_session(
            $key,
            $amountCents,
            ($pkg['name'] ?? 'HabeshaList') . ' promotion',
            $successUrl, $cancelUrl,
            ['telegram_id' => $userId, 'package_key' => $data['package_key'] ?? '']
        );

        if (empty($sess['url'])) {
            $stripeErr = $sess['error'] ?? 'unknown';
            error_log('promo stripe create session failed: ' . $stripeErr);
            // Tell the admin exactly what Stripe said, so a key problem is obvious
            // and fixable without guesswork (users never see this).
            $keyHint = substr($key, 0, 8);
            foreach (($config['admin_ids'] ?? []) as $aid) {
                $tg->sendMessage((int) $aid,
                    "\xE2\x9A\xA0\xEF\xB8\x8F <b>Card checkout failed for a user.</b>\n\n" .
                    "Stripe said: <b>" . htmlspecialchars($stripeErr, ENT_QUOTES) . "</b>\n" .
                    "Key in use starts with: <code>" . htmlspecialchars($keyHint, ENT_QUOTES) . "...</code>\n\n" .
                    "Fix it on the panel Keys page - paste your SECRET key (sk_live_ or sk_test_). The panel now verifies the key with Stripe before saving.");
            }
            $db->setState($userId, 'promo_payment', $data);
            $tg->sendInlineButtons($userId,
                "\xE2\x9A\xA0\xEF\xB8\x8F Sorry, card checkout is temporarily unavailable. Please pay with Zelle or Cash App instead and I'll get you sorted.",
                [
                    [['text' => "\xF0\x9F\x8F\xA6 Pay with Zelle", 'callback_data' => 'promopay_zelle']],
                    [['text' => "\xF0\x9F\x92\xB5 Pay with Cash App", 'callback_data' => 'promopay_cashapp']],
                    [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'promo_cancel']],
                ]
            );
            return;
        }

        $data['payment_method'] = 'card';
        $data['_stripe_session'] = $sess['id'];
        $db->setState($userId, 'promo_awaiting_card', $data);
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x94\x92 <b>Secure Card Payment</b>\n\n" .
            "You are about to pay for your promotion. Click the button below to continue to our secure payment page.",
            [
                [['text' => "Continue to Payment", 'url' => $sess['url']]],
                [['text' => "Cancel", 'callback_data' => 'promo_cancel']],
            ]
        );
        return;
    }

    // Manual methods: Zelle / Cash App
    $data['payment_method'] = $method;
    $methodName = ($method === 'zelle') ? 'Zelle' : 'Cash App';
    $settingKey = ($method === 'zelle') ? 'pay_zelle' : 'pay_cashapp';
    $handle = $db->getSetting($settingKey, $config['payment_defaults'][$settingKey] ?? '');
    $support = $db->getSetting('pay_support', $config['payment_defaults']['pay_support'] ?? '@Habesha_list');

    $msg = "\xF0\x9F\x92\xB3 <b>Pay {$price} via {$methodName}</b>\n\n";
    if (!empty($handle)) {
        $msg .= "Please send payment to:\n<b>{$handle}</b>\n\n";
    } else {
        $msg .= "Please contact {$support} to get the {$methodName} payment details.\n\n";
    }
    $msg .= "After completing your payment, please send a <b>screenshot</b> of your payment confirmation to {$support}.\n\n";
    $msg .= "Once you have sent the screenshot, tap <b>Submit Payment Proof</b> below.";

    $db->setState($userId, 'promo_awaiting_payment_proof', $data);
    $tg->sendInlineButtons($userId, $msg, [
        [['text' => "\xF0\x9F\x93\xA4 Submit Payment Proof", 'callback_data' => 'promo_paid_manual']],
        [
            ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'promo_payment_back'],
            ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'promo_cancel'],
        ],
    ]);
}

// User tapped "I've paid" after a Stripe checkout. Verify OUTBOUND with Stripe
// (no webhook needed) and, if paid, move straight into the ad form.
function promoCheckCard($userId, $state) {
    global $tg, $db, $config;

    $data = $state['data'];
    $sessionId = $data['_stripe_session'] ?? '';
    if ($sessionId === '') { promoShowPayment($userId, $data); return; }

    $key = preg_replace('/\s+/', '', (string) $db->getSetting('stripe_key', $config['stripe_key']));
    $session = hl_stripe_get_session($key, $sessionId);

    if (hl_stripe_session_paid($session)) {
        $pi = is_array($session) ? (is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : '') : '';
        $ref = preg_replace('/[^a-zA-Z0-9]/', '', $pi !== '' ? $pi : $sessionId);
        $data['payment_method'] = 'card';
        $data['payment_status'] = 'paid';
        $data['receipt'] = 'HL-CARD-' . strtoupper(substr($ref, -6));
        unset($data['_stripe_session']);
        promoCardPaidProceed($userId, $data, "\xE2\x9C\x85 <b>Payment confirmed!</b>");
        return;
    }

    // Not paid yet - let them retry (Stripe can take a moment to settle, and if
    // the user closed the page before paying we let them reopen checkout).
    $db->setState($userId, 'promo_awaiting_card', $data);
    $rows = [];
    $rows[] = [['text' => "\xF0\x9F\x94\x84 Check payment status", 'callback_data' => 'promo_check_card']];
    $rows[] = [
        ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Other methods", 'callback_data' => 'promo_payment_back'],
        ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'promo_cancel'],
    ];
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x95\x92 I couldn't confirm your payment yet. If you just completed it, give it a few seconds and tap <b>Check payment status</b>.",
        $rows
    );
}

// Build a deep link back into THIS bot's chat carrying a /start payload (used as
// Stripe's success/cancel return URL so the user lands back in the conversation).
// Falls back to the website if the bot username can't be determined.
function promoReturnLink($payload) {
    global $config;
    $user = function_exists('getBotUsername') ? getBotUsername() : '';
    if ($user && $user !== 'bot') {
        return 'https://t.me/' . $user . '?start=' . rawurlencode($payload);
    }
    return $config['website_url'] ?? 'https://www.habeshalist.com';
}

// User came back into the bot after a Stripe checkout (success deep link). If a
// card payment is pending, verify and continue; otherwise show the dashboard.
function promoReturnFromCheckout($userId) {
    global $db;
    $state = $db->getState($userId);
    if (($state['state'] ?? '') === 'promo_awaiting_card' && !empty($state['data']['_stripe_session'])) {
        promoCheckCard($userId, $state);
        return;
    }
    promoShowDashboard($userId);
}

// User cancelled on the Stripe page (cancel deep link) - bring them back to the
// payment options so they can retry or pick another method.
function promoReturnFromCancel($userId) {
    global $db;
    $state = $db->getState($userId);
    if (($state['state'] ?? '') === 'promo_awaiting_card') {
        $data = $state['data'];
        unset($data['_stripe_session']);
        promoShowPayment($userId, $data);
        return;
    }
    promoShowDashboard($userId);
}

// Payment is settled (card) - confirm and start the business ad form.
function promoCardPaidProceed($userId, $data, $headline) {
    global $tg, $db;
    $tg->sendMessage($userId,
        $headline . "\nReceipt: <b>{$data['receipt']}</b>\n\n" .
        "Now let's build your promotion. I'll guide you step by step."
    );
    promoStartForm($userId, $data);
}

function promoPaymentProceed($userId, $state, $proofFileId) {
    global $tg, $db;

    $data = $state['data'];
    $data['payment_proof'] = $proofFileId ?: '';
    $data['payment_status'] = $proofFileId ? 'awaiting_verification' : 'pending';
    $data['receipt'] = 'HL-' . strtoupper(substr(md5($userId . microtime()), 0, 5));

    $tg->sendMessage($userId,
        "\xE2\x9C\x85 <b>Payment received!</b>\n" .
        "Receipt: <b>{$data['receipt']}</b>\n\n" .
        "Now let's build your promotion. I'll guide you step by step."
    );

    promoStartForm($userId, $data);
}

// ============================================================
// BUSINESS AD FORM
// ============================================================

function promoStartForm($userId, $data) {
    global $db;
    if (!isset($data['images'])) $data['images'] = [];
    if (!isset($data['videos'])) $data['videos'] = [];
    $db->setState($userId, 'promo_business_name', $data);
    promoPromptStep($userId, 'business_name', $data, false);
}

// Combined photo+video count for the media step (capped together at 5).
function promoMediaCount($data) {
    return count($data['images'] ?? []) + count($data['videos'] ?? []);
}

function promoStepHeader($field) {
    $order = promoFormOrder();
    $i = array_search($field, $order, true);
    $n = ($i === false) ? 1 : $i + 1;
    $total = count($order);
    return "\xF0\x9F\x93\x8B <b>Step {$n} of {$total}</b>\n\n";
}

function promoNavButtons($field, $isEdit) {
    if ($isEdit) {
        return [[['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Review", 'callback_data' => 'promo_back_review']]];
    }
    $rows = [];
    if (!promoFieldRequired($field)) {
        $rows[] = [['text' => "\xE2\x8F\xA9 Skip", 'callback_data' => 'promoskip_' . $field]];
    }
    $navRow = [];
    if (promoPrevField($field) !== null) {
        $navRow[] = ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'promoback_' . $field];
    }
    $navRow[] = ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'promo_cancel'];
    $rows[] = $navRow;
    return $rows;
}

function promoPromptStep($userId, $field, $data, $isEdit = false) {
    global $tg, $config;

    $header = promoStepHeader($field);
    $current = '';
    if ($isEdit) {
        $header = "\xE2\x9C\x8F\xEF\xB8\x8F <b>Edit " . promoFieldLabel($field) . "</b>\n\n";
        if ($field === 'images') {
            $cnt = count($data['images'] ?? []);
            $current = "Current: {$cnt} image(s)\n\n";
        } elseif ($field === 'logo') {
            $current = !empty($data['logo']) ? "A logo is currently set.\n\n" : '';
        } elseif (!empty($data[$field])) {
            $current = "Current: <b>{$data[$field]}</b>\n\n";
        }
    }

    switch ($field) {
        case 'business_category':
            $buttons = [];
            $row = [];
            foreach ($config['business_categories'] as $idx => $cat) {
                $row[] = ['text' => $cat, 'callback_data' => 'promocat_' . $idx];
                if (count($row) === 2) { $buttons[] = $row; $row = []; }
            }
            if (!empty($row)) $buttons[] = $row;
            if ($isEdit) {
                $buttons[] = [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Review", 'callback_data' => 'promo_back_review']];
            } else {
                $nav = [];
                if (promoPrevField($field) !== null) {
                    $nav[] = ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'promoback_' . $field];
                }
                $nav[] = ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'promo_cancel'];
                $buttons[] = $nav;
            }
            $tg->sendInlineButtons($userId, $header . $current . "Select your <b>business category</b>:", $buttons);
            return;

        case 'logo':
            $rows = $isEdit
                ? [
                    [['text' => "\xE2\x8F\xA9 Skip Logo", 'callback_data' => 'promoskip_logo']],
                    [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Review", 'callback_data' => 'promo_back_review']],
                  ]
                : promoNavButtonsPhoto('logo', false);
            $tg->sendInlineButtons($userId,
                $header . $current . "\xF0\x9F\x96\xBC Upload your <b>business logo</b> (send it as a photo), or tap Skip.",
                $rows
            );
            return;

        case 'images':
            $cnt = count($data['images'] ?? []);
            $rows = [];
            $rows[] = [['text' => "\xE2\x9C\x85 Done", 'callback_data' => 'promo_images_done']];
            if (!$isEdit) {
                $rows[] = [['text' => "\xE2\x8F\xA9 Skip", 'callback_data' => 'promo_images_skip']];
                $navRow = [];
                if (promoPrevField('images') !== null) {
                    $navRow[] = ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'promoback_images'];
                }
                $navRow[] = ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'promo_cancel'];
                $rows[] = $navRow;
            } else {
                $rows[] = [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Review", 'callback_data' => 'promo_back_review']];
            }
            $cnt = promoMediaCount($data);
            $tg->sendInlineButtons($userId,
                $header . $current . "\xF0\x9F\x93\xB8 Send up to 5 <b>photos or videos</b> for your ad. Tap Done when finished." .
                ($cnt ? "\n\nSo far: {$cnt}/5" : ''),
                $rows
            );
            return;

        default:
            // text fields
            $prompts = [
                'business_name' => 'What is your <b>business name</b>?',
                'description' => 'Write a short <b>description</b> of your business (what you offer, why customers should come):',
                'phone' => 'Enter your business <b>phone number</b>:',
                'website' => "Enter your <b>website</b> (optional):",
                'social' => "Enter your <b>social media</b> link or handle (optional):",
                'address' => "Enter your business <b>address</b> (optional):",
                'hours' => "Enter your <b>business hours</b> (optional), e.g. Mon-Fri 9am-6pm:",
                'cta' => "Enter a <b>call to action</b> (optional), e.g. \"Visit us today!\" or \"Call now for 20% off\":",
            ];
            $prompt = $prompts[$field] ?? ('Enter ' . promoFieldLabel($field) . ':');
            $tg->sendInlineButtons($userId, $header . $current . $prompt, promoNavButtons($field, $isEdit));
            return;
    }
}

function promoNavButtonsPhoto($field, $isEdit) {
    $rows = [];
    $rows[] = [['text' => "\xE2\x8F\xA9 Skip", 'callback_data' => 'promoskip_' . $field]];
    $navRow = [];
    if (promoPrevField($field) !== null) {
        $navRow[] = ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'promoback_' . $field];
    }
    $navRow[] = ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'promo_cancel'];
    $rows[] = $navRow;
    return $rows;
}

// Advance to the next form step (or the review screen)
function promoAdvance($userId, $data, $fromField) {
    global $db;
    $next = promoNextField($fromField);
    if ($next === 'review') {
        $db->setState($userId, 'promo_review', $data);
        promoShowReview($userId, $data);
        return;
    }
    $db->setState($userId, 'promo_' . $next, $data);
    promoPromptStep($userId, $next, $data, false);
}

// ============================================================
// TEXT & PHOTO INPUT HANDLERS
// ============================================================

function promoHandleStateInput($userId, $msg, $state) {
    global $tg, $db, $config;

    $st = $state['state'];
    $data = $state['data'];
    $text = trim($msg['text'] ?? '');

    // Returned from the Stripe page without the deep link auto-firing? Any
    // message while awaiting a card payment triggers a verification, so the user
    // is never stuck.
    if ($st === 'promo_awaiting_card') { promoCheckCard($userId, $state); return; }

    // ---- Admin: set a setting value (price / payment handle) ----
    if ($st === 'promo_admin_set') {
        $key = $data['setting_key'] ?? '';
        if ($key === '') { promoAdminMenu($userId); return; }
        if (strpos($key, 'price_') === 0) {
            $val = str_replace(['$', ','], '', $text);
            if (!is_numeric($val) || $val < 0) {
                $tg->sendMessage($userId, "Please enter a valid number (e.g. 50 or 49.99):");
                return;
            }
            $db->setSetting($key, (string)(float)$val);
        } else {
            $db->setSetting($key, $text);
        }
        $db->setState($userId, 'idle', []);
        $tg->sendMessage($userId, "\xE2\x9C\x85 Updated.");
        promoAdminMenu($userId);
        return;
    }

    // ---- Scheduling: user typed a custom time ----
    if ($st === 'promo_sched_time_text') {
        $hm = promoParseTypedTime($text);
        if ($hm === null) {
            $tg->sendMessage($userId, "Sorry, I couldn't read that time. Please type it like 9:00 AM, 2:30 PM, or 14:30:");
            return;
        }
        $ctx = $data['_sched_ctx'] ?? 'single';
        if ($ctx === 'single') {
            promoFinalizeSingle($userId, $data, $hm);
        } elseif (strpos($ctx, 'rec') === 0) {
            $n = (int) substr($ctx, 3);
            if (!isset($data['_sched_slots'])) $data['_sched_slots'] = [];
            $data['_sched_slots'][$n]['time'] = $hm;
            $db->setState($userId, 'promo_sched_rec', $data);
            if ($n === 1) { promoWeekdayGrid($userId, 2, $data); }
            else { promoFinalizeRecurring($userId, $data); }
        }
        return;
    }

    $isEdit = promoIsEdit($state);
    $field = $isEdit ? substr($st, strlen('promo_edit_')) : substr($st, strlen('promo_'));

    // Photo-only steps: user typed instead of sending a photo
    if ($field === 'logo' || $field === 'images') {
        $tg->sendMessage($userId, "Please send a photo, or tap one of the buttons above.");
        return;
    }

    // Validate per field
    switch ($field) {
        case 'business_name':
            if (mb_strlen($text) < 2) {
                $tg->sendMessage($userId, "Please enter a valid business name (at least 2 characters):");
                return;
            }
            $data['business_name'] = $text;
            break;

        case 'business_category':
            $matched = null;
            foreach ($config['business_categories'] as $cat) {
                if (strcasecmp($cat, $text) === 0) { $matched = $cat; break; }
            }
            if ($matched === null) {
                promoPromptStep($userId, 'business_category', $data, $isEdit);
                return;
            }
            $data['business_category'] = $matched;
            break;

        case 'description':
            if (mb_strlen($text) < 10) {
                $tg->sendMessage($userId, "Description must be at least 10 characters. Please add more detail:");
                return;
            }
            $data['description'] = $text;
            break;

        case 'phone':
            $phone = preg_replace('/[^0-9+\-\s()]/', '', $text);
            if (strlen(preg_replace('/\D/', '', $phone)) < 7) {
                $tg->sendMessage($userId, "Please enter a valid phone number:");
                return;
            }
            $data['phone'] = $phone;
            break;

        case 'website':
        case 'social':
        case 'address':
        case 'hours':
        case 'cta':
            $data[$field] = $text;
            break;

        default:
            return;
    }

    if ($isEdit) {
        promoPersistEditField($data, $field);
        $db->setState($userId, 'promo_review', $data);
        promoShowReview($userId, $data);
    } else {
        promoAdvance($userId, $data, $field);
    }
}

function promoHandlePhoto($userId, $msg) {
    global $tg, $db;

    $state = $db->getState($userId);
    $st = $state['state'];
    if (strpos($st, 'promo_') !== 0) return false;
    $data = $state['data'];
    $photo = end($msg['photo']);

    // Payment proof screenshot
    if ($st === 'promo_awaiting_payment_proof') {
        promoPaymentProceed($userId, $state, $photo['file_id']);
        return true;
    }

    $isEdit = promoIsEdit($state);
    $field = $isEdit ? substr($st, strlen('promo_edit_')) : substr($st, strlen('promo_'));

    if ($field === 'logo') {
        $data['logo'] = $photo['file_id'];
        if ($isEdit) {
            unset($data['_last_media_group']);
            promoPersistEditField($data, 'logo');
            $db->setState($userId, 'promo_review', $data);
            promoShowReview($userId, $data);
        } else {
            promoAdvance($userId, $data, 'logo');
        }
        return true;
    }

    if ($field === 'images') {
        $data['images'] = $data['images'] ?? [];
        $data['videos'] = $data['videos'] ?? [];
        $doneCb = 'promo_images_done';

        if (promoMediaCount($data) >= 5) {
            if (empty($data['_img_max_notified'])) {
                $data['_img_max_notified'] = true;
                $db->setState($userId, $st, $data);
                $tg->sendInlineButtons($userId,
                    "\xF0\x9F\x93\xB8 Maximum of 5 items reached. Tap Done to continue.",
                    [[['text' => "\xE2\x9C\x85 Done", 'callback_data' => $doneCb]]]
                );
            }
            return true;
        }

        $data['images'][] = $photo['file_id'];
        $count = promoMediaCount($data);

        // De-dupe the confirmation message for album uploads
        $mediaGroupId = $msg['media_group_id'] ?? null;
        $sendMsg = !($mediaGroupId && $mediaGroupId === ($data['_last_media_group'] ?? null));
        if ($mediaGroupId) $data['_last_media_group'] = $mediaGroupId;

        $db->setState($userId, $st, $data);

        if ($count >= 5) {
            $data['_img_max_notified'] = true;
            $db->setState($userId, $st, $data);
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x93\xB8 Image received! (5/5) Maximum reached. Tap Done to continue.",
                [[['text' => "\xE2\x9C\x85 Done", 'callback_data' => $doneCb]]]
            );
        } elseif ($sendMsg) {
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x93\xB8 Image received! ({$count}/5)\n\nSend more or tap Done.",
                [[['text' => "\xE2\x9C\x85 Done", 'callback_data' => $doneCb]]]
            );
        }
        return true;
    }

    return false;
}

// Video uploads during the media step. Mirrors promoHandlePhoto's images branch
// so a video is accepted exactly like a photo and gets the same confirmation.
function promoHandleVideo($userId, $msg) {
    global $tg, $db;

    $state = $db->getState($userId);
    $st = $state['state'];
    if (strpos($st, 'promo_') !== 0) return false;
    $data = $state['data'];

    // Pull the file_id from a video message, or a video sent as a document.
    $fileId = '';
    if (isset($msg['video']['file_id'])) {
        $fileId = $msg['video']['file_id'];
    } elseif (isset($msg['document']['file_id']) &&
              isset($msg['document']['mime_type']) &&
              strpos($msg['document']['mime_type'], 'video/') === 0) {
        $fileId = $msg['document']['file_id'];
    }
    if ($fileId === '') return false;

    $isEdit = promoIsEdit($state);
    $field = $isEdit ? substr($st, strlen('promo_edit_')) : substr($st, strlen('promo_'));

    // Videos are only meaningful on the media ("images") step.
    if ($field !== 'images') {
        $tg->sendMessage($userId, "Please add videos on the photos/videos step of the form.");
        return true;
    }

    $data['images'] = $data['images'] ?? [];
    $data['videos'] = $data['videos'] ?? [];
    $doneCb = 'promo_images_done';

    if (promoMediaCount($data) >= 5) {
        if (empty($data['_img_max_notified'])) {
            $data['_img_max_notified'] = true;
            $db->setState($userId, $st, $data);
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x93\xB8 Maximum of 5 items reached. Tap Done to continue.",
                [[['text' => "\xE2\x9C\x85 Done", 'callback_data' => $doneCb]]]
            );
        }
        return true;
    }

    $data['videos'][] = $fileId;
    $count = promoMediaCount($data);

    // De-dupe the confirmation for album uploads (same as photos).
    $mediaGroupId = $msg['media_group_id'] ?? null;
    $sendMsg = !($mediaGroupId && $mediaGroupId === ($data['_last_media_group'] ?? null));
    if ($mediaGroupId) $data['_last_media_group'] = $mediaGroupId;

    $db->setState($userId, $st, $data);

    if ($count >= 5) {
        $data['_img_max_notified'] = true;
        $db->setState($userId, $st, $data);
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x8E\xA5 Video received! (5/5) Maximum reached. Tap Done to continue.",
            [[['text' => "\xE2\x9C\x85 Done", 'callback_data' => $doneCb]]]
        );
    } elseif ($sendMsg) {
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x8E\xA5 Video received! ({$count}/5)\n\nSend more or tap Done.",
            [[['text' => "\xE2\x9C\x85 Done", 'callback_data' => $doneCb]]]
        );
    }
    return true;
}

// ---- Callback-driven field actions ----

function promoSelectCategory($userId, $idx, $state) {
    global $db, $config;
    $cat = $config['business_categories'][$idx] ?? null;
    if ($cat === null) return;
    $data = $state['data'];
    $data['business_category'] = $cat;
    if (promoIsEdit($state)) {
        promoPersistEditField($data, 'business_category');
        $db->setState($userId, 'promo_review', $data);
        promoShowReview($userId, $data);
    } else {
        promoAdvance($userId, $data, 'business_category');
    }
}

function promoSkipField($userId, $field, $state) {
    global $db;
    $data = $state['data'];
    $data[$field] = ($field === 'images') ? [] : '';
    if (promoIsEdit($state)) {
        promoPersistEditField($data, $field);
        $db->setState($userId, 'promo_review', $data);
        promoShowReview($userId, $data);
    } else {
        promoAdvance($userId, $data, $field);
    }
}

function promoBackField($userId, $field, $state) {
    global $db;
    $data = $state['data'];
    unset($data['_last_media_group'], $data['_img_max_notified']);
    $prev = promoPrevField($field);
    if ($prev === null) {
        promoShowPayment($userId, $data);
        return;
    }
    $db->setState($userId, 'promo_' . $prev, $data);
    promoPromptStep($userId, $prev, $data, false);
}

function promoImagesDone($userId, $state) {
    global $db;
    $data = $state['data'];
    unset($data['_last_media_group'], $data['_img_max_notified']);
    if (promoIsEdit($state)) {
        promoPersistEditField($data, 'images');
        // videos aren't a standalone form step, so persist them explicitly.
        $id = (int) ($data['_edit_promo_id'] ?? 0);
        if ($id > 0) $db->updatePromotion($id, ['videos' => $data['videos'] ?? []]);
        $db->setState($userId, 'promo_review', $data);
        promoShowReview($userId, $data);
    } else {
        promoAdvance($userId, $data, 'images');
    }
}

// ============================================================
// REVIEW / EDIT / SUBMIT
// ============================================================

function promoSummaryText($data, $forAdmin = false) {
    $pkg = promoPackage($data['package_key'] ?? '');
    $lines = [];
    $lines[] = "\xF0\x9F\x8F\xA2 Business: <b>" . ($data['business_name'] ?? '') . "</b>";
    if (!empty($data['business_category'])) $lines[] = "\xF0\x9F\x93\x82 Category: <b>{$data['business_category']}</b>";
    if (!empty($data['description'])) $lines[] = "\xF0\x9F\x93\x84 {$data['description']}";
    if (!empty($data['phone'])) $lines[] = "\xF0\x9F\x93\x9E {$data['phone']}";
    if (!empty($data['website'])) $lines[] = "\xF0\x9F\x8C\x90 {$data['website']}";
    if (!empty($data['social'])) $lines[] = "\xF0\x9F\x93\xB1 {$data['social']}";
    if (!empty($data['address'])) $lines[] = "\xF0\x9F\x93\x8D {$data['address']}";
    if (!empty($data['hours'])) $lines[] = "\xF0\x9F\x95\x92 {$data['hours']}";
    if (!empty($data['cta'])) $lines[] = "\xF0\x9F\x91\x89 <b>{$data['cta']}</b>";
    $logoTxt = !empty($data['logo']) ? 'Yes' : 'No';
    $imgCount = count($data['images'] ?? []);
    $vidCount = count($data['videos'] ?? []);
    $lines[] = "\xF0\x9F\x96\xBC Logo: {$logoTxt}   \xF0\x9F\x93\xB8 Images: {$imgCount}   \xF0\x9F\x8E\xA5 Videos: {$vidCount}";

    $meta = "\xF0\x9F\x93\xA6 Package: <b>" . ($pkg['name'] ?? '') . "</b> (" . promoFmtPrice($data['price'] ?? 0) . ")\n";
    if ($forAdmin) {
        $pm = $data['payment_method'] ?? '';
        $ps = $data['payment_status'] ?? '';
        $meta .= "\xF0\x9F\x92\xB3 Payment: <b>" . strtoupper($pm) . "</b> ({$ps})\n";
        if (!empty($data['receipt'])) $meta .= "\xF0\x9F\xA7\xBE Receipt: {$data['receipt']}\n";
    }

    return $meta . "\n" . implode("\n", $lines);
}

function promoShowReview($userId, $data) {
    global $tg, $db;
    $db->setState($userId, 'promo_review', $data);

    // Editing an already-saved ad: changes are persisted as they're made, so we
    // just offer Edit-more / Preview / Done (back to dashboard) - no re-submit
    // or re-scheduling, which would duplicate the promotion.
    if (!empty($data['_edit_promo_id'])) {
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\x8B <b>Your Ad</b>\n\n" .
            promoSummaryText($data, false) . "\n\n" .
            "Your changes are saved automatically. Edit another field, preview it, or tap Done.",
            [
                [
                    ['text' => "\xF0\x9F\x91\x81 Preview Ad", 'callback_data' => 'promo_preview'],
                    ['text' => "\xE2\x9C\x8F\xEF\xB8\x8F Edit", 'callback_data' => 'promo_edit'],
                ],
                [['text' => "\xE2\x9C\x85 Done", 'callback_data' => 'promo_dashboard']],
            ]
        );
        return;
    }

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x93\x8B <b>Review Your Promotion</b>\n\n" .
        promoSummaryText($data, false) . "\n\n" .
        "Everything look good? Tap Preview to see exactly how your ad will appear in the group, then pick your date & time.",
        [
            [
                ['text' => "\xF0\x9F\x91\x81 Preview Ad", 'callback_data' => 'promo_preview'],
                ['text' => "\xE2\x9C\x8F\xEF\xB8\x8F Edit", 'callback_data' => 'promo_edit'],
            ],
            [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'promo_cancel']],
        ]
    );
}

// If the edit data has been lost (e.g. the user tapped an old Edit button after
// the flow was reset), recover it from their current saved promotion so the
// form is never blank. Returns the data array to use, or null if nothing to edit.
function promoRecoverEditData($userId, $state) {
    $data = $state['data'] ?? [];
    // A live build/edit has the business name in memory - use it as-is.
    if (!empty($data['business_name'])) return $data;
    // Otherwise pull the current active plan back into the form.
    $promo = promoActivePromotion($userId);
    if (!$promo) return null;
    return promoLoadPromoIntoData($promo);
}

function promoEditMenu($userId, $state) {
    global $tg, $db;
    $data = promoRecoverEditData($userId, $state);
    if ($data === null) { promoShowDashboard($userId); return; }
    $db->setState($userId, 'promo_review', $data);
    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x8F\xEF\xB8\x8F <b>What would you like to edit?</b>",
        [
            [
                ['text' => "Business Name", 'callback_data' => 'promoedit_business_name'],
                ['text' => "Category", 'callback_data' => 'promoedit_business_category'],
            ],
            [
                ['text' => "Description", 'callback_data' => 'promoedit_description'],
                ['text' => "Phone", 'callback_data' => 'promoedit_phone'],
            ],
            [
                ['text' => "Website", 'callback_data' => 'promoedit_website'],
                ['text' => "Social", 'callback_data' => 'promoedit_social'],
            ],
            [
                ['text' => "Address", 'callback_data' => 'promoedit_address'],
                ['text' => "Hours", 'callback_data' => 'promoedit_hours'],
            ],
            [
                ['text' => "Logo", 'callback_data' => 'promoedit_logo'],
                ['text' => "Images", 'callback_data' => 'promoedit_images'],
            ],
            [['text' => "Call to Action", 'callback_data' => 'promoedit_cta']],
            [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Review", 'callback_data' => 'promo_back_review']],
        ]
    );
}

function promoEditField($userId, $field, $state) {
    global $db;
    if (!in_array($field, promoFormOrder(), true)) return;
    $data = promoRecoverEditData($userId, $state);
    if ($data === null) { promoShowDashboard($userId); return; }
    $db->setState($userId, 'promo_edit_' . $field, $data);
    promoPromptStep($userId, $field, $data, true);
}

function promoBackToReview($userId, $state) {
    global $db;
    $data = $state['data'];
    unset($data['_last_media_group'], $data['_img_max_notified']);
    $db->setState($userId, 'promo_review', $data);
    promoShowReview($userId, $data);
}

function promoSubmit($userId, $state) {
    global $tg, $db;

    $data = $state['data'];

    // Editing a saved ad already persists changes - never create a duplicate.
    if (!empty($data['_edit_promo_id'])) { promoShowDashboard($userId); return; }
    // Guard against a stale/blank Submit tap after the flow was reset.
    if (empty($data['business_name'])) {
        $tg->sendInlineButtons($userId,
            "That promotion is no longer in progress. Tap below to start a new one or view your dashboard.",
            [
                [['text' => "\xF0\x9F\x93\xA2 Promote My Business", 'callback_data' => 'promote']],
                [['text' => "\xF0\x9F\x93\x8A My Dashboard", 'callback_data' => 'promo_dashboard']],
            ]);
        return;
    }

    $user = $db->getUser($userId);
    $data['status'] = 'pending_review';
    if (empty($data['payment_status'])) $data['payment_status'] = 'pending';

    $promoId = $db->createPromotion($userId, $data);
    $db->setState($userId, 'idle', []);

    $receipt = $data['receipt'] ?? '';
    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x85 <b>Promotion submitted for review!</b>\n\n" .
        ($receipt ? "Receipt: <b>{$receipt}</b>\n" : '') .
        "Status: <b>Pending Review</b>\n\n" .
        "Our team will verify your payment and review your ad (usually within 24 hours). " .
        "Your posts are already scheduled for:\n" . promoScheduleSummary($data['schedule'] ?? []) . "\n\n" .
        "You'll be notified here as soon as it's approved and the first post goes live.",
        [
            [['text' => "\xF0\x9F\x93\x8A My Dashboard", 'callback_data' => 'promo_dashboard']],
            [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']],
        ]
    );

    promoNotifyAdmins($promoId, $data, $user);
}

function promoNotifyAdmins($promoId, $data, $user) {
    global $tg, $db, $config;

    $poster = $user['name'] ?? 'Unknown';
    $phone = $user['phone'] ?? '';

    $summary = "\xF0\x9F\x94\x94 <b>New promotion awaiting review</b>\n\n" .
        promoSummaryText($data, true) . "\n\n" .
        "\xF0\x9F\x91\xA4 Submitted by: <b>{$poster}</b>" . ($phone ? " ({$phone})" : '') . "\n\n" .
        "Approve to schedule it for the group, or Reject.";

    $buttons = [[
        ['text' => "\xE2\x9C\x85 Approve", 'callback_data' => "promo_approve_{$promoId}"],
        ['text' => "\xE2\x9D\x8C Reject", 'callback_data' => "promo_reject_{$promoId}"],
    ]];

    foreach ($config['admin_ids'] as $adminId) {
        if (!empty($data['logo'])) {
            $tg->sendPhoto($adminId, $data['logo'], "\xF0\x9F\x96\xBC Logo - {$data['business_name']}");
        }
        if (!empty($data['images'])) {
            $tg->sendMediaGroup($adminId, $data['images']);
        }
        if (!empty($data['payment_proof'])) {
            $tg->sendPhoto($adminId, $data['payment_proof'], "\xF0\x9F\x92\xB3 Payment proof - {$data['receipt']}");
        }
        $tg->sendInlineButtons($adminId, $summary, $buttons);
    }
}

function promoModerate($adminId, $promoId, $decision) {
    global $tg, $db;

    if (!isAdmin($adminId)) return;

    $promo = $db->getPromotion($promoId);
    if (!$promo) {
        $tg->sendMessage($adminId, "That promotion could not be found.");
        return;
    }
    if (($promo['status'] ?? '') !== 'pending_review') {
        $tg->sendMessage($adminId, "This promotion was already handled (status: {$promo['status']}).");
        return;
    }

    $posterTid = $promo['telegram_id'];
    $bname = $promo['business_name'] ?: 'your business';

    if ($decision === 'approve') {
        $db->updatePromotion($promoId, ['status' => 'approved', 'payment_status' => 'verified']);
        // Book the schedule right away so the user's dashboard (Next post /
        // Upcoming Schedule) fills in immediately, instead of waiting for the
        // next scheduler tick. Booking never posts to Telegram - only the
        // scheduler's postDue() does - so this is safe to run inline.
        promoRebookNow($promoId);

        // Also publish the business to the website (in addition to the group
        // post), if enabled. Wrapped so any website/bridge hiccup can never block
        // the approval itself.
        if (function_exists('publishPromotionToWebsite') &&
            $db->getSetting('promo_publish_website', '1') === '1') {
            try {
                $res = publishPromotionToWebsite($promo);
                if (is_array($res) && !empty($res['success']) && !empty($res['osclass_id'])) {
                    $db->updatePromotion($promoId, [
                        'website_item_id' => (int) $res['osclass_id'],
                        'website_status'  => 'live',
                    ]);
                } else {
                    $why = is_array($res) ? ($res['error'] ?? 'unknown') : 'no response';
                    $db->updatePromotion($promoId, ['website_status' => 'failed']);
                    error_log('promo website publish returned failure: ' . $why);
                }
            } catch (Throwable $e) {
                $db->updatePromotion($promoId, ['website_status' => 'failed']);
                error_log('promo website publish failed: ' . $e->getMessage());
            }
        }

        $tg->sendMessage($adminId, "\xE2\x9C\x85 Approved: <b>{$bname}</b>.");
        $tg->sendInlineButtons($posterTid,
            "\xF0\x9F\x8E\x89 <b>Great news!</b> Your promotion for <b>{$bname}</b> has been approved.\n\n" .
            "It will be posted in the HabeshaList Telegram Group as scheduled. You'll be notified once it goes live. Thank you!",
            [[['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]
        );
    } else {
        $db->updatePromotion($promoId, ['status' => 'rejected']);
        $tg->sendMessage($adminId, "\xE2\x9D\x8C Rejected: <b>{$bname}</b>.");
        $tg->sendInlineButtons($posterTid,
            "Regarding your promotion for <b>{$bname}</b> - unfortunately it wasn't approved this time. " .
            "Please contact our support team if you have any questions about your payment or would like to resubmit.",
            [[['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]
        );
    }
}

// ============================================================
// CANCEL
// ============================================================

function promoConfirmCancel($userId, $state) {
    global $tg, $db;
    $db->setState($userId, 'promo_confirm_cancel', [
        'prev_state' => $state['state'],
        'prev_data' => $state['data'],
    ]);
    $tg->sendInlineButtons($userId, "Are you sure you want to cancel this promotion?", [[
        ['text' => "\xE2\x9C\x85 Yes, cancel", 'callback_data' => 'promo_cancel_yes'],
        ['text' => "\xE2\x9D\x8C No, keep going", 'callback_data' => 'promo_cancel_no'],
    ]]);
}

function promoDoCancel($userId) {
    global $db;
    $user = $db->getUser($userId);
    $db->setState($userId, 'idle', []);
    showMainMenu($userId, $user['name'] ?? 'there');
}

function promoRestore($userId, $state) {
    global $db;
    $prevState = $state['data']['prev_state'] ?? 'idle';
    $prevData = $state['data']['prev_data'] ?? [];
    $db->setState($userId, $prevState, $prevData);

    if ($prevState === 'promo_review') {
        promoShowReview($userId, $prevData);
        return;
    }
    if ($prevState === 'promo_payment') {
        promoShowPayment($userId, $prevData);
        return;
    }
    if ($prevState === 'promo_package') {
        promoSelectPackage($userId, $prevData['package_key'] ?? '');
        return;
    }
    if (strpos($prevState, 'promo_') === 0) {
        $field = substr($prevState, strlen('promo_'));
        if (in_array($field, promoFormOrder(), true)) {
            promoPromptStep($userId, $field, $prevData, false);
            return;
        }
    }
    promoStart($userId);
}

// ============================================================
// ADMIN: edit prices & payment handles
// ============================================================

function promoAdminMenu($userId) {
    global $tg, $db, $config;
    if (!isAdmin($userId)) return;

    $lines = "\xE2\x9A\x99\xEF\xB8\x8F <b>Promotion Settings</b>\n\n<b>Package prices:</b>\n";
    foreach ($config['promo_package_order'] as $key) {
        $pkg = promoPackage($key);
        $lines .= "- {$pkg['name']}: <b>" . promoFmtPrice(promoPrice($key)) . "</b>\n";
    }
    if (promoPackage('botw')) {
        $lines .= "- " . promoPackage('botw')['name'] . ": <b>" . promoFmtPrice(promoPrice('botw')) . "</b>\n";
    }
    $zelle = $db->getSetting('pay_zelle', $config['payment_defaults']['pay_zelle']);
    $cashapp = $db->getSetting('pay_cashapp', $config['payment_defaults']['pay_cashapp']);
    $lines .= "\n<b>Payment handles:</b>\n";
    $lines .= "- Zelle: <b>" . ($zelle ?: 'not set') . "</b>\n";
    $lines .= "- Cash App: <b>" . ($cashapp ?: 'not set') . "</b>\n";

    $tg->sendInlineButtons($userId, $lines, [
        [['text' => "Edit One-Time Price", 'callback_data' => 'promoset_price_one_time']],
        [['text' => "Edit Monthly Price", 'callback_data' => 'promoset_price_monthly']],
        [['text' => "Edit Yearly Price", 'callback_data' => 'promoset_price_yearly']],
        [['text' => "Edit Business of the Week Price", 'callback_data' => 'promoset_price_botw']],
        [['text' => "Edit Zelle handle", 'callback_data' => 'promoset_pay_zelle']],
        [['text' => "Edit Cash App handle", 'callback_data' => 'promoset_pay_cashapp']],
        [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']],
    ]);
}

function promoAdminEdit($userId, $settingKey) {
    global $tg, $db, $config;
    if (!isAdmin($userId)) return;

    $allowed = ['price_one_time', 'price_monthly', 'price_yearly', 'price_botw', 'pay_zelle', 'pay_cashapp'];
    if (!in_array($settingKey, $allowed, true)) return;

    $db->setState($userId, 'promo_admin_set', ['setting_key' => $settingKey]);

    if (strpos($settingKey, 'price_') === 0) {
        $pkgKey = substr($settingKey, strlen('price_'));
        $current = promoFmtPrice(promoPrice($pkgKey));
        $tg->sendInlineButtons($userId,
            "Current " . promoPackage($pkgKey)['name'] . " price: <b>{$current}</b>\n\n" .
            "Enter the new price (number only, e.g. 50):",
            [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'main_menu']]]
        );
    } else {
        $label = ($settingKey === 'pay_zelle') ? 'Zelle' : 'Cash App';
        $current = $db->getSetting($settingKey, $config['payment_defaults'][$settingKey] ?? '');
        $tg->sendInlineButtons($userId,
            "Current {$label} handle: <b>" . ($current ?: 'not set') . "</b>\n\n" .
            "Enter the new {$label} handle:",
            [[['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'main_menu']]]
        );
    }
}

// ============================================================
// PREVIEW + SCHEDULING  (Phase 2 - M scheduling picker)
// After the review the user (1) previews the exact group post, then
// (2) picks when it runs: one date+time for a one-time / Business of the
// Week post, or two recurring weekday+time slots for monthly / yearly.
// ============================================================

function promoSchedTz() {
    global $db;
    $tz = $db->getSetting('sched_tz', 'America/New_York');
    try { return new DateTimeZone($tz); }
    catch (\Throwable $e) { return new DateTimeZone('America/New_York'); }
}

// "08:30" -> "8:30 AM"
function promoTimeLabel($hm) {
    $p = DateTime::createFromFormat('H:i', $hm);
    return $p ? $p->format('g:i A') : $hm;
}

// The 3 admin-configured slot times, used as one-tap "popular" suggestions.
function promoPopularTimes() {
    global $db;
    return [
        $db->getSetting('sched_slot_morning', '08:30'),
        $db->getSetting('sched_slot_lunch', '12:30'),
        $db->getSetting('sched_slot_evening', '19:30'),
    ];
}

// ---- Preview: render the post exactly as the scheduler will send it ----

function promoShowPreview($userId, $data) {
    global $tg, $db;
    $db->setState($userId, 'promo_preview', $data);

    $text = HL_Scheduler::renderPostText($data);
    $logo = $data['logo'] ?? '';
    $images = $data['images'] ?? [];
    $videos = $data['videos'] ?? [];

    // Header note so it's clear this block is the live preview.
    $tg->sendMessage($userId,
        "\xF0\x9F\x91\x81 <b>Preview</b> - this is exactly how your ad will look in the group:");

    // Mirror exactly what postOne() sends to the group.
    if ($logo !== '') {
        $tg->sendPhoto($userId, $logo, $text);
        if (!empty($images)) $tg->sendMediaGroup($userId, $images);
        foreach ($videos as $v) { $tg->sendVideo($userId, $v); }
    } elseif (!empty($videos)) {
        $tg->sendVideo($userId, $videos[0], $text);
        for ($i = 1; $i < count($videos); $i++) { $tg->sendVideo($userId, $videos[$i]); }
        if (!empty($images)) $tg->sendMediaGroup($userId, $images);
    } elseif (!empty($images)) {
        $tg->sendMediaGroup($userId, $images);
        $tg->sendMessage($userId, $text);
    } else {
        $tg->sendMessage($userId, $text);
    }

    // Editing a saved ad: no scheduling here (times are managed from the
    // dashboard's "Select Another Slot"), just edit/preview and go back.
    if (!empty($data['_edit_promo_id'])) {
        $tg->sendInlineButtons($userId,
            "This is your updated ad. Edit another field or go back.",
            [
                [
                    ['text' => "\xE2\x9C\x8F\xEF\xB8\x8F Edit Ad", 'callback_data' => 'promo_edit'],
                    ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'promo_back_review'],
                ],
            ]
        );
        return;
    }

    $tg->sendInlineButtons($userId,
        "Looks good? Next, choose when it should be posted.",
        [
            [['text' => "\xF0\x9F\x93\x85 Choose Date & Time", 'callback_data' => 'promo_sched_start']],
            [
                ['text' => "\xE2\x9C\x8F\xEF\xB8\x8F Edit Ad", 'callback_data' => 'promo_edit'],
                ['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Review", 'callback_data' => 'promo_back_review'],
            ],
        ]
    );
}

// ---- Entry to the scheduling picker (branches by package) ----

function promoSchedStart($userId, $state) {
    global $db;
    $data = $state['data'];
    $key = $data['package_key'] ?? 'one_time';

    // clear any half-finished picks
    unset($data['_sched_date'], $data['_sched_slots'], $data['_sched_ctx']);
    $db->setState($userId, 'promo_sched_date', $data);

    if ($key === 'monthly' || $key === 'yearly') {
        $data['_sched_slots'] = [];
        $db->setState($userId, 'promo_sched_rec', $data);
        promoWeekdayGrid($userId, 1, $data);
    } else {
        promoDateGrid($userId, $data);
    }
}

// ---- Single date grid (one-time & Business of the Week) ----

// Inline month-calendar date picker (like a booking app), replacing the old
// flat list of dates. $ym (optional 'YYYYMM') selects the month to render, for
// the ◀/▶ navigation; it defaults to the month containing today. Only dates
// inside the bookable window [today .. today+N] are tappable; everything else is
// shown greyed as a no-op, and the arrows only appear when there's a reachable
// month in that direction.
function promoDateGrid($userId, $data, $ym = null, $editMsgId = null) {
    global $tg, $db;
    $key = $data['package_key'] ?? 'one_time';
    $tz = promoSchedTz();
    $today = new DateTime('now', $tz); $today->setTime(0, 0);

    $days = ($key === 'botw') ? 28 : 30;   // botw is bookable up to 4 weeks out
    $maxDate = (clone $today)->modify('+' . ($days - 1) . ' day');

    // Business of the Week owns a whole week exclusively, so a start day is only
    // bookable when none of its 7-day run is already held by another BOTW ad.
    // Pre-load the taken days once, then a start day is "free" if its span is clear.
    $botwFree = function (DateTime $d) { return true; };
    if ($key === 'botw' && $db) {
        $spanEnd = (clone $maxDate)->modify('+6 day');   // last start's run reaches here
        $taken = $db->botwOccupiedDays($today->format('Y-m-d'), $spanEnd->format('Y-m-d'),
            $data['id'] ?? null);
        $botwFree = function (DateTime $start) use ($taken) {
            for ($i = 0; $i < 7; $i++) {
                $day = (clone $start)->modify("+{$i} day")->format('Y-m-d');
                if (!empty($taken[$day])) return false;
            }
            return true;
        };
    }

    // Month being shown. Clamp so we never render a month with no bookable day.
    $month = null;
    if ($ym && preg_match('/^(\d{4})(\d{2})$/', $ym, $m)) {
        $month = DateTime::createFromFormat('Y-m-d', "{$m[1]}-{$m[2]}-01", $tz);
    }
    if (!($month instanceof DateTime)) { $month = (clone $today); }
    $month->setTime(0, 0); $month->modify('first day of this month');

    $firstOfMonth = (clone $month);
    $daysInMonth  = (int) $month->format('t');
    $lastOfMonth  = (clone $month)->modify('last day of this month');

    // Leading blanks so day 1 lands under the right weekday (Mon-first grid).
    $leadBlanks = ((int) $firstOfMonth->format('N')) - 1;   // Mon=1..Sun=7

    $isTappable = function (DateTime $d) use ($today, $maxDate) {
        return $d >= $today && $d <= $maxDate;
    };

    $rows = [];
    // Header: ◀  Month Year  ▶
    $prevMonthLast = (clone $firstOfMonth)->modify('-1 day');            // last day of prev month
    $nextMonthFirst = (clone $lastOfMonth)->modify('+1 day');           // first day of next month
    $hasPrev = ($prevMonthLast >= $today);                              // prev month still has bookable days
    $hasNext = ($nextMonthFirst <= $maxDate);                           // next month has bookable days
    $header = [];
    $header[] = $hasPrev
        ? ['text' => "\xE2\x97\x80", 'callback_data' => 'pcaln_' . $prevMonthLast->format('Ym')]
        : ['text' => "\xC2\xA0", 'callback_data' => 'pnop'];
    $header[] = ['text' => $month->format('F Y'), 'callback_data' => 'pnop'];
    $header[] = $hasNext
        ? ['text' => "\xE2\x96\xB6", 'callback_data' => 'pcaln_' . $nextMonthFirst->format('Ym')]
        : ['text' => "\xC2\xA0", 'callback_data' => 'pnop'];
    $rows[] = $header;

    // Weekday header (Mon-first).
    $rows[] = array_map(function ($w) { return ['text' => $w, 'callback_data' => 'pnop']; },
        ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']);

    // Day cells.
    $row = [];
    for ($i = 0; $i < $leadBlanks; $i++) { $row[] = ['text' => "\xC2\xA0", 'callback_data' => 'pnop']; }
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $d = DateTime::createFromFormat('Y-m-d', $month->format('Y-m-') . sprintf('%02d', $day), $tz);
        $d->setTime(0, 0);
        if ($isTappable($d) && $botwFree($d)) {
            $row[] = ['text' => (string) $day, 'callback_data' => 'pschd_' . $d->format('Ymd')];
        } elseif ($isTappable($d) && $key === 'botw') {
            // In-window but that week is already booked by another Business of the Week.
            $row[] = ['text' => "\xF0\x9F\x9A\xAB", 'callback_data' => 'pnop'];   // no-entry = week taken
        } else {
            $row[] = ['text' => "\xC2\xB7", 'callback_data' => 'pnop'];   // middot = outside window
        }
        if (count($row) === 7) { $rows[] = $row; $row = []; }
    }
    if (!empty($row)) {
        while (count($row) < 7) { $row[] = ['text' => "\xC2\xA0", 'callback_data' => 'pnop']; }
        $rows[] = $row;
    }

    $rows[] = [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'promo_preview']];

    $note = ($key === 'botw')
        ? "Pick the day your Business of the Week feature should start. We'll post it once a day for 7 consecutive days from then, each pinned to the top on its day.\n\n\xF0\x9F\x9A\xAB = that week is already taken by another business. \xC2\xB7 = outside the booking window."
        : "Tap the date you'd like your post to go out:";
    $body = "\xF0\x9F\x93\x85 <b>Choose a date</b>\n\n" . $note;

    // When navigating months, edit the existing calendar message in place so the
    // user stays on the SAME calendar (no repeated "Choose a date" messages).
    // On first open ($editMsgId is null) we post a fresh calendar.
    if ($editMsgId) {
        $tg->editMessageText($userId, $editMsgId, $body, ['inline_keyboard' => $rows]);
    } else {
        $tg->sendInlineButtons($userId, $body, $rows);
    }
}

// ◀/▶ month navigation on the calendar. Re-renders the picker for the chosen
// month, reusing the current scheduling session's data (so the bookable window
// for the package is preserved). $editMsgId, when given, edits the calendar in
// place instead of posting a new one.
function promoCalendarNav($userId, $ym, $state, $editMsgId = null) {
    promoDateGrid($userId, $state['data'] ?? [], $ym, $editMsgId);
}

function promoSchedPickDate($userId, $ymd, $state) {
    global $db, $tg;
    $data = $state['data'];
    $d = DateTime::createFromFormat('Ymd', $ymd, promoSchedTz());
    if (!$d) { promoDateGrid($userId, $data); return; }
    $d->setTime(0, 0);
    // Re-check availability for Business of the Week in case the week was booked
    // by someone else between the calendar being shown and this tap.
    if (($data['package_key'] ?? '') === 'botw' && $db) {
        $spanEnd = (clone $d)->modify('+6 day');
        $taken = $db->botwOccupiedDays($d->format('Y-m-d'), $spanEnd->format('Y-m-d'), $data['id'] ?? null);
        if (!empty($taken)) {
            $tg->sendMessage($userId, "\xE2\x9A\xA0\xEF\xB8\x8F Sorry, that week was just taken by another business. Please pick a different start date.");
            promoDateGrid($userId, $data);
            return;
        }
    }
    $data['_sched_date'] = $d->format('Y-m-d');
    $data['_sched_ctx'] = 'single';
    $db->setState($userId, 'promo_sched_time', $data);
    promoTimeGrid($userId, 'pscht_', "\xF0\x9F\x95\x92 <b>Choose a time</b> for <b>" . $d->format('D, M j') . "</b>:", 'promo_sched_start');
}

// ---- Time grid (shared by single & recurring; prefix picks the callback) ----

function promoTimeGrid($userId, $cbPrefix, $header, $backCb) {
    global $tg;

    // Popular one-tap suggestions (the 3 configured slots)
    $popular = [];
    foreach (promoPopularTimes() as $hm) {
        $popular[] = ['text' => "\xE2\xAD\x90 " . promoTimeLabel($hm), 'callback_data' => $cbPrefix . str_replace(':', '', $hm)];
    }
    $rows = [$popular];

    // Hourly grid 8 AM - 9 PM
    $row = [];
    for ($h = 8; $h <= 21; $h++) {
        $hm = sprintf('%02d:00', $h);
        $row[] = ['text' => promoTimeLabel($hm), 'callback_data' => $cbPrefix . str_replace(':', '', $hm)];
        if (count($row) === 3) { $rows[] = $row; $row = []; }
    }
    if (!empty($row)) $rows[] = $row;

    $rows[] = [['text' => "\xE2\x8C\xA8\xEF\xB8\x8F Type another time", 'callback_data' => $cbPrefix . 'custom']];
    $rows[] = [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => $backCb]];

    $tg->sendInlineButtons($userId, $header . "\n\n(\xE2\xAD\x90 = popular times)", $rows);
}

function promoSchedPickTimeSingle($userId, $hhmm, $state) {
    global $db, $tg;
    $data = $state['data'];

    if ($hhmm === 'custom') {
        $data['_sched_ctx'] = 'single';
        $db->setState($userId, 'promo_sched_time_text', $data);
        $tg->sendInlineButtons($userId,
            "\xE2\x8C\xA8\xEF\xB8\x8F Type the time you want (e.g. <b>9:00 AM</b>, <b>2:30 PM</b>, or <b>14:30</b>):",
            [[['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'pschd_' . str_replace('-', '', $data['_sched_date'] ?? '')]]]
        );
        return;
    }

    $hm = promoNormPickTime($hhmm);
    if ($hm === null) { promoTimeGrid($userId, 'pscht_', "Please pick a valid time:", 'promo_sched_start'); return; }
    promoFinalizeSingle($userId, $data, $hm);
}

function promoFinalizeSingle($userId, $data, $hm) {
    $date = $data['_sched_date'] ?? '';
    $data['schedule'] = ['mode' => 'single', 'date' => $date, 'time' => $hm];
    $data['start_date'] = $date;
    // Business of the Week runs for 7 consecutive days, so its plan window ends
    // 6 days after the start; a one-time post starts and ends the same day.
    if (($data['package_key'] ?? '') === 'botw' && $date !== '') {
        $end = DateTime::createFromFormat('Y-m-d', $date, promoSchedTz());
        $data['end_date'] = ($end instanceof DateTime) ? $end->modify('+6 day')->format('Y-m-d') : $date;
    } else {
        $data['end_date'] = $date;
    }
    unset($data['_sched_date'], $data['_sched_ctx']);
    promoSchedConfirm($userId, $data);
}

// ---- Recurring: two weekday + time slots (monthly & yearly) ----

function promoWeekdayNames() {
    return [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
}

function promoWeekdayGrid($userId, $slotNum, $data) {
    global $tg;
    $names = promoWeekdayNames();
    $rows = [];
    $row = [];
    foreach ($names as $dow => $name) {
        $row[] = ['text' => $name, 'callback_data' => "pschw_{$slotNum}_{$dow}"];
        if (count($row) === 2) { $rows[] = $row; $row = []; }
    }
    if (!empty($row)) $rows[] = $row;
    $backCb = ($slotNum === 1) ? 'promo_preview' : 'promo_sched_start';
    $rows[] = [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => $backCb]];

    $ord = ($slotNum === 1) ? 'first' : 'second';
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x93\x86 <b>Weekly posting day " . $slotNum . " of 2</b>\n\n" .
        "Pick the <b>{$ord}</b> day of the week you'd like to post on:",
        $rows
    );
}

function promoSchedPickWeekday($userId, $slotNum, $dow, $state) {
    global $db;
    $data = $state['data'];
    $dow = (int) $dow;
    if ($dow < 1 || $dow > 7) { promoWeekdayGrid($userId, $slotNum, $data); return; }
    if (!isset($data['_sched_slots'])) $data['_sched_slots'] = [];
    $data['_sched_slots'][$slotNum] = ['dow' => $dow];
    $data['_sched_ctx'] = 'rec' . $slotNum;
    $db->setState($userId, 'promo_sched_rec', $data);
    $names = promoWeekdayNames();
    promoTimeGrid($userId, "pschrt_{$slotNum}_",
        "\xF0\x9F\x95\x92 <b>Time on {$names[$dow]}</b>\n\nWhat time should the post go out each " . $names[$dow] . "?",
        'promo_sched_start');
}

function promoSchedPickTimeRecurring($userId, $slotNum, $hhmm, $state) {
    global $db, $tg;
    $data = $state['data'];
    $slotNum = (int) $slotNum;

    if ($hhmm === 'custom') {
        $data['_sched_ctx'] = 'rec' . $slotNum;
        $db->setState($userId, 'promo_sched_time_text', $data);
        $tg->sendInlineButtons($userId,
            "\xE2\x8C\xA8\xEF\xB8\x8F Type the time you want (e.g. <b>9:00 AM</b>, <b>2:30 PM</b>, or <b>14:30</b>):",
            [[['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => "pschw_{$slotNum}_" . ($data['_sched_slots'][$slotNum]['dow'] ?? 1)]]]
        );
        return;
    }

    $hm = promoNormPickTime($hhmm);
    if ($hm === null) { promoWeekdayGrid($userId, $slotNum, $data); return; }
    $data['_sched_slots'][$slotNum]['time'] = $hm;
    $db->setState($userId, 'promo_sched_rec', $data);

    if ($slotNum === 1) {
        // move on to the second weekly slot
        promoWeekdayGrid($userId, 2, $data);
    } else {
        promoFinalizeRecurring($userId, $data);
    }
}

function promoFinalizeRecurring($userId, $data) {
    $slots = [];
    foreach ([1, 2] as $n) {
        if (!empty($data['_sched_slots'][$n]['dow']) && !empty($data['_sched_slots'][$n]['time'])) {
            $slots[] = ['dow' => (int) $data['_sched_slots'][$n]['dow'], 'time' => $data['_sched_slots'][$n]['time']];
        }
    }
    $data['schedule'] = ['mode' => 'recurring', 'slots' => $slots];

    $tz = promoSchedTz();
    $today = new DateTime('now', $tz); $today->setTime(0, 0);
    $pkg = promoPackage($data['package_key'] ?? '');
    $dur = (int) ($pkg['duration_days'] ?? 30);
    $data['start_date'] = $today->format('Y-m-d');
    $data['end_date'] = (clone $today)->modify("+{$dur} day")->format('Y-m-d');

    unset($data['_sched_slots'], $data['_sched_ctx']);
    promoSchedConfirm($userId, $data);
}

// Accept a raw HHMM tag from a button ("0830") or a normalized time.
function promoNormPickTime($raw) {
    $raw = trim((string) $raw);
    if (preg_match('/^(\d{2})(\d{2})$/', $raw, $m)) {
        $h = (int) $m[1]; $min = (int) $m[2];
        if ($h <= 23 && $min <= 59) return sprintf('%02d:%02d', $h, $min);
    }
    return null;
}

// Parse a free-typed time like "9", "9am", "2:30 pm", "14:30".
function promoParseTypedTime($text) {
    $t = strtolower(trim($text));
    $t = str_replace('.', ':', $t);
    if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/', $t, $m)) {
        $h = (int) $m[1];
        $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $ap = $m[3] ?? '';
        if ($ap === 'am') { if ($h === 12) $h = 0; }
        elseif ($ap === 'pm') { if ($h !== 12) $h += 12; }
        if ($h <= 23 && $min <= 59) return sprintf('%02d:%02d', $h, $min);
    }
    return null;
}

// ---- Confirmation before submit ----

function promoScheduleSummary($schedule) {
    if (($schedule['mode'] ?? '') === 'single') {
        $tz = promoSchedTz();
        $d = DateTime::createFromFormat('Y-m-d', $schedule['date'], $tz);
        $ds = $d ? $d->format('l, M j, Y') : $schedule['date'];
        return "\xF0\x9F\x93\x85 " . $ds . " at " . promoTimeLabel($schedule['time']);
    }
    if (($schedule['mode'] ?? '') === 'recurring') {
        $names = promoWeekdayNames();
        $parts = [];
        foreach ($schedule['slots'] as $s) {
            $parts[] = ($names[$s['dow']] ?? '?') . "s at " . promoTimeLabel($s['time']);
        }
        return "\xF0\x9F\x94\x81 Every " . implode(" and ", $parts);
    }
    return '';
}

function promoSchedConfirm($userId, $data) {
    global $tg, $db;
    $db->setState($userId, 'promo_sched_confirm', $data);

    $pkg = promoPackage($data['package_key'] ?? '');
    $total = (int) ($data['posts_total'] ?? ($pkg['posts_total'] ?? 1));
    $isResched = !empty($data['resched_id']);

    $extra = '';
    if (($data['package_key'] ?? '') === 'botw') {
        $extra = "\n\nWe'll post your feature once a day for <b>7 consecutive days</b> starting then - each post pinned to the top of the group on its day.";
    } elseif (($data['schedule']['mode'] ?? '') === 'recurring') {
        $extra = "\n\nWe'll auto-schedule up to <b>{$total}</b> posts on these days" .
            (($data['package_key'] ?? '') === 'yearly'
                ? ", opening one month at a time (the next month unlocks automatically as it gets close)."
                : ", across the next 30 days.");
    }

    $saveBtn = $isResched
        ? ['text' => "\xE2\x9C\x85 Save Schedule", 'callback_data' => 'promo_resched_save']
        : ['text' => "\xE2\x9C\x85 Submit for Review", 'callback_data' => 'promo_submit'];

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x97\x93 <b>Confirm your schedule</b>\n\n" .
        promoScheduleSummary($data['schedule']) . $extra . "\n\n" .
        ($isResched ? "Save this new schedule?" : "Ready to submit for review?"),
        [
            [$saveBtn],
            [
                ['text' => "\xF0\x9F\x94\x84 Change", 'callback_data' => 'promo_sched_start'],
                ['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'promo_cancel'],
            ],
        ]
    );
}

// Reschedule save (from the dashboard "Select Another Slot"): update the
// existing promotion's schedule and clear future bookings so the engine re-books.
function promoReschedSave($userId, $state) {
    global $tg, $db, $config;
    $data = $state['data'];
    $promoId = (int) ($data['resched_id'] ?? 0);
    if (!$promoId) { $db->setState($userId, 'idle', []); promoShowDashboard($userId); return; }

    $db->updatePromotion($promoId, [
        'schedule'   => $data['schedule'] ?? [],
        'start_date' => $data['start_date'] ?? '',
        'end_date'   => $data['end_date'] ?? '',
    ]);
    // Drop the old future bookings, then RE-BOOK IMMEDIATELY from the new
    // schedule so the Upcoming Schedule (and any pinned post) reflect the change
    // right away, instead of waiting for the next scheduler cron tick. Booking
    // never touches Telegram, so it's safe to run here. Re-booking respects the
    // remaining post allowance (already-posted slots are counted), so no
    // double-booking.
    $db->clearFutureBookings($promoId);
    promoRebookNow($promoId);
    $db->setState($userId, 'idle', []);

    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x85 <b>Schedule updated!</b>\n\n" . promoScheduleSummary($data['schedule']) .
        "\n\nYour upcoming posts have been rescheduled - open View Schedule to see the new times.",
        [[['text' => "\xF0\x9F\x93\x8A My Dashboard", 'callback_data' => 'promo_dashboard']]]
    );
}

// Book (or re-book) a promotion's future posts from its saved schedule right
// now, without waiting for the scheduler cron. Used after a dashboard
// reschedule. Never posts to Telegram - only computes and stores the bookings.
function promoRebookNow($promoId) {
    global $tg, $db, $config;
    try {
        require_once __DIR__ . '/scheduler.php';
        $sched = new HL_Scheduler($db->path(), $tg, $config);
        $sched->bookPromoById((int) $promoId);
    } catch (Throwable $e) {
        error_log('promoRebookNow failed for promo ' . $promoId . ': ' . $e->getMessage());
    }
}

// ============================================================
// USER DASHBOARD
// ============================================================

// Today's date (Y-m-d) in the schedule timezone - the reference point for
// "future" posts and plan expiry across the whole dashboard.
function promoToday() {
    return (new DateTime('now', promoSchedTz()))->format('Y-m-d');
}

// A plan is expired once its end_date is in the past (schedule timezone).
function promoIsExpired($promo, $today = null) {
    $end = substr($promo['end_date'] ?? '', 0, 10);
    if ($end === '') return false;              // open-ended / single-post plans never "expire" by date
    $today = $today ?: promoToday();
    return $end < $today;
}

// The user's ONE current active plan for the dashboard.
// Active = approved AND not expired. If there's no running plan we fall back to
// a plan still awaiting review (so the user can see its pending status), but we
// NEVER surface cancelled, rejected, expired or draft plans here.
function promoActivePromotion($userId) {
    global $db;
    $all = $db->getUserPromotions($userId);
    $today = promoToday();

    // Prefer a live, approved, not-yet-expired plan (most recent first - the
    // list is already ordered created_at DESC).
    foreach ($all as $p) {
        if (($p['status'] ?? '') === 'approved' && !promoIsExpired($p, $today)) return $p;
    }
    // Otherwise a plan still pending review is worth showing.
    foreach ($all as $p) {
        if (($p['status'] ?? '') === 'pending_review') return $p;
    }
    // Nothing active - deliberately do NOT fall back to cancelled/rejected/
    // expired/draft records.
    return null;
}

// Map a stored promotion row into the in-memory "data" array the ad-builder /
// edit flow works with. Tags it with _edit_promo_id so saves update this record
// instead of creating a brand-new promotion.
function promoLoadPromoIntoData($promo) {
    $images = [];
    if (!empty($promo['images'])) {
        $decoded = json_decode($promo['images'], true);
        if (is_array($decoded)) $images = $decoded;
    }
    $videos = [];
    if (!empty($promo['videos'])) {
        $decoded = json_decode($promo['videos'], true);
        if (is_array($decoded)) $videos = $decoded;
    }
    return [
        '_edit_promo_id'    => (int) $promo['id'],
        'package_key'       => $promo['package_key'] ?? '',
        'price'             => $promo['price'] ?? 0,
        'payment_method'    => $promo['payment_method'] ?? '',
        'payment_status'    => $promo['payment_status'] ?? '',
        'receipt'           => $promo['receipt'] ?? '',
        'business_name'     => $promo['business_name'] ?? '',
        'business_category' => $promo['business_category'] ?? '',
        'description'       => $promo['description'] ?? '',
        'phone'             => $promo['phone'] ?? '',
        'website'           => $promo['website'] ?? '',
        'social'            => $promo['social'] ?? '',
        'address'           => $promo['address'] ?? '',
        'hours'             => $promo['hours'] ?? '',
        'logo'              => $promo['logo'] ?? '',
        'images'            => $images,
        'videos'            => $videos,
        'cta'               => $promo['cta'] ?? '',
        'posts_total'       => (int) ($promo['posts_total'] ?? 0),
    ];
}

// When an edit is happening on an already-saved ad, persist the single changed
// field straight to the DB so it survives even if the user walks away.
function promoPersistEditField($data, $field) {
    global $db;
    $id = (int) ($data['_edit_promo_id'] ?? 0);
    if ($id <= 0) return;
    if (!in_array($field, promoFormOrder(), true)) return;
    $db->updatePromotion($id, [$field => $data[$field] ?? '']);
}

function promoStatusLabel($status) {
    switch ($status) {
        case 'approved': return "\xF0\x9F\x9F\xA2 Active";
        case 'pending_review': return "\xF0\x9F\x95\x92 Pending Review";
        case 'rejected': return "\xE2\x9D\x8C Not Approved";
        case 'canceled': return "\xE2\x9A\xAA Cancelled";
        default: return ucfirst($status ?: 'Draft');
    }
}

function promoShowDashboard($userId) {
    global $tg, $db;
    $db->setState($userId, 'idle', []);

    $promo = promoActivePromotion($userId);
    if (!$promo) {
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\x8A <b>Your Promotion Dashboard</b>\n\n" .
            "You don't have any promotions yet. Tap below to promote your business in the HabeshaList group.",
            [
                [['text' => "\xF0\x9F\x93\xA2 Promote My Business", 'callback_data' => 'promote']],
                [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']],
            ]
        );
        return;
    }

    $pid = (int) $promo['id'];
    $pkg = promoPackage($promo['package_key'] ?? '');
    $planName = $pkg['name'] ?? 'Promotion';
    $total = (int) ($promo['posts_total'] ?: ($pkg['posts_total'] ?? 1));
    // "Used" counts posts already committed (scheduled + published), so a booked
    // post immediately reduces the remaining allowance. Fall back to posts_used
    // for any legacy plan that has no scheduled_posts rows.
    $used = max($db->countCommittedPosts($pid), (int) ($promo['posts_used'] ?? 0));
    $remaining = max(0, $total - $used);
    $tz = promoSchedTz();

    $lines = [];
    $lines[] = "\xF0\x9F\x93\x8A <b>Your Promotion Dashboard</b>";
    $lines[] = '';
    $lines[] = "\xF0\x9F\x93\xA6 Plan: <b>{$planName}</b>";
    $lines[] = "Status: <b>" . promoStatusLabel($promo['status'] ?? '') . "</b>";
    if (!empty($promo['start_date'])) {
        $sd = DateTime::createFromFormat('Y-m-d', substr($promo['start_date'], 0, 10), $tz);
        $ed = !empty($promo['end_date']) ? DateTime::createFromFormat('Y-m-d', substr($promo['end_date'], 0, 10), $tz) : null;
        $line = "\xF0\x9F\x93\x85 " . ($sd ? $sd->format('M j, Y') : $promo['start_date']);
        if ($ed) $line .= " \xE2\x86\x92 " . $ed->format('M j, Y');
        $lines[] = $line;
    }
    $next = $db->getNextPost($pid, promoToday());

    // A plan is COMPLETE once its whole allowance has been used up and there are
    // no more upcoming posts (e.g. a one-time post that has now gone live). Show
    // a clean "Completed" card with only a Main Menu button.
    $isComplete = ($promo['status'] ?? '') === 'approved' && $total > 0 && $used >= $total && !$next;
    if ($isComplete) {
        $lines[3] = "Status: <b>\xE2\x9C\x85 Completed</b>";  // replace the raw status line
        $lines[] = '';
        $lines[] = "All scheduled posts for this plan have been used.";
        $tg->sendInlineButtons($userId, implode("\n", $lines),
            [[['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
        return;
    }

    $lines[] = '';
    $lines[] = "\xF0\x9F\x93\x9D Used posts: <b>{$used} / {$total}</b>";

    if ($next) {
        $nd = DateTime::createFromFormat('Y-m-d', $next['post_date'], $tz);
        $nt = !empty($next['post_time']) ? $next['post_time'] : '';
        $lines[] = "\xE2\x8F\xAD\xEF\xB8\x8F Next post: <b>" . ($nd ? $nd->format('M j, Y') : $next['post_date']) .
            ($nt ? " at " . promoTimeLabel($nt) : '') . "</b>";
    } else {
        $lines[] = "\xE2\x8F\xAD\xEF\xB8\x8F Next post: <b>-</b>";
    }

    $pin = $db->getActivePin($pid);
    if ($pin) {
        $left = strtotime($pin['pin_until'] . ' UTC') - time();
        $hrs = max(1, (int) ceil($left / 3600));
        $lines[] = "\xF0\x9F\x93\x8C Pinned message: <b>Active ({$hrs}h left)</b>";
    }

    $rows = [];
    $rows[] = [
        ['text' => "\xF0\x9F\x93\x86 View Schedule", 'callback_data' => "dash_sched_{$pid}"],
        ['text' => "\xF0\x9F\x93\x91 My Ads", 'callback_data' => 'dash_ads'],
    ];
    $rows[] = [
        ['text' => "\xF0\x9F\x92\xB3 Payment", 'callback_data' => 'dash_pay'],
        ['text' => "\xE2\x9C\x8F\xEF\xB8\x8F Edit Ad", 'callback_data' => "dash_edit_{$pid}"],
    ];
    if (($promo['status'] ?? '') === 'approved') {
        $rows[] = [
            ['text' => "\xF0\x9F\x93\x85 Select Another Slot", 'callback_data' => "dash_slot_{$pid}"],
            ['text' => "\xF0\x9F\x9B\x91 Cancel Plan", 'callback_data' => "dash_cancel_{$pid}"],
        ];
    }
    $rows[] = [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']];

    $tg->sendInlineButtons($userId, implode("\n", $lines), $rows);
}

function promoDashViewSchedule($userId, $promoId) {
    global $tg, $db;
    $promo = $db->getPromotion($promoId);
    if (!$promo || $promo['telegram_id'] != $userId) { promoShowDashboard($userId); return; }
    $tz = promoSchedTz();
    $rows = $db->getUpcomingPosts($promoId, 20, promoToday());

    $txt = "\xF0\x9F\x93\x86 <b>Upcoming Schedule - " . ($promo['business_name'] ?: 'Your promotion') . "</b>\n\n";
    if (empty($rows)) {
        $txt .= "No upcoming posts scheduled right now.";
        if (($promo['status'] ?? '') === 'pending_review') $txt .= "\n\nYour posts will be scheduled as soon as your promotion is approved.";
    } else {
        foreach ($rows as $r) {
            $d = DateTime::createFromFormat('Y-m-d', $r['post_date'], $tz);
            $t = !empty($r['post_time']) ? $r['post_time'] : '';
            $pinTag = ((int) $r['pin'] === 1) ? "  \xF0\x9F\x93\x8C" : '';
            $txt .= "\xE2\x80\xA2 " . ($d ? $d->format('D, M j') : $r['post_date']) . ($t ? " - " . promoTimeLabel($t) : '') . $pinTag . "\n";
        }
        $txt .= "\n\xF0\x9F\x93\x8C = pinned post";
    }

    $tg->sendInlineButtons($userId, $txt, [[['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Dashboard", 'callback_data' => 'promo_dashboard']]]);
}

function promoDashMyAds($userId) {
    global $tg, $db;
    // Only ever show the ad for the current active plan - never expired,
    // cancelled, rejected or draft records.
    $promo = promoActivePromotion($userId);
    $txt = "\xF0\x9F\x93\x91 <b>My Ad</b>\n\n";
    if (!$promo) {
        $txt .= "You don't have an active promotion right now.";
    } else {
        $pkg = promoPackage($promo['package_key'] ?? '');
        // Use the same committed-posts count as the dashboard so the two views
        // always agree (e.g. a scheduled one-time post reads 1/1, not 0/1).
        $usedAds = max($db->countCommittedPosts((int) $promo['id']), (int) ($promo['posts_used'] ?? 0));
        $totalAds = (int) ($promo['posts_total'] ?? 0);
        $txt .= "\xF0\x9F\x8F\xA2 <b>" . ($promo['business_name'] ?: 'Untitled') . "</b>\n";
        $txt .= "   " . ($pkg['name'] ?? $promo['package_key']) . " - " . promoStatusLabel($promo['status'] ?? '') .
            " ({$usedAds}/{$totalAds} posts)\n";
        if (!empty($promo['business_category'])) $txt .= "   " . $promo['business_category'] . "\n";
    }
    $rows = [];
    if ($promo) $rows[] = [['text' => "\xE2\x9C\x8F\xEF\xB8\x8F Edit Ad", 'callback_data' => "dash_edit_" . (int) $promo['id']]];
    $rows[] = [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Dashboard", 'callback_data' => 'promo_dashboard']];
    $tg->sendInlineButtons($userId, $txt, $rows);
}

function promoDashPayments($userId) {
    global $tg, $db;
    // Show only the most recent payment for the current active plan.
    $promo = promoActivePromotion($userId);
    $txt = "\xF0\x9F\x92\xB3 <b>Payment</b>\n\n";
    if (!$promo || (empty($promo['receipt']) && empty($promo['payment_method']) && empty($promo['price']))) {
        $txt .= "No payment on record for your current plan yet.";
    } else {
        $pkg = promoPackage($promo['package_key'] ?? '');
        $tz = promoSchedTz();
        $paidAt = '';
        if (!empty($promo['created_at'])) {
            // created_at is stored UTC; show it in the schedule timezone.
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', substr($promo['created_at'], 0, 19), new DateTimeZone('UTC'));
            if ($dt) { $dt->setTimezone($tz); $paidAt = $dt->format('M j, Y'); }
        }
        $txt .= "\xF0\x9F\x93\xA6 Plan: <b>" . ($pkg['name'] ?? $promo['package_key']) . "</b>\n";
        $txt .= "\xF0\x9F\x92\xB5 Amount: <b>" . promoFmtPrice($promo['price'] ?? 0) . "</b>\n";
        if ($paidAt !== '') $txt .= "\xF0\x9F\x93\x85 Date: <b>{$paidAt}</b>\n";
        $txt .= "\xF0\x9F\x92\xB3 Method: <b>" . ($promo['payment_method'] ? strtoupper($promo['payment_method']) : 'n/a') . "</b>\n";
        $txt .= "\xF0\x9F\x93\x8C Status: <b>" . ($promo['payment_status'] ?: 'pending') . "</b>\n";
        if (!empty($promo['receipt'])) $txt .= "\xF0\x9F\xA7\xBE Receipt: <b>" . $promo['receipt'] . "</b>\n";
    }
    $tg->sendInlineButtons($userId, $txt, [[['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Dashboard", 'callback_data' => 'promo_dashboard']]]);
}

// Entry from the dashboard "Edit Ad" button: load the SAVED ad back into the
// edit flow so its fields are pre-filled, and tag it so each change is written
// straight back to this promotion (no duplicate record).
function promoDashEditAd($userId, $promoId) {
    global $tg, $db;
    $promo = $db->getPromotion($promoId);
    if (!$promo || $promo['telegram_id'] != $userId) { promoShowDashboard($userId); return; }
    if (in_array(($promo['status'] ?? ''), ['canceled', 'rejected'], true)) {
        $tg->sendInlineButtons($userId,
            "This plan is no longer active, so it can't be edited.",
            [[['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Dashboard", 'callback_data' => 'promo_dashboard']]]);
        return;
    }
    $data = promoLoadPromoIntoData($promo);
    $db->setState($userId, 'promo_review', $data);
    promoEditMenu($userId, ['state' => 'promo_review', 'data' => $data]);
}

function promoDashSelectSlot($userId, $promoId) {
    global $tg, $db;
    $promo = $db->getPromotion($promoId);
    if (!$promo || $promo['telegram_id'] != $userId) { promoShowDashboard($userId); return; }

    // Load the promotion into a scheduling session flagged as a reschedule.
    $data = [
        'resched_id'  => (int) $promoId,
        'package_key' => $promo['package_key'],
        'posts_total' => (int) $promo['posts_total'],
        'price'       => $promo['price'],
    ];
    $db->setState($userId, 'promo_sched_date', $data);
    promoSchedStart($userId, ['data' => $data]);
}

function promoDashCancelConfirm($userId, $promoId) {
    global $tg, $db;
    $promo = $db->getPromotion($promoId);
    if (!$promo || $promo['telegram_id'] != $userId) { promoShowDashboard($userId); return; }
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x9B\x91 <b>Cancel this plan?</b>\n\n" .
        "This stops all future scheduled posts for <b>" . ($promo['business_name'] ?: 'your promotion') . "</b>. " .
        "Posts already published in the group stay up.\n\n" .
        "This can't be undone. Are you sure?",
        [
            [['text' => "\xE2\x9C\x85 Yes, cancel plan", 'callback_data' => "dash_cancelyes_{$promoId}"]],
            [['text' => "\xE2\xAC\x85\xEF\xB8\x8F No, keep it", 'callback_data' => 'promo_dashboard']],
        ]
    );
}

function promoDashDoCancel($userId, $promoId) {
    global $tg, $db;
    $promo = $db->getPromotion($promoId);
    if (!$promo || $promo['telegram_id'] != $userId) { promoShowDashboard($userId); return; }
    $db->cancelPromotionSchedule($promoId);
    $tg->sendInlineButtons($userId,
        "\xE2\x9A\xAA Your plan for <b>" . ($promo['business_name'] ?: 'your promotion') . "</b> has been cancelled. " .
        "No further posts will go out.\n\nIf you have any questions about a refund, please contact support.",
        [[['text' => "\xF0\x9F\x93\x8A My Dashboard", 'callback_data' => 'promo_dashboard']]]
    );
}
