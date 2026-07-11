<?php
/**
 * signin.php - admin sign in. Includes a small brute-force delay.
 * (Named "signin" rather than "login" because some shared-host WAFs block the
 * literal filename login.php.)
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_session_start();

// Not set up yet -> send to first-run setup.
if (!hl_is_configured()) {
    header('Location: setup.php');
    exit;
}
if (hl_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hl_csrf_check();
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    $realUser = hl_get_setting('admin_user', '');
    $hash     = hl_get_setting('admin_pass_hash', '');
    $ok = hash_equals($realUser, $user) && $hash && password_verify($pass, $hash);
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['hl_admin'] = $user;
        header('Location: index.php');
        exit;
    }
    // Slow down repeated guesses.
    sleep(1);
    $err = 'Wrong username or password.';
}

hl_head('Login');
echo '<div class="card" style="max-width:400px;margin:6vh auto 0">';
echo '<h2>Sign in</h2>';
echo '<p class="sub">HabeshaList admin panel</p>';
if ($err) hl_flash($err, 'err');
echo '<form method="post">';
echo '<input type="hidden" name="csrf" value="' . h(hl_csrf_token()) . '">';
echo '<div class="field" style="margin-bottom:14px"><label>Username</label><input type="text" name="username" autocomplete="username" required autofocus></div>';
echo '<div class="field" style="margin-bottom:18px"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div>';
echo '<button type="submit">Sign in</button>';
echo '</form></div>';
hl_foot();
