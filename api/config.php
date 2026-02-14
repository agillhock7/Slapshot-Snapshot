<?php
declare(strict_types=1);

$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    require $localConfig;
}

define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('DB_HOST', getenv('DB_HOST') ?: (defined('LOCAL_DB_HOST') ? LOCAL_DB_HOST : '127.0.0.1'));
define('DB_PORT', getenv('DB_PORT') ?: (defined('LOCAL_DB_PORT') ? LOCAL_DB_PORT : '3306'));
define('DB_NAME', getenv('DB_NAME') ?: (defined('LOCAL_DB_NAME') ? LOCAL_DB_NAME : ''));
define('DB_USER', getenv('DB_USER') ?: (defined('LOCAL_DB_USER') ? LOCAL_DB_USER : ''));
define('DB_PASS', getenv('DB_PASS') ?: (defined('LOCAL_DB_PASS') ? LOCAL_DB_PASS : ''));
define('APP_PUBLIC_URL', getenv('APP_PUBLIC_URL') ?: (defined('LOCAL_APP_PUBLIC_URL') ? LOCAL_APP_PUBLIC_URL : 'https://snap.pucc.us'));
define('APP_BRAND_NAME', getenv('APP_BRAND_NAME') ?: (defined('LOCAL_APP_BRAND_NAME') ? LOCAL_APP_BRAND_NAME : 'Slapshot Snapshot'));
define('APP_INVITE_LOGO_URL', getenv('APP_INVITE_LOGO_URL') ?: (defined('LOCAL_APP_INVITE_LOGO_URL') ? LOCAL_APP_INVITE_LOGO_URL : ''));
define('SUPPORT_EMAIL', getenv('SUPPORT_EMAIL') ?: (defined('LOCAL_SUPPORT_EMAIL') ? LOCAL_SUPPORT_EMAIL : 'support@pucc.us'));

define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_ROOT', APP_ROOT . '/uploads');
define('MAX_UPLOAD_BYTES', 300 * 1024 * 1024);
define('MAX_TEAM_LOGO_BYTES', 8 * 1024 * 1024);
