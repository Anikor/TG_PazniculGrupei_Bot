<?php

$host = '127.0.0.1';
$db   = 'attendance_utm';
$user = 'root';
$pass = '';       
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];


$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, $opts);


define('BOT_TOKEN', 'bottoken');
define('API_URL',   'https://api.telegram.org/bot'.BOT_TOKEN.'/');