<?php
error_reporting(E_ALL); ini_set('log_errors','1'); ini_set('display_errors','0');

$ENV = is_readable('/etc/attendance-bot.env')
  ? (parse_ini_file('/etc/attendance-bot.env', false, INI_SCANNER_TYPED) ?: [])
  : [];

function env($k,$d=null){global $ENV; if(array_key_exists($k,$ENV)&&$ENV[$k]!=='')return $ENV[$k]; $v=getenv($k); return ($v===false||$v==='')?$d:$v;}

// All date()/strtotime() calls follow this. Without it, "today" comes from the
// server's TZ while the lock logic uses Europe/Chisinau explicitly — around
// midnight those disagree and attendance lands on the wrong day.
define('APP_TZ', env('APP_TZ', 'Europe/Chisinau'));
date_default_timezone_set(APP_TZ);

define('APP_HOST', env('APP_HOST','https://pi.anikor.eu'));
define('SECONDARY_TG_ID', (int)env('SECONDARY_TG_ID', 0)); // admin "Secondary" shortcut; 0 hides the button

// Business values that used to be hardcoded in page files.
define('PRIMARY_GROUP_NAME', env('PRIMARY_GROUP_NAME', 'R-241')); // admin "Primary" view shortcut
define('LAB_FEE_LEI', (int)env('LAB_FEE_LEI', 50));               // estimated fee per missed lab
define('MODERATOR_GRACE_MIN', (int)env('MODERATOR_GRACE_MIN', 20));          // minutes after last lesson
define('MODERATOR_FALLBACK_CUTOFF', env('MODERATOR_FALLBACK_CUTOFF', '18:00')); // when no schedule that day
define('BOT_TOKEN',env('BOT_TOKEN','')); if(BOT_TOKEN===''){error_log('FATAL: BOT_TOKEN missing');http_response_code(500);exit('Config error');}

$DB_HOST = env('DB_HOST','127.0.0.1');
$DB_NAME = env('DB_NAME','attendence_utm');   // note spelling as in your dump
$DB_USER = env('DB_USER','paznic');
$DB_PASS = env('DB_PASS','');

$dsn = "mysql:host=$DB_HOST;port=3306;dbname=$DB_NAME;charset=utf8mb4"; // force TCP
try {
  $pdo = new PDO($dsn,$DB_USER,$DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
  ]);
} catch (Throwable $e) {
  error_log('DB connect failed: '.$e->getMessage());
  http_response_code(500); exit('DB error');
}
