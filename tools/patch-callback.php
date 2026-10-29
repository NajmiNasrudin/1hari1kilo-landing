<?php
/**
 * Patch the server's chip-callback.php so a failed Brevo send is never hidden:
 *  - log Brevo's HTTP status and reason when a send fails (no buyer data in the log);
 *  - only mark the purchase as sent when the buyer email really went out;
 *  - otherwise answer 502 so CHIP retries the callback later.
 *
 *   php patch-callback.php <path/to/chip-callback.php>   -> prints the patched file to stdout
 *
 * Exits 3 when the file is already patched, 2 when an expected block is missing
 * (the file is then left for a human to look at).
 */

if (PHP_SAPI !== 'cli' || !isset($argv[1]) || !is_file($argv[1])) {
    fwrite(STDERR, "Guna: php patch-callback.php chip-callback.php\n");
    exit(1);
}
$src = file_get_contents($argv[1]);
if (strpos($src, 'chip-callback.php: Brevo send failed') !== false) {
    fwrite(STDERR, "SUDAH-DIBETULKAN: chip-callback.php dah ada pembetulan ni.\n");
    exit(3);
}

$patches = array(
    // 1. Keep Brevo's answer and log it when the send fails.
    array(
        "    curl_exec(\$ch);\n    \$status = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);\n    curl_close(\$ch);\n\n    return \$status === 201;",
        "    \$response = curl_exec(\$ch);\n    \$status = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);\n    curl_close(\$ch);\n\n"
        . "    if (\$status !== 201) {\n"
        . "        \$detail = json_decode((string) \$response, true);\n"
        . "        error_log('chip-callback.php: Brevo send failed (HTTP ' . \$status . ')' . (is_array(\$detail) && isset(\$detail['message']) ? ': ' . \$detail['message'] : ''));\n"
        . "    }\n\n    return \$status === 201;",
    ),
    // 2. Only remember the purchase as sent when the buyer email went out; otherwise let CHIP retry.
    array(
        "\$sent[\$purchaseId] = time();\nfile_put_contents(\$dedupeFile, \"<?php\\nreturn \" . var_export(\$sent, true) . \";\\n\");",
        "if (!\$mailSent) {\n"
        . "    error_log('chip-callback.php: buyer email not sent for purchase ' . \$purchaseId . '; CHIP will retry');\n"
        . "    respond(502, array('ok' => false, 'mail_sent' => false, 'notify_sent' => \$notifySent));\n"
        . "}\n"
        . "\$sent[\$purchaseId] = time();\nfile_put_contents(\$dedupeFile, \"<?php\\nreturn \" . var_export(\$sent, true) . \";\\n\");",
    ),
);

foreach ($patches as $i => $patch) {
    list($old, $new) = $patch;
    $count = substr_count($src, $old);
    if ($count !== 1) {
        fwrite(STDERR, 'STOP: blok #' . ($i + 1) . " dijumpai $count kali (patut 1). Fail tak diubah.\n");
        exit(2);
    }
    $src = str_replace($old, $new, $src);
}
echo $src;
