<?php
/**
 * reset-password.php - CLI ONLY. Clears the stored admin login so you can run
 * setup.php again (e.g. if you forget the password). Cannot be used from a
 * browser.
 *
 * Usage over SSH:  php reset-password.php
 * If you don't have SSH, ask me and I'll give you a one-line alternative.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}
require __DIR__ . '/lib.php';
hl_db()->exec("DELETE FROM settings WHERE key IN ('admin_user','admin_pass_hash')");
echo "Admin login cleared. Open setup.php in your browser to create a new one.\n";
