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
        $key = $db->getSetting('stripe_key', $config['stripe_key']);
        if (empty($key)) {
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
        // Stripe key present: link generation is wired in the payment milestone.
        $link = promoStripeLink($data['price'] ?? 0, $data, $userId);
        if ($link) {
            $data['payment_method'] = 'card';
            $db->setState($userId, 'promo_awaiting_payment_proof', $data);
            $tg->sendInlineButtons($userId,
                "\xF0\x9F\x92\xB3 Tap below to pay <b>{$price}</b> securely by card. " .
                "Once payment completes you'll be taken through the ad form.",
                [
                    [['text' => "Pay {$price} by Card", 'url' => $link]],
                    [['text' => "\xE2\x9C\x85 I've paid", 'callback_data' => 'promo_paid_manual']],
                    [['text' => "\xE2\x9D\x8C Cancel", 'callback_data' => 'promo_cancel']],
                ]
            );
            return;
        }
        // Fallback if link couldn't be created
        promoShowPayment($userId, $data);
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

// Placeholder for Stripe Checkout link creation - fully wired once the client
// provides their Stripe secret key (payment milestone). Returns null for now.
function promoStripeLink($amount, $data, $userId) {
    global $db, $config;
    $key = $db->getSetting('stripe_key', $config['stripe_key']);
    if (empty($key)) return null;
    // Intentionally not implemented until the live key + webhook are in place.
    return null;
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
    $db->setState($userId, 'promo_business_name', $data);
    promoPromptStep($userId, 'business_name', $data, false);
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
            $tg->sendInlineButtons($userId,
                $header . $current . "\xF0\x9F\x93\xB8 Send up to 5 <b>additional images</b> for your ad. Tap Done when finished." .
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
            $db->setState($userId, 'promo_review', $data);
            promoShowReview($userId, $data);
        } else {
            promoAdvance($userId, $data, 'logo');
        }
        return true;
    }

    if ($field === 'images') {
        $data['images'] = $data['images'] ?? [];
        $doneCb = $isEdit ? 'promo_images_done' : 'promo_images_done';

        if (count($data['images']) >= 5) {
            if (empty($data['_img_max_notified'])) {
                $data['_img_max_notified'] = true;
                $db->setState($userId, $st, $data);
                $tg->sendInlineButtons($userId,
                    "\xF0\x9F\x93\xB8 Maximum of 5 images reached. Tap Done to continue.",
                    [[['text' => "\xE2\x9C\x85 Done", 'callback_data' => $doneCb]]]
                );
            }
            return true;
        }

        $data['images'][] = $photo['file_id'];
        $count = count($data['images']);

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

// ---- Callback-driven field actions ----

function promoSelectCategory($userId, $idx, $state) {
    global $db, $config;
    $cat = $config['business_categories'][$idx] ?? null;
    if ($cat === null) return;
    $data = $state['data'];
    $data['business_category'] = $cat;
    if (promoIsEdit($state)) {
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
    $lines[] = "\xF0\x9F\x96\xBC Logo: {$logoTxt}   \xF0\x9F\x93\xB8 Images: {$imgCount}";

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

function promoEditMenu($userId, $state) {
    global $tg, $db;
    $db->setState($userId, 'promo_review', $state['data']);
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
    $data = $state['data'];
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

    // Header note so it's clear this block is the live preview.
    $tg->sendMessage($userId,
        "\xF0\x9F\x91\x81 <b>Preview</b> - this is exactly how your ad will look in the group:");

    if ($logo !== '') {
        $tg->sendPhoto($userId, $logo, $text);
    } elseif (!empty($images)) {
        $tg->sendMediaGroup($userId, $images);
        $tg->sendMessage($userId, $text);
    } else {
        $tg->sendMessage($userId, $text);
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

function promoDateGrid($userId, $data) {
    global $tg;
    $key = $data['package_key'] ?? 'one_time';
    $tz = promoSchedTz();
    $today = new DateTime('now', $tz);
    $today->setTime(0, 0);

    $days = ($key === 'botw') ? 28 : 30;   // botw is bookable up to 4 weeks out
    $rows = [];
    $row = [];
    for ($i = 0; $i < $days; $i++) {
        $d = (clone $today)->modify("+{$i} day");
        $label = $d->format('D M j');
        $row[] = ['text' => $label, 'callback_data' => 'pschd_' . $d->format('Ymd')];
        if (count($row) === 2) { $rows[] = $row; $row = []; }
    }
    if (!empty($row)) $rows[] = $row;
    $rows[] = [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'promo_preview']];

    $note = ($key === 'botw')
        ? "Pick the day your Business of the Week feature should start. It stays pinned for the full 7 days."
        : "Pick the date you'd like your post to go out:";
    $tg->sendInlineButtons($userId, "\xF0\x9F\x93\x85 <b>Choose a date</b>\n\n" . $note, $rows);
}

function promoSchedPickDate($userId, $ymd, $state) {
    global $db;
    $data = $state['data'];
    $d = DateTime::createFromFormat('Ymd', $ymd, promoSchedTz());
    if (!$d) { promoDateGrid($userId, $data); return; }
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
    $data['end_date'] = $date;
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
    if (($data['schedule']['mode'] ?? '') === 'recurring') {
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
    global $tg, $db;
    $data = $state['data'];
    $promoId = (int) ($data['resched_id'] ?? 0);
    if (!$promoId) { $db->setState($userId, 'idle', []); promoShowDashboard($userId); return; }

    $db->updatePromotion($promoId, [
        'schedule'   => $data['schedule'] ?? [],
        'start_date' => $data['start_date'] ?? '',
        'end_date'   => $data['end_date'] ?? '',
    ]);
    $db->clearFutureBookings($promoId);
    $db->setState($userId, 'idle', []);

    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x85 <b>Schedule updated!</b>\n\n" . promoScheduleSummary($data['schedule']) .
        "\n\nYour upcoming posts will follow the new schedule.",
        [[['text' => "\xF0\x9F\x93\x8A My Dashboard", 'callback_data' => 'promo_dashboard']]]
    );
}

// ============================================================
// USER DASHBOARD
// ============================================================

function promoActivePromotion($userId) {
    global $db;
    $all = $db->getUserPromotions($userId);
    // Prefer an approved (running) promotion, else the most recent non-cancelled.
    foreach ($all as $p) { if (($p['status'] ?? '') === 'approved') return $p; }
    foreach ($all as $p) { if (in_array(($p['status'] ?? ''), ['pending_review'], true)) return $p; }
    return $all[0] ?? null;
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
    $used = (int) ($promo['posts_used'] ?? 0);
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
    $lines[] = '';
    $lines[] = "\xF0\x9F\x93\x9D Remaining posts: <b>{$remaining} / {$total}</b>";

    $next = $db->getNextPost($pid);
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
    $rows[] = [['text' => "\xF0\x9F\x92\xB3 Payment History", 'callback_data' => 'dash_pay']];
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
    $rows = $db->getUpcomingPosts($promoId, 20);

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
    $all = $db->getUserPromotions($userId);
    $txt = "\xF0\x9F\x93\x91 <b>My Promotions</b>\n\n";
    if (empty($all)) {
        $txt .= "You haven't created any promotions yet.";
    } else {
        foreach ($all as $p) {
            $pkg = promoPackage($p['package_key'] ?? '');
            $txt .= "\xF0\x9F\x8F\xA2 <b>" . ($p['business_name'] ?: 'Untitled') . "</b>\n";
            $txt .= "   " . ($pkg['name'] ?? $p['package_key']) . " - " . promoStatusLabel($p['status'] ?? '') .
                " (" . (int) ($p['posts_used'] ?? 0) . "/" . (int) ($p['posts_total'] ?? 0) . " posts)\n";
        }
    }
    $tg->sendInlineButtons($userId, $txt, [[['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Dashboard", 'callback_data' => 'promo_dashboard']]]);
}

function promoDashPayments($userId) {
    global $tg, $db;
    $all = $db->getUserPromotions($userId);
    $txt = "\xF0\x9F\x92\xB3 <b>Payment History</b>\n\n";
    $any = false;
    foreach ($all as $p) {
        if (empty($p['receipt']) && empty($p['payment_method'])) continue;
        $any = true;
        $pkg = promoPackage($p['package_key'] ?? '');
        $txt .= "\xF0\x9F\xA7\xBE " . ($p['receipt'] ?: '-') . "\n";
        $txt .= "   " . ($pkg['name'] ?? $p['package_key']) . " - " . promoFmtPrice($p['price'] ?? 0) . "\n";
        $txt .= "   " . strtoupper($p['payment_method'] ?: 'n/a') . " - " . ($p['payment_status'] ?: 'pending') . "\n\n";
    }
    if (!$any) $txt .= "No payments on record yet.";
    $tg->sendInlineButtons($userId, $txt, [[['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back to Dashboard", 'callback_data' => 'promo_dashboard']]]);
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
