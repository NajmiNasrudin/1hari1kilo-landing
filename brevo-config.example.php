<?php
/**
 * Copy this file to brevo-config.php and fill in your real values.
 *
 * brevo-config.php is listed in .gitignore and must NEVER be committed to
 * git or uploaded through the GitHub deploy pipeline -- the repo is public.
 * Create brevo-config.php directly on the server via cPanel File Manager
 * (New File, paste the content below with real values, save it inside
 * the same folder as chip-callback.php). It will not be touched or
 * overwritten by future git deploys since it is never part of the repo.
 *
 * Get the API key from Brevo dashboard -> Settings -> SMTP & API ->
 * "API Keys" tab (NOT the "SMTP" tab -- that's a different credential).
 * The key must start with "xkeysib-".
 */
return array(
    'api_key'      => 'YOUR_BREVO_API_KEY_HERE',
    'sender_email' => 'noreply@coachcem.com',
    'sender_name'  => 'Coach Cem / TeamCC7',
);
