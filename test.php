<?php
error_reporting(E_ALL & ~(E_STRICT|E_NOTICE));
include('functions.php');

$cookies = auth('m.demo.en.cx', 'enxbot', '***REMOVED***');
if($cookies === false)
{
    die('Неверный логин или пароль');
}

//print sendCode($cookies,'m.demo.en.cx','25478','en1s1');
$result = getLevelText($cookies,'m.demo.en.cx','25478');
$result_clean = $purifier->purify($result);

print $result_clean;

