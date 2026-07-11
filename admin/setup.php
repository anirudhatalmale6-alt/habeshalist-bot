<?php
/**
 * setup.php - one-time creation of the admin login.
 * Runs only until an admin password exists in the database; after that it
 * refuses and points you to the login page. No password is ever stored in a
 * file - only a secure hash goes into the database.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_session_start();

// Already configured? Do not allow re-setup from the web (would be a takeover
// risk). To reset a lost password, see README.md (reset-password.php).
if (hl_is_configured()) {
    hl_head('Setup');
    hl_flash('The admin account is already set up. Please log in.', 'err');
    echo '<p><a class="btn" href="login.php">Go to login</a></p>';
    hl_foot();
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hl_csrf_check();
    $user = trim($_POST['username'] ?? '');
    $p1   = $_POST['password'] ?? '';
    $p2   = $_POST['password2'] ?? '';
    if (strlen($user) < 3) {
        $err = 'Username must be at least 3 characters.';
    } elseif (strlen($p1) < 8) {
        $err = 'Password must be at least 8 characters.';
    } elseif ($p1 !== $p2) {
        $err = 'The two passwords do not match.';
    } else {
        hl_set_setting('admin_user', $user);
        hl_set_setting('admin_pass_hash', password_hash($p1, PASSWORD_DEFAULT));
        $_SESSION['hl_admin'] = $user;
        session_regenerate_id(true);
        header('Location: index.php');
        exit;
    }
}

hl_head('Setup');
echo '<div class="card" style="max-width:440px;margin:0 auto">';
echo '<h2>Create your admin login</h2>';
echo '<p class="sub">This is a one-time step. Pick a username and a strong password - you will use these to sign in to the panel.</p>';
if ($err) hl_flash($err, 'err');
echo '<form method="post">';
echo '<input type="hidden" name="csrf" value="' . h(hl_csrf_token()) . '">';
echo '<div class="field" style="margin-bottom:14px"><label>Username</label><input type="text" name="username" autocomplete="username" required></div>';
echo '<div class="field" style="margin-bottom:14px"><label>Password (min 8 characters)</label><input type="password" name="password" autocomplete="new-password" required></div>';
echo '<div class="field" style="margin-bottom:18px"><label>Confirm password</label><input type="password" name="password2" autocomplete="new-password" required></div>';
echo '<button type="submit">Create account</button>';
echo '</form></div>';
hl_foot();
