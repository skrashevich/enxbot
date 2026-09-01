<?php

//
// config.php
//
//$mysql_server = '';
//$mysql_user = '';
//$mysql_pass = '';
//$mysql_db = '';

// Начиная с PHP 8.1 mysqli по умолчанию бросает исключения на любой ошибке SQL.
// Код бота рассчитан на проверку ошибок вручную, поэтому режим задаётся явно.
mysqli_report(MYSQLI_REPORT_OFF);

$db = mysqli_connect($mysql_server, $mysql_user, $mysql_pass, $mysql_db);

if (!$db) {
    error_log('Не удалось подключиться к БД: '.mysqli_connect_error());
    die('Ошибка подключения к БД');
}

// Кодировка соединения совпадает с кодировкой таблиц из db.sql (utf8mb3).
// Переход на utf8mb4 требует отдельной миграции схемы.
mysqli_set_charset($db, 'utf8');

unset($mysql_pass);
