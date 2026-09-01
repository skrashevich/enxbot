<?php
/*
    Скрипт приёма платежей с яндекс-денег
    Для работы необходимо в кошельке включить уведомление о платежах на адрес этого скрипта
    А также получить токен авторизации для получения информации о платежах
    Все настройки указываются в config.php
*/
// E_STRICT удалён как уровень ошибок и объявлен deprecated в PHP 8.4
error_reporting(E_ALL & ~E_NOTICE);

include('config.php');
include('db.php');
include('functions.php');

$operation_id = isset($_POST['operation_id']) ? $_POST['operation_id'] : '';
$amount = isset($_POST['withdraw_amount']) ? floatval($_POST['withdraw_amount']) : 0;
$unaccepted = isset($_POST['unaccepted']) ? $_POST['unaccepted'] : '';

if($operation_id === '' || $unaccepted == 'true')
{
    // Перевод не принят или уведомление без идентификатора операции
    die();
}

//
// Запрашиваем подробности операции
//
$post = 'operation_id='.urlencode($operation_id);
$ch = curl_init('https://money.yandex.ru/api/operation-details');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, Array('Authorization: Bearer '.YANDEX_ACCESS_TOKEN));
$response = curl_exec($ch);

if($response === false)
{
    error_log('paygate: не удалось получить детали операции '.$operation_id);
    die();
}

$return = json_decode($response, true);

$message = isset($return['message']) ? decrypt($return['message'], ENCRYPTION_KEY) : false;
if($message === false)
{
    error_log('paygate: не удалось расшифровать метку платежа для операции '.$operation_id);
    die();
}

$parts = explode('|', $message, 2);
$chat_id = intval($parts[0]);
$game_id = isset($parts[1]) ? intval($parts[1]) : 0;

$sql = "SELECT payment FROM games WHERE chat_id = $chat_id AND game_id = $game_id";
$result = db_query($db, $sql);
$row = db_fetch_assoc($result);

if(!$row)
{
    error_log("paygate: игра не найдена (chat_id=$chat_id, game_id=$game_id)");
    die();
}

$payment = $row['payment']+$amount;

if($payment >= PAYMENT_SUM)
{
    $text = "Поступила оплата за бота: <b>$amount</b> руб. Работа бота полностью оплачена.";
} else {
    $text = "Поступила оплата за бота: <b>$amount</b> руб. Осталось заплатить: <b>".(PAYMENT_SUM-$payment).'</b> руб';
}
$sql = "UPDATE games SET payment = payment+$amount WHERE chat_id = $chat_id AND game_id = $game_id";
db_query($db, $sql);

apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "parse_mode" => 'HTML', "text" => $text));