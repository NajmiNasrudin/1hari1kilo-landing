<?php
/**
 * Copy this file to chip-config.php and fill in your real values.
 *
 * chip-config.php is listed in .gitignore and must NEVER be committed to
 * git or uploaded through the GitHub deploy pipeline -- the repo is public.
 * Create chip-config.php directly on the server via cPanel File Manager
 * (New File, paste the content below with real values, save it inside
 * the same folder as create-purchase.php). It will not be touched or
 * overwritten by future git deploys since it is never part of the repo.
 */
return array(
    'secret_key' => 'YOUR_CHIP_SECRET_KEY_HERE',
    'brand_id'   => 'YOUR_CHIP_BRAND_ID_HERE',
);
