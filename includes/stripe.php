<?php
/**
 * stripe.php - minimal, dependency-free Stripe Checkout helper.
 *
 * WHY PULL-BASED (no webhook)
 * This host's firewall blocks/inspects inbound POSTs (the same reason the bot
 * runs in polling mode). Rather than rely on a Stripe webhook reaching the
 * server, we create a Checkout Session and later VERIFY it by calling Stripe
 * OUTBOUND when the user taps "I've paid". Outbound HTTPS always works here, so
 * this is the reliable design on Bluehost shared hosting.
 *
 * Uses the Stripe REST API directly over curl - no SDK/composer needed, in
 * keeping with the rest of this codebase.
 */

// Create a Checkout Session for a one-off payment.
// Returns ['id' => 'cs_...', 'url' => 'https://checkout.stripe.com/...'] or
// ['error' => 'message'] on failure.
function hl_stripe_create_session($key, $amountCents, $productName, $successUrl, $cancelUrl, $metadata = [], $currency = 'usd') {
    $params = [
        'mode'        => 'payment',
        'success_url' => $successUrl,
        'cancel_url'  => $cancelUrl,
        'line_items[0][quantity]'                              => 1,
        'line_items[0][price_data][currency]'                  => $currency,
        'line_items[0][price_data][unit_amount]'               => (int) $amountCents,
        'line_items[0][price_data][product_data][name]'        => $productName,
    ];
    foreach ($metadata as $k => $v) {
        $params['metadata[' . $k . ']'] = (string) $v;
    }

    $res = hl_stripe_request($key, 'POST', 'https://api.stripe.com/v1/checkout/sessions', $params);
    if (!is_array($res)) return ['error' => 'network'];
    if (!empty($res['error'])) return ['error' => $res['error']['message'] ?? 'stripe error'];
    if (empty($res['id']) || empty($res['url'])) return ['error' => 'unexpected response'];
    return ['id' => $res['id'], 'url' => $res['url']];
}

// Retrieve a Checkout Session to check its payment status.
// Returns the decoded session array, or null on failure.
function hl_stripe_get_session($key, $sessionId) {
    $res = hl_stripe_request($key, 'GET', 'https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId), []);
    if (!is_array($res) || !empty($res['error'])) return null;
    return $res;
}

// True if a retrieved session has been paid.
function hl_stripe_session_paid($session) {
    return is_array($session) && (($session['payment_status'] ?? '') === 'paid');
}

// Low-level Stripe API call. Returns decoded JSON array, or null on transport
// failure.
function hl_stripe_request($key, $verb, $url, $params) {
    // Test seam: let automated tests simulate Stripe responses without any
    // network call. Never set in production.
    if (isset($GLOBALS['__HL_STRIPE_STUB']) && is_callable($GLOBALS['__HL_STRIPE_STUB'])) {
        return call_user_func($GLOBALS['__HL_STRIPE_STUB'], $verb, $url, $params);
    }
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_USERPWD        => $key . ':',   // Stripe uses the secret key as basic-auth username
        CURLOPT_HTTPHEADER     => ['Stripe-Version: 2023-10-16'],
    ];
    if ($verb === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    curl_close($ch);
    if ($body === false) return null;
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}
