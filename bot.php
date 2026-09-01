<?php
// E_STRICT удалён как уровень ошибок и объявлен deprecated в PHP 8.4
error_reporting(E_ALL & ~E_NOTICE);

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
define('PHANTOMJS', '/usr/local/bin/phantomjs'); // Путь до бинарника phantomjs
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
/screenshot - сделать скриншот движка
/getscreens _уровень_|_all_ - получить архив со скриншотами движка за уровень.
/coords _координаты_ - создать карту и ссылки навигации на указанные координаты

/encrypt _пароль_ - в личку боту! получить зашифрованный пароль для установки в канале
/help или /start - помощь

*Игровой процесс*:
/level - отобразить текст текущего уровня.
\tБот попробует найти в тексте уровня координаты и выслать их в виде локации для упрощения построения маршрута.
/hints - отобразить подсказки на уровне
/schema - схема дохода
/sectors - отобразить сектора уровня
/messages - отобразить сообщения организатора
/keyboard - включение игровой клавиатуры. Опционально, можно в параметре через запятую указать набор своих команд для клавиатуры

/screenshot - сделать скриншот движка
/getscreens _уровень_|_all_ - получить архив со скриншотами движка за уровень.

*Пробитие кодов:*
Игровые коды пробивать с префиксами & либо # либо \$ или ;
Например: _&en123_
После кода можно ввести комментарий, например: _&en123//3 этаж_
В движок пойдет всё до символов //, в данном случае en123.

Бот уведомляет о подсказках и автопереходе за 5 и 15 минут,
а также непосредственно в момент наступления события.
В эти же моменты автоматически создается и сохраняется скриншот движка

";
if(PAYMENT_SUM>0) 
    $helptext .= 'Стоимость одной игры с ботом: '.PAYMENT_SUM.' рублей. При запуске бота в чате он предложит совершить оплату с помощью банковской карты. Сразу после успешной оплаты бот автоматически запустится.';
if(PAYMENT_SUM<0)
    $helptext .= 'В текущий момент бот работает в режиме ограниченного доступа. Для получения возможности работы с ботом обратитесь к @'.ADMIN_USERNAME;
