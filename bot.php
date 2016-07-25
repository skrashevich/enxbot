<?php
error_reporting(E_ALL & ~(E_STRICT|E_NOTICE));

define('BOT_TOKEN', '***REMOVED-TOKEN***');
define('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/');
define('WEBHOOK_URL', 'https://shtab.svk.su/enbot/bot.php');

include('functions.php');

$settings_all = json_decode(file_get_contents('config'),true);

// if run from console, set or delete webhook
if (php_sapi_name() == 'cli') {
  apiRequest('setWebhook', array('url' => isset($argv[1]) && $argv[1] == 'delete' ? '' : WEBHOOK_URL));
  die();
}

$helptext = "Это бот для игры Encounter 
Основная цель бота - пробитие кодов.

Настройка бота:
/game domain <domain> - задать домен
/game login <login> - задать логин движка
/game pass <pass> - задать пароль движка. Пароль задается в зашифрованном виде!
/game id <id> - ID игры (из адресной строки!)

/game auth - авторизоваться на движке
/game print - вывод настроек

/game start - старт бота
/game stop - остановка бота
/game delete - удалить все настройки игры в канале


/encrypt <пароль> - в личку боту! получить зашифрованный пароль для установки в канале

Игровой процесс:

/level - отобразить текст текущего уровня
/hints - отобразить подсказки на уровне

Коды пробивать с префиксом & либо #
Например: &en123
После кода можно ввести комментарий, например: &en123//3 этаж
В движок пойдет всё до символов //, в данном случае en123.

/help или /start - помощь

Автор бота: @skrashevich";

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

        
        if(!isset($settings_all[md5($chat_id)]))
            $settings_all[md5($chat_id)] = Array();
        $settings = $settings_all[md5($chat_id)];

        // Проверяем первый символ
		$ch=mb_substr($text,0,1);
		if(in_array($ch,array("/", "&", "#")))
        {
            list($command,$args) = explode(' ', $text, 2);
            $args = explode(' ', $args);

            // for commands like /level@enxbot
            $command = str_replace('@enxbot', '', $command);

            switch($command)
            {
                case '/help':
                case '/start':
                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => $helptext));
                break;
                case '/game':
                    switch($args[0])
                    {
                        case 'domain':
                            $settings["game_domain"] = 'm.'.$args[1];
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Установлен домен $args[1]"));
                        break;
                        case 'id':
                            $settings["game_id"] = $args[1];
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Установлен ID игры $args[1]"));
                        break;
                        case 'login':
                            $settings["game_login"] = $args[1];
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Установлен игровой логин $args[1]"));
                        break;
                        case 'pass':
                            $clear_pass = decrypt($args[1], 'Cjhjrnsczxj,tpmzyd;jgeceyekb,fyfy');

                            $settings["game_pass"] = trim($clear_pass);
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Установлен пароль"));
                        break;
                        case 'auth':
                            $cookies = auth($settings["game_domain"], $settings["game_login"], $settings["game_pass"]);
                            if($cookies===false)
                            {
                                $result = "Не проходит авторизация на игровом движке";
                            } else {
                                $result = "Авторизация успешно пройдена";
                                $settings['cookies'] = $cookies;
                            }
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                        break;
                        case 'test':
                            $result = testGame($settings['cookies'],$settings["game_domain"],$settings["game_id"]);
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result ? $result : 'Ошибка'));
                        break;
                        case 'start':
                            if(!in_array($message['from']['username'], $settings_all['admins']))
                            {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "У вас недостаточно прав для старта игры"));
                            } else {
                                $settings["game_chat"] = $chat_id;
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Игра привязана к чату"));
                            }
                        break;
                        case 'stop':
                            if(!in_array($message['from']['username'], $settings_all['admins']))
                            {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "У вас недостаточно прав для остановки бота"));
                            } else 
                            {
                                $settings["game_chat"] = '';
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Игра отвязана от чата"));
                            }
                        break;
                        case 'delete':
                            if(!in_array($message['from']['username'], $settings_all['admins']))
                            {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "У вас недостаточно прав для остановки бота"));
                            } else 
                            {
                                $settings = Array();
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Настройки игры удалены"));
                            }
                        break;
                        case 'print':
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => "Домен: $settings[game_domain]\nИгра $settings[game_id]\nЧат $settings[game_chat]\nЛогин $settings[game_login]\nПароль ".($settings['game_pass'] ? 'задан' : 'не задан')));
                        break;
                    }
                break;
                case '/level':
                    if($chat_id!=$settings["game_chat"])
                    {
                        $result = "В данном чате бот недоступен";
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                    } else 
                    {
                        $levelText = getLevelText($settings['cookies'],$settings["game_domain"],$settings["game_id"]);

                        $result = $levelText;
                        $result_clean = $purifier->purify($result);
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result ? $result_clean : 'Ошибка'));

                        // Ищем в тексте координаты
                        $levelTextClean = $purifier->purify($levelText);
                        preg_match_all('#(.*?)[\s:,;\.](-?[1-8]?\d(?:\.\d{1,6})?|90(?:\.0{1,6})?)[,]?\s+(-?(?:1[0-7]|[1-9])?\d(?:\.\d{1,6})?|180(?:\.0{1,6})?)#', $levelTextClean, $matches, PREG_SET_ORDER);
                        foreach($matches as $match)
                        {
                            $text = $match[0];
                            $lat = $match[2];
                            $lon = $match[3];

                            apiRequestJSON("sendLocation", array('chat_id' => $chat_id, "latitude" => $lat, "longitude" => $lon));
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => $text));
                        }
                    }

                break;
                case '/encrypt':
                    $result = encrypt($args[0], 'Cjhjrnsczxj,tpmzyd;jgeceyekb,fyfy');
                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Зашифрованный пароль: $result"));
                break;
                case '/hints':
                    $hints = getHints($settings['cookies'],$settings["game_domain"],$settings["game_id"]);
                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "parse_mode" => 'Markdown', "text" => $hints));
                break;
                case '/admin':
                    if($message['from']['username'] == 'skrashevich')
                    {
                        switch($args[0])
                        {
                            case 'add':
                                $settings_all['admins'][] = $args[1];
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Администратор $args[1] добавлен"));
                            break;
                            case 'print':
                                $result = '';
                                foreach($settings_all['admins'] as $admin)
                                {
                                    $result .= "@$admin\n";
                                }
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Список администраторов бота:\n$result"));
                            break;
                            case 'delete':
                                unset($settings_all['admins'][array_search($args[1],$settings_all['admins'])]);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Администратор $args[1] удален"));
                            break;
                        }
                    }
                break;
            }
        }

        if(in_array($ch,array("&","#")))
        {
            if($chat_id!=$settings["game_chat"])
            {
                $result = "В данном чате бот недоступен";
            } else 
            {
                $code=substr($text,1);

                // Пробиваем в движок все до символов //
                list($code,$comment) = explode('//', $code, 2);
                if(!$settings["cookies"])
                    $cookies = auth($settings["game_domain"], $settings["game_login"], $settings["game_pass"]);
                else {
                    $cookies = $settings["cookies"];
                }
                if($cookies===false)
                {
                    $result = "Не проходит авторизация на игровом движке";
                } else {
                    $result = sendCode($cookies,$settings["game_domain"],$settings["game_id"],$code);
                }
            }
            if(!$result)
                $result = "Ошибка";
            apiRequest("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
        }
        $settings_all[md5($chat_id)] = $settings;
    }
}

file_put_contents('config', json_encode($settings_all));