<?php
/**
 * Sample config — copy to config.php and fill real values.
 * cp private/config/config.sample.php private/config/config.php
 * Then: openssl rand -base64 32  -> ENCRYPTION_KEY_B64
 *       openssl rand -base64 64  -> JWT_SECRET
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'babar_central');
define('DB_USER', 'babar_user');
define('DB_PASS', 'StrongPass_ChangeMe!123');

define('ENCRYPTION_KEY_B64', 'REPLACE_WITH_openssl_rand_-base64_32_OUTPUT');
define('JWT_SECRET', 'REPLACE_WITH_openssl_rand_-base64_64_OUTPUT');

define('APP_URL', 'https://your-host.example.com');

define('ENV', 'production');