if(PAYMENT_SUM==0)
    $helptext .= "В настоящее время бот работает в бесплатном режиме. Вы можете использовать его без оплаты.";

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
		// У пользователей без username Telegram поле не присылает
		$from_username = isset($message['from']['username']) ? $message['from']['username'] : '';

        $sql = "SELECT * FROM games WHERE chat_id = $chat_id";
        $result = mysqli_query($db, $sql);
        if(!$result)
        {
            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => 'Ошибка подключения к БД'));
            die();
        }
        if(mysqli_num_rows($result)===0)
        {
            $sql = "INSERT INTO games (chat_id) VALUES (".intval($chat_id).")";
            mysqli_query($db, $sql);
            $sql = "SELECT * FROM games WHERE chat_id = $chat_id";
            $result = mysqli_query($db, $sql);
        }
        $settings = mysqli_fetch_assoc($result);
        if(!$settings)
        {
            $settings = Array();
        }
        $settings += Array('chat_id' => $chat_id, 'game_id' => 0, 'status' => 0, 'payment' => 0, 'cookies' => '', 'game_domain' => '', 'game_login' => '', 'game_pass' => '', 'last_level_id' => 0, 'infochannel' => '');
        $settings['admins'] = Array();

        $sql = "SELECT * FROM admins";
        $result = mysqli_query($db, $sql);
        while($row = mysqli_fetch_assoc($result))
        {
            $settings['admins'][]=$row['admin_username'];
        }

        // Проверяем первый символ
		$ch=mb_substr($text,0,1);
		if(in_array($ch,array("/", "&", "#", "!")))
        {
            $parts = explode(' ', $text, 2);
            $command = $parts[0];
            $args = explode(' ', isset($parts[1]) ? $parts[1] : '');
            // Гарантируем наличие $args[0..2], чтобы обращения к аргументам
            // не приводили к Warning "Undefined array key" на PHP 8
            $args = array_pad($args, 3, '');

            // for commands like /level@enxbot
            $command = str_replace('@'.BOT_USERNAME, '', $command);

            //Приводим к нижнему регистру
            $command = strtolower($command);
            //
            // Если сообщение пришло в личку, то ищем, есть ли игрок в каком-либо действующем игровом чате
            // если есть, то подгружаем настройки игры этого чата. 
            //
            if($chat_id > 0)
            {
                $tmpsettings = gameSettingsbyUser($chat_id);
                if(count($tmpsettings))
                {
                    // Список администраторов не относится к конкретной игре, сохраняем его
                    $tmpsettings['admins'] = $settings['admins'];
                    $settings = $tmpsettings;
                }
            }

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
                                $sql = "UPDATE games SET game_domain = 'm.".mysqli_escape_string($db, $args[1])."' WHERE chat_id = $chat_id";
                                mysqli_query($db, $sql);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Установлен домен $args[1]"));
                            } else {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Невозможно поменять домен в процессе игры. Остановите бота командой /game stop"));
                            }
                        break;
                        case 'id':
                            if($settings['game_id']==0)
                            {
                                $sql = "UPDATE games SET game_id = ".intval($args[1])." WHERE chat_id = $chat_id";
                                mysqli_query($db, $sql);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Установлен ID игры $args[1]"));
                            } else {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Невозможно поменять ID игры после создания. Воспользуйтесь командой /game delete для удаления прошлой игры в данном чате."));
                            }
                        break;
                        case 'login':
                            $sql = "UPDATE games SET game_login= '".mysqli_escape_string($db, $args[1])."' WHERE chat_id = $chat_id";
                            mysqli_query($db, $sql);
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Установлен игровой логин $args[1]"));
                        break;
                        case 'pass':
                            $clear_pass = decrypt($args[1], ENCRYPTION_KEY);
                            if($clear_pass === false)
                            {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Не удалось расшифровать пароль. Получите его заново командой /encrypt в личке боту"));
                                break;
                            }
                            $clear_pass = trim($clear_pass);
                            $sql = "UPDATE games SET game_pass = '".mysqli_escape_string($db, $clear_pass)."' WHERE chat_id = $chat_id";
                            mysqli_query($db, $sql);
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
                                $sql = "UPDATE games SET cookies = '".mysqli_escape_string($db, $cookies)."' WHERE chat_id = $chat_id";
                                mysqli_query($db, $sql);
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
                                if( (in_array($from_username, $settings['admins']) && PAYMENT_SUM==-1) || ( $settings['payment'] >= PAYMENT_SUM && PAYMENT_SUM >=0) )
                                {
                                    $sql = "UPDATE games SET status = 1 WHERE chat_id = $chat_id";
                                    mysqli_query($db, $sql);
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
                            if( (in_array($from_username, $settings['admins']) && PAYMENT_SUM==-1) || ( $settings['payment'] >= PAYMENT_SUM && PAYMENT_SUM >=0) )
                            {
                                $sql = "UPDATE games SET status = 0 WHERE chat_id = $chat_id";
                                mysqli_query($db, $sql);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Бот остановлен"));
                            } else 
                            {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "У вас недостаточно прав для остановки бота"));
                            }
                        break;
                        case 'delete':
                            if( (in_array($from_username, $settings['admins']) && PAYMENT_SUM==-1) || ( $settings['payment'] >= PAYMENT_SUM && PAYMENT_SUM >=0) )
                            {
                                $sql="DELETE FROM games WHERE chat_id = $chat_id";
                                mysqli_query($db, $sql);
                                $sql="DELETE FROM timers WHERE chat_id = $chat_id";
                                mysqli_query($db, $sql);
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
                        case 'infochannel':
                            $infochannel = mysqli_escape_string($db, $args[1]);
		                    if(in_array(mb_substr($infochannel,0,1),array('-', '@')))
                            {
                                $sql = "UPDATE games SET infochannel='$infochannel' WHERE chat_id = $chat_id";
                                mysqli_query($db, $sql);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => "Установлен ID инфоканала $infochannel"));
                            } else
                            {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => "Неверный идентификатор инфоканала"));
                            }
                        break;
                        case 'codes':
                            switch($args[1])
                            {
                                case 'on':
                                    if(!$settings['status'])
                                    {
                                        $result = "Нет активной игры";
                                    } else 
                                    {
                                        $sql = "UPDATE games SET status = 1 WHERE chat_id = $chat_id";
                                        mysqli_query($db, $sql);
                                        $result = "Стандартный прием кодов включен";
                                    }
                                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                                break;
                                case 'off':
                                    if(!$settings['status'])
                                    {
                                        $result = "Нет активной игры";
                                    } else 
                                    {
                                        $sql = "UPDATE games SET status = 2 WHERE chat_id = $chat_id";
                                        mysqli_query($db, $sql);
                                        $result = "Прием кодов выключен";
                                    }
                                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                                break;
                                case 'locon':
                                    if(!$settings['status'])
                                    {
                                        $result = "Нет активной игры";
                                    } else 
                                    {
                                        $sql = "UPDATE games SET status = 3 WHERE chat_id = $chat_id";
                                        mysqli_query($db, $sql);
                                        $result = "Прием кодов возможен только с локацией";
                                    }
                                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                                break;
                                case 'locoff':
                                    if(!$settings['status'])
                                    {
                                        $result = "Нет активной игры";
                                    } else 
                                    {
                                        $sql = "UPDATE games SET status = 4 WHERE chat_id = $chat_id";
                                        mysqli_query($db, $sql);
                                        $result = "Прием кодов возможен только с локацией, коды не бьются в движок";
                                    }
                                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                                break;
                                case 'print':
                                    $result = '';
                                    $sql = "SELECT * FROM codeslog WHERE chat_id = $chat_id AND level = $settings[last_level_id]";
                                    $sqlresult = mysqli_query($db, $sql);
                                    while($row = mysqli_fetch_assoc($sqlresult))
                                    {
                                        $result .= "$row[code] - $row[comment] _($row[sender])_\n";
                                    }
                                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "parse_mode" => 'Markdown', "text" => $result));
                                break;
                                case 'send':
                                    $sql = "SELECT * FROM codeslog WHERE chat_id = $chat_id AND level = $settings[last_level_id]";
                                    $sqlresult = mysqli_query($db, $sql);
                                    while($row = mysqli_fetch_assoc($sqlresult))
                                    {
                                        $result = sendCode($settings['cookies'],$settings["game_domain"],$settings["game_id"],$row['code']);
                                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => $row['code'].': '.$result['result']));
                                        usleep(rand(300000,2000000));
                                    }
                                break;
                                case 'stat':
                                    $result = "Статистика успешно пробитых кодов за игру:\n";
                                    $total = 0;
                                    $sql = "SELECT COUNT(id) as cnt, sender FROM codeslog WHERE chat_id=$settings[chat_id] GROUP BY sender ORDER BY cnt DESC";
                                    $sqlresult = mysqli_query($db, $sql);
                                    while($row = mysqli_fetch_assoc($sqlresult))
                                    {
                                        $result .="$row[sender] - $row[cnt]\n";
                                        $total += $row['cnt'];
                                    }
                                    $result .= "Всего пробито через бота: $total кодов";
                                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                                break;
                                case 'map':
                                    if(!defined('LOCATION_MAP_GAMENAME'))
                                    {
                                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => "Карта не настроена: не задана константа LOCATION_MAP_GAMENAME в config.php"));
                                        break;
                                    }
                                    $buttons = Array(Array(Array('text' => 'Открыть в новой вкладке', 'callback_game' => Array())));
                                    $keyboard = Array('inline_keyboard' => $buttons);
                                    apiRequestJSON("sendGame",
                                        array(
                                            'chat_id' => $chat_id,
                                            'game_short_name' => LOCATION_MAP_GAMENAME,
                                            'reply_markup' => json_encode($keyboard),
                                        )
                                    );
                                break;
                                default:
                                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => "Доступные команды: on, off, locon, locoff, stat, print"));
                                break;
                            }
                        break;
                    }
                break;
                case '/scheme':
                case '/schema':
                case '!схема':
                if(!$settings['status'])
                    {
                        $result = "Нет активной игры";
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                    } else 
                    {
                        $img = getScheme($settings['cookies'],$settings["game_domain"],$settings["game_id"]);

                        if($img === false)
                        {
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Схема не найдена"));
                        } else
                        {
                            apiRequestJSON("sendPhoto", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "photo" => $img));
                        }
                    }
                break;
                case '/settings':
                    if(get_setting('last_settings_message_id', $chat_id))
                    {
                        // Если уже было сообщение с настройками в этом чате, удаляем его
                        apiRequestJSON("editMessageText", array('chat_id' => $chat_id, 'message_id' => get_setting('last_settings_message_id', $chat_id), "text" => "Настройки игры"));
                    }
                    $buttons = getSettingsButtons($chat_id);
                    $keyboard = Array('inline_keyboard' => $buttons);
                    $result = apiRequestJSON("sendMessage", array('chat_id' => $chat_id, 'reply_markup' => json_encode($keyboard), "text" => "Настройки игры"));

                    if(isset($result['message_id']))
                    {
                        set_setting('last_settings_message_id', $result['message_id'], $chat_id);
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

                            apiRequestJSON("sendVenue", array('chat_id' => $settings['chat_id'], "latitude" => $lat, "longitude" => $lon, "title" => $text, "address" => $address));
                            apiRequestJSON("sendMessage", array('chat_id' => $settings['chat_id'], "parse_mode" => 'HTML', "text" => "$lat $lon"));
                        }
                    }

                break;
                case '/coords':
                    $coords = implode(' ', $args);

                    $coords = getCoordsFromText($coords);
                    foreach($coords as $match)
                    {
                        $text = $match['text'];
                        $lat = $match['lat'];
                        $lon = $match['lon'];
                        $address = $match['address'];
                        $links = $match['links'];

                        apiRequestJSON("sendVenue", array('chat_id' => $chat_id, "latitude" => $lat, "longitude" => $lon, "title" => $text, "address" => $address));
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "parse_mode" => 'HTML', "text" => "$lat $lon"));
                    }
                break;
                case '/screenshot':
                        screenshot(true, $settings['chat_id'], $settings['cookies'],$settings["game_domain"],$settings["game_id"],$settings['last_level_id']);
                break;
                case '/getscreens':
                    // Без каталога DirectoryIterator бросает UnexpectedValueException
                    if(!is_dir('screens'))
                    {
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Скриншотов пока нет"));
                        break;
                    }
                    $levelid = intval($args[0]);
                    if($levelid <= 0)
                    {
                        $levelid = $settings['last_level_id'];
                    }

                    $zip = new ZipArchive;
                    $archivename = 'screens/'.abs($settings['chat_id']).'.'.$levelid.'.zip';
                    $res = $zip->open($archivename, ZipArchive::CREATE);

                    if ($res !== true)
                    {
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Ошибка инициализации модуля архивации"));
                        break;
                    }

                    foreach (new DirectoryIterator('screens') as $fileInfo)
                    {
                        if($fileInfo->isDot()) continue;

                        $fname = $fileInfo->getFilename();
                        $tmp = array_pad(explode('.', $fname, 4), 4, '');

                        if($tmp[0] == $settings['chat_id'] && ( $levelid == $tmp[2] || $args[0] == 'all') )
                        {
                            $zip->addFile('screens/'.$fname);
                        }
                    }

                    $zip->close();

                    if(!file_exists($archivename))
                    {
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => "Создать архив не удалось"));
                        break;
                    }

                    apiRequestPOST("sendDocument", array('chat_id' => $chat_id, "document" => new CURLFile($archivename)));
                    unlink($archivename);
                break;
                case '/sectors':
                    if(!$settings['status'])
                    {
                        $result = "Нет активной игры";
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                    } else 
                    {
                        $sectors = getSectors($settings['cookies'],$settings["game_domain"],$settings["game_id"]);

                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "parse_mode" => 'HTML', "text" => $sectors['text'] ? $sectors['text'] : 'Ошибка'));
                    }
                break;
                case '!нко':
                case '/нко':
                case '/ohl':
                    if(!$settings['status'])
                    {
                        $result = "Нет активной игры";
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                    } else 
                    {
                        $result = "Незакрытые метки:\n";
                        $sectors = getSectors($settings['cookies'],$settings["game_domain"],$settings["game_id"]);
                        foreach($sectors['sectors'] as $num => $code)
                        {
                            if(!is_int($num))
                                continue; // не обрабатываем если это не метка кода

                            if($code['found'])
                            {
                                // Ничего не делаем, потому что нужны только незакрытые
                            } else
                            {
                                $result .= "$num\n";
                            }
                        }
                        if(get_setting('optimize_chat', $chat_id)=='true')
                        {
                            // Если уже было сообщение с настройками в этом чате, удаляем его
                            apiRequestJSON("editMessageText", array('chat_id' => $chat_id, 'message_id' => get_setting('last_ohl_message_id', $chat_id), "text" => "..."));
                        }
                        $result = apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "parse_mode" => 'Markdown', "text" => $result));
                        if(isset($result['message_id']))
                        {
                            set_setting('last_ohl_message_id', $result['message_id'], $chat_id);
                        }
                    }
                break;
                case '!всеко':
                case '/всеко':
                case '/allhl':
                    if(!$settings['status'])
                    {
                        $result = "Нет активной игры";
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                    } else 
                    {
                        $result = "Все метки:\n";
                        $sectors = getSectors($settings['cookies'],$settings["game_domain"],$settings["game_id"]);
                        foreach($sectors['sectors'] as $num => $code)
                        {
                            if(!is_int($num))
                                continue; // не обрабатываем если это не метка кода

                            if($code['found'])
                            {
                                $result .= "*$num:\t$code[code]*\n";
                            } else
                            {
                                $result .= "_{$num}_\n";
                            }
                        }
                        if(get_setting('optimize_chat', $chat_id)=='true')
                        {
                            // Если уже было сообщение с настройками в этом чате, удаляем его
                            apiRequestJSON("editMessageText", array('chat_id' => $chat_id, 'message_id' => get_setting('last_allhl_message_id', $chat_id), "text" => "..."));
                        }
                        $result = apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "parse_mode" => 'Markdown', "text" => $result));
                        if(isset($result['message_id']))
                        {
                            set_setting('last_allhl_message_id', $result['message_id'], $chat_id);
                        }
                    }
                break;
                case '!зко':
                case '/зко':
                case '/chl':
                    if(!$settings['status'])
                    {
                        $result = "Нет активной игры";
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                    } else 
                    {
                        $result = "Закрытые метки:\n";
                        $sectors = getSectors($settings['cookies'],$settings["game_domain"],$settings["game_id"]);
                        foreach($sectors['sectors'] as $num => $code)
                        {
                            if(!is_int($num))
                                continue; // не обрабатываем если это не метка кода

                            if(!$code['found'])
                            {
                                // Ничего не делаем, потому что нужны только закрытые
                            } else
                            {
                                $result .= "*$num:\t$code[code]*\n";
                            }
                        }
                        if(get_setting('optimize_chat', $chat_id)=='true')
                        {
                            // Если уже было сообщение с настройками в этом чате, удаляем его
                            apiRequestJSON("editMessageText", array('chat_id' => $chat_id, 'message_id' => get_setting('last_chl_message_id', $chat_id), "text" => "..."));
                        }
                        $result = apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "parse_mode" => 'Markdown', "text" => $result));
                        if(isset($result['message_id']))
                        {
                            set_setting('last_chl_message_id', $result['message_id'], $chat_id);
                        }
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
                    $text = $result === false
                        ? "Не удалось зашифровать пароль"
                        : "Зашифрованный пароль: $result";
                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $text));
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
                case '/keyboard':
                    switch($args[0])
                    {
                        case 'off':
                            $keyboard = Array('remove_keyboard' => true, 'selective' => true);
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, 'reply_markup' => json_encode($keyboard), "text" => "Готово"));
                        break;
                        case '':
                            $buttons = Array(Array('!зко', '!нко'), Array('/schema', '/hints'));
                            $keyboard = Array('keyboard' => $buttons, 'selective' => true, 'resize_keyboard' => true);
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, 'reply_markup' => json_encode($keyboard), "text" => "Готово"));
                        break;
                        default:
                            $buttons = Array();
                            // Команды идут по две в ряд; у нечётного списка вторая кнопка - выключение клавиатуры
                            $cmds = array_values(array_filter(array_map('trim', explode(',', implode(' ',$args))), 'strlen'));
                            for($i=0;$i<count($cmds);$i+=2)
                            {
                                $second = isset($cmds[$i+1]) && $cmds[$i+1] !== '' ? $cmds[$i+1] : '/keyboard off';
                                $buttons[] = Array($cmds[$i], $second);
                            }
                            $keyboard = Array('keyboard' => $buttons, 'selective' => true, 'resize_keyboard' => true);
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, 'reply_markup' => json_encode($keyboard), "text" => "Готово"));
                        break;
                    }
                break;
                case '/admin':
                    if($from_username == ADMIN_USERNAME)
                    {
                        switch($args[0])
                        {
                            case 'add':
                                $sql="INSERT INTO admins (admin_username) VALUES ('".mysqli_escape_string($db, $args[1])."')";
                                mysqli_query($db, $sql);
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
                                $sql="DELETE FROM admins WHERE admin_username = '".mysqli_escape_string($db, $args[1])."'";
                                mysqli_query($db, $sql);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Администратор $args[1] удален"));
                            break;
                            case 'daemon':
                                $pidfile = BOT_USERNAME.'.pid';
                                switch($args[1])
                                {
                                    case 'start':
                                        $cwd = posix_getcwd();
                                        $ret = exec("screen -d -m php $cwd/daemon.php");
                                        apiRequestJSON("sendChatAction", array('chat_id' => $chat_id, 'action' => 'typing'));
                                        sleep(2);
                                        $pid = readPid($pidfile);
                                        if($pid > 0)
                                        {
                                             apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Служба запущена. PID: $pid"));
                                        } else
                                        {
                                             apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Ошибка запуска службы: $ret"));
                                        }
                                    break;
                                    case 'stop':
                                        $pid = readPid($pidfile);
                                        if($pid > 0)
                                        {
                                            posix_kill($pid, 2); // SIGINT
                                            file_put_contents($pidfile, '');
                                            $ret = exec("ps ax | grep $pid | grep -v grep");
                                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Служба остановлена. $ret"));
                                        } else
                                        {
                                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Не найден PID процесса"));
                                        }
                                    break;
                                    case 'restart':
                                        apiRequestJSON("sendChatAction", array('chat_id' => $chat_id, 'action' => 'typing'));
                                        $pid = readPid($pidfile);
                                        if($pid > 0)
                                        {
                                            posix_kill($pid, 2); // SIGINT
                                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Служба остановлена"));
                                        } else
                                        {
                                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Не найден PID процесса"));
                                        }
                                        apiRequestJSON("sendChatAction", array('chat_id' => $chat_id, 'action' => 'typing'));
                                        sleep(2);
                                        $cwd = posix_getcwd();
                                        exec("screen -d -m php $cwd/daemon.php");
                                        sleep(2);
                                        $pid = readPid($pidfile);
                                        if($pid > 0)
                                        {
                                             apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Служба запущена. PID: $pid"));
                                        } else
                                        {
                                             apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Ошибка запуска службы"));
                                        }
                                    break;
                                    case 'status':
                                        $pid = readPid($pidfile);
                                        $ret = exec("ps ax | grep $pid | grep -v grep");
                                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "PID: $pid\n$ret"));
                                    break;
                                    case 'help':
                                        $adminhelp = "*Помощь администратора*
add _username_ - добавить администратора
delete _username_ - удалить администратора
print - вывести список администраторов
daemon start - запустить демона бота
daemon stop - остановить демона бота
daemon restart - перезапустить демона бота
daemon status - отобразить статус работы демона
help - эта справка";
                                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "parse_mode" => 'Markdown', "text" => $adminhelp));
                                    break;
                                    default:
                                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Доступные команды: start, stop, restart, status"));
                                    break;
                                }
                            break;
                        }
                    }
                break;
            }
        }

        if( 
            (in_array($ch,array('&', ';', '$', '#', '?')) && strlen($text)>1) // если начинается с префикса
            ||
            ( preg_match('#^[en1234567890]{3,}#', $text) && get_setting('noprefix', $chat_id) == 'true' ) // Соответствует регулярке на стандартный код и включена опция безпрефиксного приема стандартных кодов
        )
        {
            $sender = trim((isset($message['from']['first_name']) ? $message['from']['first_name'] : '').' '.(isset($message['from']['last_name']) ? $message['from']['last_name'] : ''));

            // Костыль: эмулируем префикс для безпрефиксного ввода кодов
            if( preg_match('#^[en1234567890]{3,}#', $text) && get_setting('noprefix', $chat_id) == 'true' )
                $text = '&'.$text;
            $result = parseCode($text, $chat_id, $sender);

            if(!$result)
                $result = "Ошибка";
            apiRequest("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "parse_mode" => 'Markdown', "text" => $result));
        }
    }

    if(isset($message['migrate_from_chat_id']))
    {
        // Кто-то обновил группу до супергруппы
        $message_id=$message['message_id'];
		$chat_id=$message['chat']['id'];
        $old_chat = $message['migrate_from_chat_id'];
        $sql = "UPDATE games SET chat_id = $chat_id WHERE chat_id = $old_chat";
        mysqli_query($db, $sql);
        $sql = "UPDATE codes SET chat_id = $chat_id WHERE chat_id = $old_chat";
        mysqli_query($db, $sql);
        $sql = "UPDATE codeslog SET chat_id = $chat_id WHERE chat_id = $old_chat";
        mysqli_query($db, $sql);
        $sql = "UPDATE locations SET chat_id = $chat_id WHERE chat_id = $old_chat";
        mysqli_query($db, $sql);
        $sql = "UPDATE timers SET chat_id = $chat_id WHERE chat_id = $old_chat";
        mysqli_query($db, $sql);
    }

    if(isset($message['left_chat_member']))
    {
        // Обрабатываем ситуацию, когда бота вышли из группы
        $kicked_username = $message['left_chat_member']['username'];
        $message_id=$message['message_id'];
		$chat_id=$message['chat']['id'];

        if($kicked_username == BOT_USERNAME)
        {
            // Кикнули не кого-то, а самого бота
            $sql = "SELECT * FROM games WHERE chat_id = $chat_id";
            $result = mysqli_query($db, $sql);
            $settings = mysqli_fetch_assoc($result);

            if(empty($settings['game_domain']))
            {
                // Если не было настроек, то удаляем строку вообще
                $sql = "DELETE FROM games WHERE chat_id = $chat_id";
            } else
            {
                // Если настройки были, то отключаем игру через status = 0
                $sql = "UPDATE games SET status = 0 WHERE chat_id = $chat_id";
            }

            mysqli_query($db, $sql);
        }
    }

    if(isset($message['pinned_message']))
    {
        // Припинили сообщение. Кидаем его в инфоканал.
        $message_text = isset($message['pinned_message']['text']) ? $message['pinned_message']['text'] : '';
        $message_entities = isset($message['pinned_message']['entities']) ? $message['pinned_message']['entities'] : Array();
		$chat_id=$message['chat']['id'];

        $sql = "SELECT * FROM games WHERE chat_id = $chat_id";
        $result = mysqli_query($db, $sql);
        $settings = mysqli_fetch_assoc($result);

        if($message_text !== '' && !empty($settings['infochannel']) && $settings['status']>0)
        {
            apiRequest("sendMessage", array('chat_id' => $settings['infochannel'], "text" => $message_text, 'entities' => $message_entities));
        }
    }

    if(isset($message['venue']))
    {
        // Если нам прислали "Venue" - подписанную геолоку через inline-запрос
        $chat_id=$message['chat']['id'];
        $sender_id = isset($message['from']['id']) ? $message['from']['id'] : 0;
        $message_id = $message['message_id'];
        $sender_username = mysqli_escape_string($db, isset($message['from']['username']) ? $message['from']['username'] : '');
        $sender_name = mysqli_escape_string($db, trim((isset($message['from']['first_name']) ? $message['from']['first_name'] : '').' '.(isset($message['from']['last_name']) ? $message['from']['last_name'] : '')));

        $venue = $message['venue'];

        $lat = floatval($venue['location']['latitude']);
        $lon = floatval($venue['location']['longitude']);
        $address = mysqli_escape_string($db, isset($venue['address']) ? $venue['address'] : '');
        // Заголовок нужен и для SQL, и в исходном виде для разбора кода
        $title = isset($venue['title']) ? $venue['title'] : '';
        $title_escaped = mysqli_escape_string($db, $title);

        $sql = "SELECT * FROM games WHERE chat_id = $chat_id";
        $result = mysqli_query($db, $sql);
        $settings = mysqli_fetch_assoc($result);

        if($settings && $settings['last_level_id'] > 0) // Если у нас есть активный уровень
        {
            $sql = "INSERT INTO locations (chat_id, time, sender_username, sender_name, lat, lon, title, level, type)
            VALUES ($chat_id, ".time().", '$sender_username', '$sender_name', $lat, $lon, '$title_escaped', $settings[last_level_id], 1)
            ";
            mysqli_query($db, $sql);

            if(in_array(mb_substr($title,0,1),array('&', ';', '$', '#', '?')) && strlen($title)>1)
            {
                $result = parseCode($title, $chat_id, $sender_name, $venue['location']);
            } else
            {
                $result = "Локация принята";
            }
            
            if(!$result)
                $result = "Ошибка";
        
            apiRequest("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
        } else
        {
            apiRequest("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Нет активной игры"));
        }
    }
}

 // Обрабатываем inline query
