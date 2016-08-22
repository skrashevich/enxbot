<?php
error_reporting(E_ALL & ~(E_STRICT|E_NOTICE));

include('config.php');
include('db.php');
include('functions.php');

/* config.php :
define('BOT_TOKEN', 'токен');
define('WEBHOOK_URL', 'URL');
define('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/');
define('ENCRYPTION_KEY', 'ключ');
define('BOT_USERNAME', 'имя бота без собаки');
define('ADMIN_USERNAME', 'юзернейм главного администратора без собаки');
define('YANDEX_ACCOUNT_NUMBER', 'номер яндекс кошелька для приема денег');
define('YANDEX_ACCOUNT_SECRET', 'Секретное слово');
define('YANDEX_ACCESS_TOKEN', 'токен авторизации приложения');
define('PAYMENT_SUM', 'стоимость игры в рублях'); // отрицательное значение - работа в режиме администраторов. 0 - доступно всем. больше нуля - за деньги
*/


// if run from console, set or delete webhook
if (php_sapi_name() == 'cli') {
  apiRequest('setWebhook', array('url' => isset($argv[1]) && $argv[1] == 'delete' ? '' : WEBHOOK_URL));
  die();
}

$helptext = "Это бот для игры Encounter 
Основная цель бота: пробитие кодов и оптимизация взаимодействия с движком.

*Настройка бота*:
Для начала использования добавьте бота в ваш игровой чат и последовательно вводите данные команды:
/game domain _домен_ - задать домен, например _moscow.en.cx_
/game login _логин_ - задать логин движка
/game pass _пароль_ - задать пароль движка. Пароль задается в зашифрованном виде! (см команду /encrypt)
/game id _id_ - ID игры (из адресной строки!), например _12345_
/game auth - авторизоваться на движке
/game start - старт бота

*Другие команды*:
/game test - проверить подключение к игре
/game print - вывод настроек
/game stop - остановка бота 
/game delete - удалить все настройки игры в канале".(PAYMENT_SUM>0 ? ', *включая информацию о внесенных средствах*' : '')."

/encrypt _пароль_ - в личку боту! получить зашифрованный пароль для установки в канале
/help или /start - помощь

*Игровой процесс*:
/level - отобразить текст текущего уровня.
\tБот попробует найти в тексте уровня координаты и выслать их в виде локации для упрощения построения маршрута.
/hints - отобразить подсказки на уровне
/sectors - отобразить сектора уровня
/messages - отобразить сообщения организатора

*Пробитие кодов*
Коды пробивать с префиксом & либо #
Например: _&en123_
После кода можно ввести комментарий, например: _&en123//3 этаж_
В движок пойдет всё до символов //, в данном случае en123.

Бот уведомляет о подсказках и автопереходе за 5 и 15 минут,
а также непосредственно в момент наступления события.

";
if(PAYMENT_SUM>0) 
    $helptext .= 'Стоимость одной игры с ботом: '.PAYMENT_SUM.' рублей. При запуске бота в чате он предложит совершить оплату с помощью банковской карты. Сразу после успешной оплаты бот автоматически запустится.';
if(PAYMENT_SUM<0)
    $helptext .= 'В текущий момент бот работает в режиме ограниченного доступа. Для получения возможности работы с ботом обратитесь к @'.ADMIN_USERNAME;
if(PAYMENT_SUM==0)
    $helptext .= "В настоящее время бот работет в бесплатном режиме. Вы можете использовать его без оплаты.";

$helptext .="

