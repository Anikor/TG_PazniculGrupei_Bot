<?php
error_reporting(E_ALL); ini_set('log_errors','1');

$ENV = is_readable('/etc/attendance-bot.env')
  ? (parse_ini_file('/etc/attendance-bot.env', false, INI_SCANNER_TYPED) ?: [])
  : [];

function env($k,$d=null){global $ENV; if(array_key_exists($k,$ENV)&&$ENV[$k]!=='')return $ENV[$k]; $v=getenv($k); return ($v===false||$v==='')?$d:$v;}

define('APP_HOST', env('APP_HOST','https://pi.anikor.eu'));
define('SECRET_TOKEN', env('SECRET_TOKEN', ''));
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
define('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/');

// === Telegram basics (appended) ===
if (!defined('BOT_TOKEN')) {
  $botTokenFromEnv = getenv('BOT_TOKEN') ?: '';
  define('BOT_TOKEN', $botTokenFromEnv);
}
if (!defined('API_URL')) {
  define('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/');
}
