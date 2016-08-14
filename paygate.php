<?php
/*
    Скрипт приёма платежей с яндекс-денег
    Для работы необходимо в кошельке включить уведомление о платежах на адрес этого скрипта
    А также получить токен авторизации для получения информации о платежах
    Все настройки указываются в config.php
*/
error_reporting(E_ALL & ~(E_STRICT|E_NOTICE));

include('config.php');
include('db.php');
include('functions.php');

$operation_id = $_POST['operation_id'];
$amount = $_POST['withdraw_amount'];
$type = $_POST['notification_type'];
$unaccepted = $_POST['unaccepted'];

if($unaccepted == 'true')
{
    // Перевод не принят
    die();
}

//
// Запрашиваем подробности операции
//
$post = 'operation_id='.$operation_id;
$ch = curl_init('https://money.yandex.ru/api/operation-details');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, Array('Authorization: Bearer '.YANDEX_ACCESS_TOKEN));
$response = curl_exec($ch);
curl_close($ch);

$return = json_decode($response, true);

$message = decrypt($return['message'], ENCRYPTION_KEY);
list($chat_id, $game_id) = explode('|', $message, 2);

$chat_id = mysql_escape_string($chat_id);
$game_id = intval($game_id);

$sql = "SELECT payment FROM games WHERE chat_id = $chat_id AND game_id = $game_id";
$result = mysql_query($sql);
$row = mysql_fetch_assoc($result);

$payment = $row['payment']+$amount;

if($payment >= PAYMENT_SUM)
{
    $text = "Поступила оплата за бота: <b>$amount</b> руб. Работа бота полностью оплачена.";
} else {
    $text = "Поступила оплата за бота: <b>$amount</b> руб. Осталось заплатить: <b>".(PAYMENT_SUM-$payment).'</b> руб';
}
$sql = "UPDATE games SET payment = payment+$amount WHERE chat_id = $chat_id AND game_id = $game_id";
mysql_query($sql);

apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "parse_mode" => 'HTML', "text" => $text));