Автор бота: @skrashevich <svk>";

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if(isset($update["message"]))
{
	$message=$update["message"];
	logMessage($message);

    if(isset($message["text"]))
    {
        $text=$message["text"];
		$message_id=$message['message_id'];
		$chat_id=$message['chat']['id'];

        $sql = "SELECT * FROM games WHERE chat_id = $chat_id";
        $result = mysql_query($sql);
        if(mysql_error())
        {
            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => 'Ошибка подключения к БД'));
            die();
        }
        if(mysql_num_rows($result)===0)
        {
            $sql = "INSERT INTO games (chat_id) VALUES (".intval($chat_id).")";
            $result = mysql_query($sql);
            $sql = "SELECT * FROM games WHERE chat_id = $chat_id";
            $result = mysql_query($sql);
        }
        $settings = mysql_fetch_assoc($result);

        $sql = "SELECT * FROM admins";
        $result = mysql_query($sql);
        while($row = mysql_fetch_assoc($result))
        {
            $settings['admins'][]=$row['admin_username'];
        }

        // Проверяем первый символ
		$ch=mb_substr($text,0,1);
		if(in_array($ch,array("/", "&", "#")))
        {
            list($command,$args) = explode(' ', $text, 2);
            $args = explode(' ', $args);

            // for commands like /level@enxbot
            $command = str_replace('@'.BOT_USERNAME, '', $command);

            switch($command)
            {
                case '/help':
                case '/start':
                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "parse_mode" => 'Markdown', "text" => $helptext));
                break;
                case '/game':
                    switch($args[0])
                    {
                        case 'domain':
                            if($settings['status']==0)
                            {
                                $sql = "UPDATE games SET game_domain = 'm.".mysql_escape_string($args[1])."' WHERE chat_id = $chat_id";
                                mysql_query($sql);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Установлен домен $args[1]"));
                            } else {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Невозможно поменять домен в процессе игры. Остановите бота командой /game stop"));
                            }
                        break;
                        case 'id':
                            if($settings['game_id']==0)
                            {
                                $sql = "UPDATE games SET game_id = ".intval($args[1])." WHERE chat_id = $chat_id";
                                mysql_query($sql);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Установлен ID игры $args[1]"));
                            } else {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Невозможно поменять ID игры после создания. Воспользуйтесь командой /game delete для удаления прошлой игры в данном чате."));
                            }
                        break;
                        case 'login':
                            $sql = "UPDATE games SET game_login= '".mysql_escape_string($args[1])."' WHERE chat_id = $chat_id";
                            mysql_query($sql);
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Установлен игровой логин $args[1]"));
                        break;
                        case 'pass':
                            $clear_pass = decrypt($args[1], ENCRYPTION_KEY);
                            $clear_pass = trim($clear_pass);
                            $sql = "UPDATE games SET game_pass = '".mysql_escape_string($clear_pass)."' WHERE chat_id = $chat_id";
                            mysql_query($sql);
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Установлен пароль"));
                        break;
                        case 'auth':
                            $cookies = auth($settings["game_domain"], $settings["game_login"], $settings["game_pass"]);
                            $settings['cookies'] = $cookies;
                            if($cookies===false)
                            {
                                $result = "Не проходит авторизация на игровом движке";
                            } else {
                                $result = "Авторизация успешно пройдена";
                                $sql = "UPDATE games SET cookies = '".mysql_escape_string($cookies)."' WHERE chat_id = $chat_id";
                                mysql_query($sql);
                            }
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                        break;
                        case 'test':
                            $result = testGame($settings['cookies'],$settings["game_domain"],$settings["game_id"]);
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result ? $result : 'Ошибка'));
                        break;
                        case 'start':
                            if($settings['payment']>=PAYMENT_SUM)
                            {
                                if( (in_array($message['from']['username'], $settings['admins']) && PAYMENT_SUM==-1) || ( $settings['payment'] >= PAYMENT_SUM && PAYMENT_SUM >=0) )
                                {
                                    $sql = "UPDATE games SET status = 1 WHERE chat_id = $chat_id";
                                    mysql_query($sql);
                                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Игра привязана к чату"));
                                } else {
                                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "У вас нет прав для запуска игры."));
                                }
                            } else {
                                $paykey = urlencode(encrypt("$chat_id|$settings[game_id]", ENCRYPTION_KEY));
                                $text = "Услуги бота в данном чате не оплачены.\nДля оплаты перейдите по ссылке: <a href=\"https://money.yandex.ru/embed/shop.xml?account=".YANDEX_ACCOUNT_NUMBER."&quickpay=shop&payment-type-choice=on&mobile-payment-type-choice=on&writer=seller&targets=$paykey&targets-hint=&default-sum=".PAYMENT_SUM."&button-text=01&successURL=\">Оплатить</a>\n\nСтоимость бота: <b>".PAYMENT_SUM."</b> руб. После успешной оплаты в чат придет уведомление о возможности запуска бота. Есть возможность платить по частям, в таком случае бот начнет работать как только наберется необходимая сумма.\nТекущий баланс: <b>$settings[payment]</b> руб.\n\nУбедитесь, что ID игры задан корректно. Вы не сможете его поменять.";
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "parse_mode" => 'HTML', "reply_to_message_id" => $message_id, "text" => $text));
                            }
                        break;
                        case 'stop':
                            if( (in_array($message['from']['username'], $settings['admins']) && PAYMENT_SUM==-1) || ( $settings['payment'] >= PAYMENT_SUM && PAYMENT_SUM >=0) )
                            {
                                $sql = "UPDATE games SET status = 0 WHERE chat_id = $chat_id";
                                mysql_query($sql);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Бот остановлен"));
                            } else 
                            {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "У вас недостаточно прав для остановки бота"));
                            }
                        break;
                        case 'delete':
                            if( (in_array($message['from']['username'], $settings['admins']) && PAYMENT_SUM==-1) || ( $settings['payment'] >= PAYMENT_SUM && PAYMENT_SUM >=0) )
                            {
                                $sql="DELETE FROM games WHERE chat_id = $chat_id";
                                mysql_query($sql);
                                $sql="DELETE FROM timers WHERE chat_id = $chat_id";
                                mysql_query($sql);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Настройки игры удалены"));
                            } else 
                            {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "У вас недостаточно прав для остановки бота"));
                            }
                        break;
                        case 'print':
                            $text = "Домен: $settings[game_domain]\nИгра $settings[game_id]\nСтатус $settings[status]\nЛогин $settings[game_login]\nПароль ".($settings['game_pass'] ? 'задан' : 'не задан').(PAYMENT_SUM > 0 ? "\nВнесено денег: $settings[payment]\nСтоимость бота: ".PAYMENT_SUM : '');
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => $text));
                        break;
                    }
                break;
                case '/level':
                    if(!$settings['status'])
                    {
                        $result = "Нет активной игры";
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                    } else 
                    {
                        $levelText = getLevelText($settings['cookies'],$settings["game_domain"],$settings["game_id"]);

                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "parse_mode" => 'HTML', "text" => $levelText ? $levelText : 'Ошибка'));

                        // Ищем в тексте координаты
                        $coords = getCoordsFromText($levelText);
                        foreach($coords as $match)
                        {
                            $text = $match['text'];
                            $lat = $match['lat'];
                            $lon = $match['lon'];

                            $address = $match['address'];

                            apiRequestJSON("sendLocation", array('chat_id' => $chat_id, "latitude" => $lat, "longitude" => $lon));
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => "$lat $lon"));
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => $address));
                        }
                    }

                break;
                case '/sectors':
                    if(!$settings['status'])
                    {
                        $result = "Нет активной игры";
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                    } else 
                    {
                        $sectors = getSectors($settings['cookies'],$settings["game_domain"],$settings["game_id"]);

                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "parse_mode" => 'HTML', "text" => $sectors ? $sectors : 'Ошибка'));
                    }
                break;
                case '/messages':
                    if(!$settings['status'])
                    {
                        $result = "Нет активной игры";
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                    } else 
                    {
                        $messages = getMessages($settings['cookies'],$settings["game_domain"],$settings["game_id"]);

                        foreach($messages as $message)
                        {
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "parse_mode" => 'HTML', "text" => $message));
                        }
                    }
                break;
                case '/encrypt':
                    $result = encrypt($args[0], ENCRYPTION_KEY);
                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Зашифрованный пароль: $result"));
                break;
                case '/hints':
                    if(!$settings['status'])
                    {
                        $result = "Нет активной игры";
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                    } else 
                    {
                        $array = getHints($settings['cookies'],$settings["game_domain"],$settings["game_id"]);
                        $hints = $array['result'];
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "parse_mode" => 'Markdown', "text" => $hints));
                    }
                break;
                case '/admin':
                    if($message['from']['username'] == ADMIN_USERNAME)
                    {
                        switch($args[0])
                        {
                            case 'add':
                                $sql="INSERT INTO admins (admin_username) VALUES ('".mysql_escape_string($args[1])."')";
                                mysql_query($sql);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Администратор $args[1] добавлен"));
                            break;
                            case 'print':
                                $result = '';
                                foreach($settings['admins'] as $admin)
                                {
                                    $result .= "@$admin\n";
                                }
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Список администраторов бота:\n$result"));
                            break;
                            case 'delete':
                                $sql="DELETE FROM admins WHERE admin_username = '".mysql_escape_string($args[1])."'";
                                mysql_query($sql);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Администратор $args[1] удален"));
                            break;
                        }
                    }
                break;
            }
        }

        if(in_array($ch,array("&","#")))
        {
            if(!$settings['status'])
            {
                $result = "Нет активной игры";
                apiRequest("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
            } else 
            {
                $code=substr($text,1);

                // Пробиваем в движок все до символов //
                list($code,$comment) = explode('//', $code, 2);
                if(!$settings["cookies"])
                {
                    $cookies = auth($settings["game_domain"], $settings["game_login"], $settings["game_pass"]);
                    $sql = "UPDATE games SET cookies = '".mysql_escape_string($cookies)."' WHERE chat_id = $chat_id";
                    mysql_query($sql);
                }
                else {
                    $cookies = $settings["cookies"];
                }
                if($cookies===false)
                {
                    $result = "Не проходит авторизация на игровом движке";
                    apiRequest("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                } else {
                    $array = sendCode($cookies,$settings["game_domain"],$settings["game_id"],$code);
                    $result = $array['result'];

                    apiRequest("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));

                    $levelId = $array['levelid'];
                    $sql = "UPDATE games SET last_level_id = ".intval($levelId)." WHERE chat_id = $chat_id";
                    mysql_query($sql);
                }
            }
            if(!$result)
            {
                $result = "Ошибка";
                apiRequest("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
            }
        }
    }
}