if(isset($update["inline_query"]))
{
    $inline = $update["inline_query"];
    $inline_id = $inline['id'];
    $from = $inline['from'];
    $from_id =  $from['id'];

    $lat = isset($inline['location']['latitude']) ? $inline['location']['latitude'] : null;
    $lon = isset($inline['location']['longitude']) ? $inline['location']['longitude'] : null;

    $query = isset($inline['query']) ? $inline['query'] : '';
    if($lat !== null && $lon !== null && strlen(trim($query))>1)
    {
        // В процессе написания
        apiRequest("answerInlineQuery",
         array(
             'inline_query_id' => $inline_id,
             "is_personal" => true,
             'results' => Array(
                 Array(
                     'type' =>'venue',
                     'id' => (string)round(microtime(true) * 1000),
                     'title' => $query,
                     'latitude' => $lat,
                     'longitude' => $lon,
                     'address' => geocoder($lat, $lon),
                    )
            )
        )
       );
    }
}

// Обрабатываем callback query
if(isset($update["callback_query"]))
{
    $cbq = $update["callback_query"];
    $callback_id = $cbq['id'];
    $from_id = $cbq['from']['id'];

    $settings = gameSettingsbyUser($from_id);
    $settings += Array('chat_id' => 0, 'status' => 0, 'last_level_id' => 0);
    $settings['admins'] = Array();

    $sql = "SELECT * FROM admins";
    $result = mysqli_query($db, $sql);
    while($row = mysqli_fetch_assoc($result))
    {
        $settings['admins'][]=$row['admin_username'];
    }

    // Если это вызов "игры"
    if(isset($cbq['game_short_name']))
    {
        $game_short_name = $cbq['game_short_name'];

        if($settings['status']) // Если есть активная игра с участием игрока
        {
            $return_url = dirname(WEBHOOK_URL)."/map.php?c=$settings[chat_id]&l=$settings[last_level_id]";
            apiRequest("answerCallbackQuery",
                array(
                    'callback_query_id' => $callback_id,
                    "url" => $return_url,
                )
            );
        } else
        {
            apiRequest("answerCallbackQuery",
                array(
                    'callback_query_id' => $callback_id,
                    "text" => "Не найдено активной игры с вашим участием.",
                    'show_alert' => true,
                )
            );
        }
    }

    // Если это нажатие на кнопку settings
    if(isset($cbq['data']))
    {
        $tmp = array_pad(explode(' ', $cbq['data']), 2, '');
        $command = $tmp[0];
        $chat_id = intval($tmp[1]);

        // Управлять настройками может только админ
        if(isset($cbq['from']['username']) && in_array($cbq['from']['username'], $settings['admins']))
        {
            switch($command)
            {
                case '/noprefix':
                    $currentValue = get_setting('noprefix', $chat_id);
                    set_setting('noprefix', $currentValue == 'true' ? 'false' : 'true', $chat_id);
                break;
                case '/nocomment':
                    $currentValue = get_setting('nocomment', $chat_id);
                    set_setting('nocomment', $currentValue == 'true' ? 'false' : 'true', $chat_id);
                break;
            }

            apiRequest("answerCallbackQuery",
                array(
                    'callback_query_id' => $callback_id,
                    "text" => "Выполняем...",
                )
            );

            // Редактируем предыдущее сообщение с настройками
            $buttons = getSettingsButtons($chat_id);
            $keyboard = Array('inline_keyboard' => $buttons);
            apiRequestJSON("editMessageText", array('chat_id' => $chat_id, 'message_id' => get_setting('last_settings_message_id', $chat_id), "text" => "Настройки игры", 'reply_markup' => json_encode($keyboard)));
        } else
        {
            apiRequest("answerCallbackQuery",
                array(
                    'callback_query_id' => $callback_id,
                    "text" => "Изменять настройки может только администратор бота",
                )
            );
        }
    }
}