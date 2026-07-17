<?php
/**
 * secrets.php - manage sensitive API keys (Telegram bot token, Stripe key)
 * from the browser. Values are stored ENCRYPTED at rest in the settings table
 * (keys sec_*) using AES-256-GCM with the master key HL_APP_KEY from the bot's
 * .env. The bot (config.php) decrypts them at runtime and always falls back to
 * the plain .env value if anything is missing, so this can never brick the bot.
 *
 * A new bot token is verified against Telegram (getMe) BEFORE it is saved, so a
 * wrong token cannot be stored.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();

$SECRETS = [
    'bot_token' => [
        'label'    => 'Telegram bot token',
        'env'      => 'TELEGRAM_BOT_TOKEN',
        'setkey'   => 'sec_bot_token',
        'hint'     => 'From @BotFather. It is checked live with Telegram before saving.',
        'validate' => 'bot',
    ],
    'stripe_key' => [
        'label'    => 'Stripe secret key',
        'env'      => 'STRIPE_KEY',
        'setkey'   => 'sec_stripe_key',
        'hint'     => 'Use your SECRET key (starts with sk_live_ or sk_test_, or a restricted rk_ key) - NOT the Publishable pk_ key.',
        'validate' => 'stripe',
    ],
    'provider_token' => [
        'label'    => 'Telegram payment provider token',
        'env'      => 'PAYMENT_PROVIDER_TOKEN',
        'setkey'   => 'sec_payment_provider_token',
        'hint'     => 'Optional - only needed if you use Telegram native card payments.',
        'validate' => 'none',
    ],
];

$appKey = hl_app_key();

// Verify a bot token by calling Telegram getMe. Returns [resultArray|null, error|null].
function hl_verify_bot_token($token) {
    $url = 'https://api.telegram.org/bot' . str_replace(' ', '', $token) . '/getMe';
    $resp = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
    }
    if ($resp === false) {
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
    }
    if ($resp === false) {
        return [null, 'Could not reach Telegram to verify the token. Check the token and try again in a moment.'];
    }
    $j = json_decode($resp, true);
    if (is_array($j) && !empty($j['ok']) && !empty($j['result'])) {
        return [$j['result'], null];
    }
    $desc = is_array($j) && !empty($j['description']) ? $j['description'] : 'Telegram rejected this token.';
    return [null, $desc];
}

// Verify a Stripe SECRET key by calling Stripe (GET /v1/balance). Returns
// [true, null] if the key works, or [false, error] with Stripe's own message.
// This is what a Publishable key or a typo'd/expired key fails.
function hl_verify_stripe_key($key) {
    $key = preg_replace('/\s+/', '', $key);
    $url = 'https://api.stripe.com/v1/balance';
    $resp = false; $http = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERPWD => $key . ':',   // secret key is the basic-auth username
        ]);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }
    if ($resp === false) {
        return [false, 'Could not reach Stripe to verify the key. Try again in a moment.'];
    }
    $j = json_decode($resp, true);
    if ($http === 200 && is_array($j) && ($j['object'] ?? '') === 'balance') {
        return [true, null];
    }
    $desc = (is_array($j) && !empty($j['error']['message'])) ? $j['error']['message'] : 'Stripe rejected this key.';
    return [false, $desc];
}

$flash = null; $flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hl_csrf_check();
    $action = $_POST['action'] ?? '';
    $id = $_POST['id'] ?? '';

    if (!isset($SECRETS[$id])) {
        $flash = 'Unknown field.'; $flashType = 'err';
    } elseif ($appKey === null) {
        $flash = 'The master key HL_APP_KEY is not set yet - add it to your .env first (see the note above).'; $flashType = 'err';
    } elseif ($action === 'revert') {
        hl_db()->exec("DELETE FROM settings WHERE key = '" . SQLite3::escapeString($SECRETS[$id]['setkey']) . "'");
        $flash = $SECRETS[$id]['label'] . ' reverted to the value in your .env file.';
    } elseif ($action === 'save') {
        $new = trim($_POST['value'] ?? '');
        $meta = $SECRETS[$id];
        if ($new === '') {
            $flash = 'Please paste a value first.'; $flashType = 'err';
        } elseif ($meta['validate'] === 'bot') {
            list($info, $err) = hl_verify_bot_token($new);
            if ($err) {
                $flash = 'Not saved - ' . $err; $flashType = 'err';
            } else {
                $blob = hl_encrypt_secret($new, $appKey);
                if ($blob === false) { $flash = 'Encryption failed on this server.'; $flashType = 'err'; }
                else {
                    hl_set_setting($meta['setkey'], $blob);
                    $uname = $info['username'] ?? '';
                    $flash = 'Saved and verified - this token belongs to @' . $uname . '. Note: after switching to a different bot I need to re-point the webhook to it (just message me).';
                }
            }
        } elseif ($meta['validate'] === 'stripe') {
            $new = preg_replace('/\s+/', '', $new);   // strip any pasted whitespace
            if (preg_match('/^pk_(live|test)_/', $new)) {
                // HARD stop: a Publishable key (pk_) cannot create charges. This is
                // the single most common mistake, so refuse it and say exactly which
                // key to paste instead.
                $flash = 'Not saved - that is your Publishable key (pk_...). Card charges need your SECRET key, which starts with sk_live_ (or sk_test_). In Stripe: Developers > API keys > Secret key > Reveal, then paste that here.';
                $flashType = 'err';
            } else {
                // Verify the key against Stripe BEFORE saving, so a bad/expired key
                // (the exact thing that produced "checkout unavailable") is caught
                // here instead of silently failing later for a paying user.
                list($ok, $err) = hl_verify_stripe_key($new);
                if (!$ok) {
                    $flash = 'Not saved - Stripe rejected this key: ' . $err . ' | Use your SECRET key (sk_live_ or sk_test_) from Stripe > Developers > API keys.';
                    $flashType = 'err';
                } else {
                    $blob = hl_encrypt_secret($new, $appKey);
                    if ($blob === false) { $flash = 'Encryption failed on this server.'; $flashType = 'err'; }
                    else { hl_set_setting($meta['setkey'], $blob); $flash = 'Stripe key saved and verified with Stripe - card payments are ready.'; }
                }
            }
        } else {
            $blob = hl_encrypt_secret($new, $appKey);
            if ($blob === false) { $flash = 'Encryption failed on this server.'; $flashType = 'err'; }
            else { hl_set_setting($meta['setkey'], $blob); $flash = $meta['label'] . ' saved securely.'; }
        }
    }
}

hl_shell_head('Keys', 'keys', hl_pending_count());
if ($flash) hl_flash($flash, $flashType);
$csrf = h(hl_csrf_token());

if ($appKey === null):
?>
<div class="card">
  <h2>One quick setup step</h2>
  <p class="sub">To store keys in encrypted form, the panel needs a master key called HL_APP_KEY in your bot's .env file. It is not there yet.</p>
  <p>Add a line like this to your .env (I will send you the exact value to paste):</p>
  <p class="mono">HL_APP_KEY=your-32-byte-base64-key</p>
  <p class="muted small">The master key lives only in .env, never in the database - that is what keeps the stored keys safe even if the database were ever copied. Once the line is added, reload this page.</p>
</div>
<?php
    hl_shell_foot();
    exit;
endif;
?>

<div class="card">
  <h2>API keys</h2>
  <p class="sub">Update your Telegram bot token and Stripe key here. Values are encrypted before they are saved, shown only as dots, and the bot picks up a change on its next run. Your .env stays as an automatic fallback, so nothing here can break the bot.</p>

  <?php foreach ($SECRETS as $id => $meta):
      $override = hl_get_setting($meta['setkey'], '');
      $fromPanel = false; $effective = '';
      if ($override !== '') {
          $dec = hl_decrypt_secret($override, $appKey);
          if ($dec !== false) { $effective = $dec; $fromPanel = true; }
      }
      if (!$fromPanel) { $effective = hl_bot_env($meta['env']); }
      $masked = hl_secret_masked($effective);
      $status = $effective === '' ? 'Not set'
              : ($fromPanel ? 'Managed here (encrypted): ' . $masked
                            : 'From .env: ' . $masked);
  ?>
  <div class="secblock">
    <div class="seclabel"><?= h($meta['label']) ?></div>
    <div class="muted small" style="margin-bottom:8px"><?= h($meta['hint']) ?></div>
    <div class="secstatus <?= $effective === '' ? 'mut' : ($fromPanel ? 'ok' : '') ?>"><?= h($status) ?></div>
    <form method="post" style="margin-top:10px">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h($id) ?>">
      <div class="row">
        <div class="field">
          <label>New value (paste to change)</label>
          <input type="password" name="value" autocomplete="off" spellcheck="false" placeholder="paste new <?= h($meta['label']) ?>">
        </div>
        <button type="submit">Save</button>
      </div>
    </form>
    <?php if ($fromPanel): ?>
    <form method="post" onsubmit="return confirm('Revert to the value in your .env file?')">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="revert">
      <input type="hidden" name="id" value="<?= h($id) ?>">
      <button type="submit" class="btn ghost">Revert to .env value</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<p class="muted small">Keys are encrypted with AES-256-GCM using the master key in your .env. The database only ever holds the encrypted form.</p>

<style>
  .secblock{padding:14px 0;border-bottom:1px solid var(--line)}
  .secblock:last-child{border-bottom:0}
  .seclabel{font-weight:600;margin-bottom:2px}
  .secstatus{font-size:13px;color:var(--muted)}
  .secstatus.ok{color:#3fb950}
  .mono{font-family:ui-monospace,Menlo,Consolas,monospace;background:var(--chip);
    padding:8px 10px;border-radius:6px;display:inline-block;font-size:13px;word-break:break-all}
</style>

<?php hl_shell_foot();
