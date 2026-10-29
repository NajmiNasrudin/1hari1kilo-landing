<?php
/**
 * Patch the server's chip-callback.php to stop using Brevo:
 *  - every email (buyer delivery + any owner fallback) goes through Spacemail via
 *    coachcem-store's mailer (coachcem_legacy_mail in legacy-bridge.php);
 *  - brevo-config.php is no longer required;
 *  - a failed send is logged (no buyer data in the log), the purchase is NOT marked
 *    as sent, and the callback answers 502 so CHIP retries later.
 *
 *   php patch-callback.php <path/to/chip-callback.php>   -> prints the patched file to stdout
 *
 * Exit 3: already patched. Exit 2: an expected block is missing (file left untouched).
 */

if (PHP_SAPI !== 'cli' || !isset($argv[1]) || !is_file($argv[1])) {
    fwrite(STDERR, "Guna: php patch-callback.php chip-callback.php\n");
    exit(1);
}
$src = file_get_contents($argv[1]);
if (strpos($src, 'function sendDeliveryEmail(') !== false) {
    fwrite(STDERR, "SUDAH-DIBETULKAN: chip-callback.php dah guna Spacemail.\n");
    exit(3);
}

function stop($message) {
    fwrite(STDERR, "STOP: $message Fail tak diubah.\n");
    exit(2);
}

$bridge = '/home/zjoawlrttx/coachcem-store/current/deploy/spaceship/legacy-bridge.php';

// 1. Replace the Brevo sender with a Spacemail sender (same arguments, so every caller keeps working).
if (preg_match_all('/^function sendBrevoEmail\([^)]*\)\s*\{.*?^\}\n/ms', $src, $m) !== 1) {
    stop('fungsi sendBrevoEmail tak dijumpai tepat sekali.');
}
$sender = <<<PHP
function sendDeliveryEmail(\$unused, \$toEmail, \$toName, \$subject, \$htmlBody) {
    // Spacemail through coachcem-store's mailer. Brevo is no longer used.
    require_once '$bridge';
    \$sent = function_exists('coachcem_legacy_mail')
        && coachcem_legacy_mail(array('to' => \$toEmail, 'name' => \$toName, 'subject' => \$subject, 'html' => \$htmlBody));
    if (!\$sent) {
        error_log('chip-callback.php: Spacemail send failed');
    }

    return \$sent;
}

PHP;
$src = str_replace($m[0][0], $sender, $src);
$calls = substr_count($src, 'sendBrevoEmail(');
if ($calls < 1) {
    stop('tiada panggilan sendBrevoEmail untuk ditukar.');
}
$src = str_replace('sendBrevoEmail(', 'sendDeliveryEmail(', $src);

// 2. brevo-config.php is no longer needed.
if (preg_match_all('/^\$brevoConfigPath = .*?^\$brevoConfig = require \$brevoConfigPath;\n/ms', $src, $m) !== 1) {
    stop('blok brevo-config.php tak dijumpai tepat sekali.');
}
$src = str_replace($m[0][0], "\$brevoConfig = null; // Brevo dibuang: emel dihantar melalui Spacemail (coachcem-store).\n", $src);

// 3. Only remember the purchase as sent when the buyer email went out; otherwise let CHIP retry.
if (strpos($src, 'CHIP will retry') === false) {
    $old = "\$sent[\$purchaseId] = time();\nfile_put_contents(\$dedupeFile, \"<?php\\nreturn \" . var_export(\$sent, true) . \";\\n\");";
    if (substr_count($src, $old) !== 1) {
        stop('blok sent-purchases tak dijumpai tepat sekali.');
    }
    $src = str_replace($old, "if (!\$mailSent) {\n"
        . "    error_log('chip-callback.php: buyer email not sent for purchase ' . \$purchaseId . '; CHIP will retry');\n"
        . "    respond(502, array('ok' => false, 'mail_sent' => false, 'notify_sent' => \$notifySent));\n"
        . "}\n" . $old, $src);
}

if (strpos($src, 'api.brevo.com') !== false) {
    stop('masih ada rujukan ke api.brevo.com selepas pembetulan.');
}
echo $src;
