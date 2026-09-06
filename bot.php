<?php
// E_STRICT удалён как уровень ошибок и объявлен deprecated в PHP 8.4
error_reporting(E_ALL & ~E_NOTICE);

include('config.php');
include('db.php');
include('functions.php');

/* config.php :
define('BOT_TOKEN', 'токен');
define('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/');
define('ENCRYPTION_KEY', 'ключ');
define('BOT_USERNAME', 'имя бота без собаки');
define('ADMIN_USERNAME', 'юзернейм главного администратора без собаки');
define('PHANTOMJS', '/usr/local/bin/phantomjs'); // Путь до бинарника phantomjs
define('PUBLIC_URL', 'https://bot.example.com'); // Только для ссылок на дополнительные веб-страницы
$sqlite_path = __DIR__.'/data/enxbot.sqlite'; // Файл БД; каталог создаётся автоматически
*/


$helptext = "Это бот для игры Encounter
Основная цель бота: пробитие кодов и оптимизация взаимодействия с движком.

*Настройка бота* (только администраторы чата и бота):
Добавьте бота в игровой чат и последовательно введите:
/game domain _домен_ - задать домен, например _moscow.en.cx_
/game login _логин_ - задать логин движка
/game pass _пароль_ - задать пароль движка в зашифрованном виде (см. /encrypt)
/game id _id_ - ID игры из адресной строки, например _12345_
/game auth - авторизоваться на движке
/game start - старт бота

*Управление игрой* (только администраторы):
/game test - проверить подключение к игре
/game print - вывод настроек
/game url - ссылка на игру в движке
/game chatid - идентификатор текущего чата
/game stop - остановка бота
/game restart - сбросить игру, сохранив настройки чата
/game delete - удалить игру и все данные чата
/game infochannel _@канал_ - канал для дублирования событий
/game shtab _-id_ - штабной чат: команды из него работают в контексте игры
/game codes on|off|locon|locoff - режим приёма кодов
/game codes print|stat|send|map - журнал, статистика, досылка, карта точек

*Игровой процесс*:
/level - текст текущего уровня; координаты из него уходят отдельной локацией
/hints - подсказки на уровне
/schema - схема дохода
/sectors - сводка по секторам уровня
/messages - сообщения организатора
/coords _координаты_ - разобрать координаты и прислать локацию
/screenshot - сделать скриншот движка
/getscreens _уровень_|_all_ - архив скриншотов за уровень
/keyboard - игровая клавиатура; можно перечислить свои команды через запятую
/settings - настройки чата на кнопках (менять могут только администраторы)

*Метки и бонусы*:
!нко, /нко, /ohl - незакрытые метки
!всеко, /всеко, /allhl - все метки
!зко, /зко, /chl - закрытые метки с кодами
!нбко, !бко, /obhl - незакрытые бонусы
!всебко, /allbhl - все бонусы
!збко, /cbhl - закрытые бонусы

*Точки на местности*:
/setpoint _координаты_ - поставить точку (штаб и администраторы)
/listpoint - точки уровня со статусами
/closepoint _[координаты]_ - закрыть точку; без аргумента - ближайшую открытую
Присланная в чат геометка (venue) тоже попадает в список точек.

*Пробитие кодов:*
Коды пробивать с префиксом & либо # либо \$ или ;
Например: _&en123_
После кода можно ввести комментарий: _&en123//3 этаж_
В движок пойдёт всё до символов //, в данном случае en123.
Бонусные коды присылаются так же: бот сам определит, что это бонус.
?_номер_ - отметить метку найденной, когда код ещё не взят.

*Прочее*:
/encrypt _пароль_ - только в личке боту: получить зашифрованный пароль
/help или /start - эта справка

Бот уведомляет о подсказках и автопереходе за 15 и 5 минут и в момент события.
В эти же моменты сохраняется скриншот движка.

Автор бота: @skrashevich <svk>";

function handleUpdate($update)
{
global $db, $helptext;

if (!is_array($update)) {
    return;
}

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

        // Команды из штабного чата работают в контексте боевой игры.
        // Собственная строка чата приоритетнее привязки как штаба.
        $sql = "SELECT * FROM games WHERE chat_id = $chat_id OR shtab_id = '".db_escape($db, $chat_id)."' ORDER BY (chat_id = $chat_id) DESC LIMIT 1";
        $result = db_query($db, $sql);
        if(!$result)
        {
            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => 'Ошибка подключения к БД'));
            die();
        }
        if(db_num_rows($result)===0)
        {
            $sql = "INSERT INTO games (chat_id) VALUES (".intval($chat_id).")";
            db_query($db, $sql);
            $sql = "SELECT * FROM games WHERE chat_id = $chat_id";
            $result = db_query($db, $sql);
        }
        $settings = db_fetch_assoc($result);
        if(!$settings)
        {
            $settings = Array();
        }
        $settings += Array('chat_id' => $chat_id, 'game_id' => 0, 'status' => 0, 'cookies' => '', 'game_domain' => '', 'game_login' => '', 'game_pass' => '', 'last_level_id' => 0, 'infochannel' => '', 'shtab_id' => '');
        $settings['admins'] = botAdmins();
        $from_id = isset($message['from']['id']) ? $message['from']['id'] : 0;
        // Чат, которому принадлежит игра. Отличается от $chat_id, когда команда
        // пришла из штабного чата или из лички игрока.
        $game_chat_id = intval($settings['chat_id']);

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
            $command = mb_strtolower($command);
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

            // Настройки могли смениться на игру другого чата (личка, штаб)
            $game_chat_id = intval($settings['chat_id']);

            switch($command)
            {
                case '/help':
                case '/start':
                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "parse_mode" => 'Markdown', "text" => $helptext));
                break;
                case '/game':
                    // Подкоманды, меняющие настройки игры или удаляющие данные,
                    // доступны только администраторам бота и администраторам чата.
                    $managing = in_array($args[0], array('domain', 'id', 'login', 'pass', 'auth', 'start', 'stop', 'delete', 'restart', 'infochannel', 'shtab'), true)
                        || ($args[0] === 'codes' && in_array($args[1], array('on', 'off', 'locon', 'locoff', 'send'), true));

                    if($managing && !canManageGame($chat_id, $from_id, $from_username, $settings['admins']))
                    {
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Команда доступна только администраторам чата и администраторам бота"));
                        break;
                    }

                    switch($args[0])
                    {
                        case 'domain':
                            if($settings['status']==0)
                            {
                                // Домен сохраняется как есть. Раньше подставлялся
                                // префикс 'm.' (легаси мобильного сайта Encounter):
                                // encx-биндинги ходят на обычный домен, а на
                                // доменах за wildcard-сертификатом *.en.cx
                                // хост m.<домен> двухуровневый и рвёт TLS.
                                $sql = "UPDATE games SET game_domain = '".db_escape($db, $args[1])."' WHERE chat_id = $game_chat_id";
                                db_query($db, $sql);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Установлен домен $args[1]"));
                            } else {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Невозможно поменять домен в процессе игры. Остановите бота командой /game stop"));
                            }
                        break;
                        case 'id':
                            if($settings['game_id']==0)
                            {
                                $sql = "UPDATE games SET game_id = ".intval($args[1])." WHERE chat_id = $game_chat_id";
                                db_query($db, $sql);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Установлен ID игры $args[1]"));
                            } else {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Невозможно поменять ID игры после создания. Воспользуйтесь командой /game delete для удаления прошлой игры в данном чате."));
                            }
                        break;
                        case 'login':
                            $sql = "UPDATE games SET game_login= '".db_escape($db, $args[1])."' WHERE chat_id = $game_chat_id";
                            db_query($db, $sql);
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
                            $sql = "UPDATE games SET game_pass = '".db_escape($db, $clear_pass)."' WHERE chat_id = $game_chat_id";
                            db_query($db, $sql);
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Установлен пароль"));
                        break;
                        case 'auth':
                            $cookies = auth($settings["game_domain"], $settings["game_login"], $settings["game_pass"]);
                            $settings['cookies'] = $cookies;
                            if($cookies===false)
                            {
                                $result = "Не проходит авторизация на игровом движке";
                            } else {
                                // После логина заявляем участие в игре (кнопка
                                // "Вход в игру" на сайте): без этого движок не
                                // отдаёт страницу уровня и /game test падает.
                                if(intval($settings['game_id']) > 0)
                                {
                                    try {
                                        $entered = encxEnterGame($cookies, $settings["game_domain"], $settings["game_id"]);
                                        if(is_string($entered) && $entered !== '')
                                        {
                                            $cookies = $entered;
                                        }
                                    } catch (Throwable $e) {
                                        error_log('Encounter enterGame failed: '.$e->getMessage());
                                    }
                                }
                                $result = "Авторизация успешно пройдена";
                                $sql = "UPDATE games SET cookies = '".db_escape($db, $cookies)."' WHERE chat_id = $game_chat_id";
                                db_query($db, $sql);
                            }
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                        break;
                        case 'test':
                            $result = testGame($settings['cookies'],$settings["game_domain"],$settings["game_id"]);
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result ? $result : 'Ошибка'));
                        break;
                        case 'start':
                            $sql = "UPDATE games SET status = 1 WHERE chat_id = $game_chat_id";
                            db_query($db, $sql);
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Игра привязана к чату"));
                        break;
                        case 'stop':
                            $sql = "UPDATE games SET status = 0 WHERE chat_id = $game_chat_id";
                            db_query($db, $sql);
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Бот остановлен"));
                        break;
                        case 'delete':
                            // Удаляем всё, что связано с чатом, а не только строку игры
                            foreach(array('games', 'timers', 'codes', 'codeslog', 'locations', 'settings', 'messages', 'coords') as $table)
                            {
                                db_query($db, "DELETE FROM $table WHERE chat_id = $game_chat_id");
                            }
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Настройки игры и все данные чата удалены"));
                        break;
                        case 'restart':
                            // Игру начинаем заново, но привязку чата и его настройки сохраняем
                            foreach(array('timers', 'codes', 'codeslog', 'locations', 'messages', 'coords') as $table)
                            {
                                db_query($db, "DELETE FROM $table WHERE chat_id = $game_chat_id");
                            }
                            db_query($db, "UPDATE games SET last_level_id = 0, status = 0 WHERE chat_id = $game_chat_id");
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Игра сброшена. Настройки чата сохранены, запустите бота командой /game start"));
                        break;
                        case 'print':
                            $text = "Домен: $settings[game_domain]\nИгра $settings[game_id]\nСтатус $settings[status]\nЛогин $settings[game_login]\nПароль ".($settings['game_pass'] ? 'задан' : 'не задан')
                                ."\nИнфоканал: ".($settings['infochannel'] ? $settings['infochannel'] : 'не задан')
                                ."\nШтабной чат: ".($settings['shtab_id'] ? $settings['shtab_id'] : 'не задан');
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => $text));
                        break;
                        case 'url':
                            if(empty($settings['game_domain']) || empty($settings['game_id']))
                            {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Домен или ID игры не заданы"));
                                break;
                            }
                            $gameurl = 'https://'.$settings['game_domain'].'/gameengines/encounter/play/'.intval($settings['game_id']);
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $gameurl));
                        break;
                        case 'chatid':
                            apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "ID этого чата: $chat_id"));
                        break;
                        case 'shtab':
                            $shtab = db_escape($db, $args[1]);
                            if(in_array(mb_substr($args[1],0,1),array('-', '@')))
                            {
                                $sql = "UPDATE games SET shtab_id='$shtab' WHERE chat_id = $game_chat_id";
                                db_query($db, $sql);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => "Установлен ID штабного чата $shtab"));
                            } else
                            {
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => "Неверный идентификатор штабного чата"));
                            }
                        break;
                        case 'infochannel':
                            $infochannel = db_escape($db, $args[1]);
		                    if(in_array(mb_substr($infochannel,0,1),array('-', '@')))
                            {
                                $sql = "UPDATE games SET infochannel='$infochannel' WHERE chat_id = $game_chat_id";
                                db_query($db, $sql);
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
                                        $sql = "UPDATE games SET status = 1 WHERE chat_id = $game_chat_id";
                                        db_query($db, $sql);
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
                                        $sql = "UPDATE games SET status = 2 WHERE chat_id = $game_chat_id";
                                        db_query($db, $sql);
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
                                        $sql = "UPDATE games SET status = 3 WHERE chat_id = $game_chat_id";
                                        db_query($db, $sql);
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
                                        $sql = "UPDATE games SET status = 4 WHERE chat_id = $game_chat_id";
                                        db_query($db, $sql);
                                        $result = "Прием кодов возможен только с локацией, коды не бьются в движок";
                                    }
                                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                                break;
                                case 'print':
                                    $result = '';
                                    $sql = "SELECT * FROM codeslog WHERE chat_id = $game_chat_id AND level = $settings[last_level_id]";
                                    $sqlresult = db_query($db, $sql);
                                    while($row = db_fetch_assoc($sqlresult))
                                    {
                                        $result .= "$row[code] - $row[comment] _($row[sender])_\n";
                                    }
                                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "parse_mode" => 'Markdown', "text" => $result));
                                break;
                                case 'send':
                                    $sql = "SELECT * FROM codeslog WHERE chat_id = $game_chat_id AND level = $settings[last_level_id]";
                                    $sqlresult = db_query($db, $sql);
                                    while($row = db_fetch_assoc($sqlresult))
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
                                    $sqlresult = db_query($db, $sql);
                                    while($row = db_fetch_assoc($sqlresult))
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
                                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => "Доступные команды: on, off, locon, locoff, stat, print, send, map"));
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
                    $previousSettings = intval(get_setting('last_settings_message_id', $chat_id));
                    if($previousSettings > 0)
                    {
                        // Старое меню оставлять нельзя: его кнопки уже не отражают состояние
                        apiRequestJSON("deleteMessage", array('chat_id' => $chat_id, 'message_id' => $previousSettings));
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

                        sendChatList($chat_id, 'level', $levelText ? $levelText : 'Ошибка', 'HTML');

                        // Ищем в тексте координаты
                        publishCoords($levelText, $chat_id);
                    }

                break;
                case '/coords':
                    if(publishCoords(implode(' ', $args), $chat_id) === 0)
                    {
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Не удалось разобрать координаты"));
                    }
                break;
                case '/setpoint':
                    // Ставить точки может штаб или администратор
                    if(!canManageGame($chat_id, $from_id, $from_username, $settings['admins']) && $chat_id != $settings['shtab_id'])
                    {
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Ставить точки могут только штаб и администраторы"));
                        break;
                    }

                    $points = getCoordsFromText(implode(' ', $args));
                    if(!$points)
                    {
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Не удалось разобрать координаты"));
                        break;
                    }

                    $sender_name = trim((isset($message['from']['first_name']) ? $message['from']['first_name'] : '').' '.(isset($message['from']['last_name']) ? $message['from']['last_name'] : ''));
                    foreach($points as $point)
                    {
                        $result = addPoint($game_chat_id, $settings['last_level_id'], $point['lat'], $point['lon'], $point['address'], $sender_name, $from_username, 2);
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
                    }
                break;
                case '/listpoint':
                case '/listpoints':
                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => listPoints($game_chat_id, $settings['last_level_id'])));
                break;
                case '/closepoint':
                    $points = getCoordsFromText(implode(' ', $args));
                    $point = $points ? $points[0] : null;
                    $result = closePoint(
                        $game_chat_id,
                        $settings['last_level_id'],
                        $point ? $point['lat'] : null,
                        $point ? $point['lon'] : null
                    );
                    apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
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

                        sendChatList($chat_id, 'sectors', $sectors['text'] ? $sectors['text'] : 'Ошибка', 'HTML');
                    }
                break;
                case '!нко':
                case '/нко':
                case '/ohl':
                case '!всеко':
                case '/всеко':
                case '/allhl':
                case '!зко':
                case '/зко':
                case '/chl':
                case '!нбко':
                case '/нбко':
                case '/obhl':
                case '!бко':
                case '!всебко':
                case '/всебко':
                case '/allbhl':
                case '!збко':
                case '/збко':
                case '/cbhl':
                    if(!$settings['status'])
                    {
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Нет активной игры"));
                        break;
                    }

                    // Тип списка: ключ настройки, режим отбора, заголовок и источник данных
                    $lists = Array(
                        '!нко'     => Array('ohl',    'open',   'Незакрытые метки:',   'sectors'),
                        '!всеко'   => Array('allhl',  'all',    'Все метки:',          'sectors'),
                        '!зко'     => Array('chl',    'closed', 'Закрытые метки:',     'sectors'),
                        '!нбко'    => Array('obhl',   'open',   'Незакрытые бонусы:',  'bonuses'),
                        '!всебко'  => Array('allbhl', 'all',    'Все бонусы:',         'bonuses'),
                        '!збко'    => Array('cbhl',   'closed', 'Закрытые бонусы:',    'bonuses'),
                    );
                    $aliases = Array(
                        '!нко' => '!нко', '/нко' => '!нко', '/ohl' => '!нко',
                        '!всеко' => '!всеко', '/всеко' => '!всеко', '/allhl' => '!всеко',
                        '!зко' => '!зко', '/зко' => '!зко', '/chl' => '!зко',
                        '!нбко' => '!нбко', '/нбко' => '!нбко', '/obhl' => '!нбко', '!бко' => '!нбко',
                        '!всебко' => '!всебко', '/всебко' => '!всебко', '/allbhl' => '!всебко',
                        '!збко' => '!збко', '/збко' => '!збко', '/cbhl' => '!збко',
                    );

                    list($listKey, $listMode, $listTitle, $listSource) = $lists[$aliases[$command]];

                    if($listSource === 'bonuses')
                    {
                        $bonuses = getBonuses($settings['cookies'],$settings["game_domain"],$settings["game_id"]);
                        $items = $bonuses['bonuses'];
                        if(!$items)
                        {
                            // Сообщение об отсутствии бонусов - тоже ответ этого типа списка,
                            // поэтому оно участвует в самоочистке чата наравне с остальными
                            sendChatList($chat_id, $listKey, $bonuses['text']);
                            break;
                        }
                    } else
                    {
                        $sectors = getSectors($settings['cookies'],$settings["game_domain"],$settings["game_id"]);
                        $items = markFoundSectors($sectors['sectors'], $game_chat_id, $settings['last_level_id']);
                    }

                    sendChatList($chat_id, $listKey, formatCodeList($items, $listMode, $listTitle));
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
                    // В группе шифртекст увидели бы все, а он равносилен паролю
                    if($chat_id < 0)
                    {
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Команду /encrypt можно выполнять только в личной переписке с ботом"));
                        break;
                    }
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
                        sendChatList($chat_id, 'hints', $array['result']);
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
                                $sql="INSERT INTO admins (admin_username) VALUES ('".db_escape($db, $args[1])."')";
                                db_query($db, $sql);
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
                                $sql="DELETE FROM admins WHERE admin_username = '".db_escape($db, $args[1])."'";
                                db_query($db, $sql);
                                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => "Администратор $args[1] удален"));
                            break;
                            case 'daemon':
                                // Тот же файл, что пишет daemon.php: иначе статус и остановка
                                // службы смотрели бы не туда (в контейнере - всегда мимо)
                                $pidfile = getenv('ENXBOT_PID_FILE') ?: BOT_USERNAME.'.pid';
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

        // "?N" - метка найдена в поле, но код ещё не взят
        if($ch === '?' && preg_match('#^\?(\d+)$#', trim($text), $findMatch))
        {
            $result = markSectorFound($game_chat_id, $settings['last_level_id'], $findMatch[1]);
            apiRequest("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $result));
        }
        else if(
            (in_array($ch,array('&', ';', '$', '#', '?')) && strlen($text)>1) // если начинается с префикса
            ||
            ( preg_match('#^[en1234567890]{3,}#', $text) && get_setting('noprefix', $chat_id) == 'true' ) // Соответствует регулярке на стандартный код и включена опция безпрефиксного приема стандартных кодов
            ||
            ( preg_match('#^\d{3,}$#', trim($text)) && get_setting('megadzr', $chat_id) == 'true' ) // Режим, в котором кодом считается любое число
        )
        {
            $sender = trim((isset($message['from']['first_name']) ? $message['from']['first_name'] : '').' '.(isset($message['from']['last_name']) ? $message['from']['last_name'] : ''));

            // Костыль: эмулируем префикс для безпрефиксного ввода кодов
            if( !in_array($ch,array('&', ';', '$', '#', '?')) )
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
        db_query($db, $sql);
        $sql = "UPDATE codes SET chat_id = $chat_id WHERE chat_id = $old_chat";
        db_query($db, $sql);
        $sql = "UPDATE codeslog SET chat_id = $chat_id WHERE chat_id = $old_chat";
        db_query($db, $sql);
        $sql = "UPDATE locations SET chat_id = $chat_id WHERE chat_id = $old_chat";
        db_query($db, $sql);
        $sql = "UPDATE timers SET chat_id = $chat_id WHERE chat_id = $old_chat";
        db_query($db, $sql);
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
            $result = db_query($db, $sql);
            $settings = db_fetch_assoc($result);

            if(empty($settings['game_domain']))
            {
                // Если не было настроек, то удаляем строку вообще
                $sql = "DELETE FROM games WHERE chat_id = $chat_id";
            } else
            {
                // Если настройки были, то отключаем игру через status = 0
                $sql = "UPDATE games SET status = 0 WHERE chat_id = $chat_id";
            }

            db_query($db, $sql);
        }
    }

    if(isset($message['pinned_message']))
    {
        // Припинили сообщение. Кидаем его в инфоканал.
        $message_text = isset($message['pinned_message']['text']) ? $message['pinned_message']['text'] : '';
        $message_entities = isset($message['pinned_message']['entities']) ? $message['pinned_message']['entities'] : Array();
		$chat_id=$message['chat']['id'];

        $sql = "SELECT * FROM games WHERE chat_id = $chat_id";
        $result = db_query($db, $sql);
        $settings = db_fetch_assoc($result);

        if($message_text !== '' && !empty($settings['infochannel']) && $settings['status']>0
           && get_setting('pinnedtochannel', $chat_id) == 'true')
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
        $sender_username = isset($message['from']['username']) ? $message['from']['username'] : '';
        $sender_name = trim((isset($message['from']['first_name']) ? $message['from']['first_name'] : '').' '.(isset($message['from']['last_name']) ? $message['from']['last_name'] : ''));

        $venue = $message['venue'];

        $lat = floatval($venue['location']['latitude']);
        $lon = floatval($venue['location']['longitude']);
        // Заголовок нужен и для записи точки, и в исходном виде для разбора кода
        $title = isset($venue['title']) ? $venue['title'] : '';

        $sql = "SELECT * FROM games WHERE chat_id = $chat_id";
        $result = db_query($db, $sql);
        $settings = db_fetch_assoc($result);

        if($settings && $settings['last_level_id'] > 0) // Если у нас есть активный уровень
        {
            // Точка от поля попадает в тот же список, что и точки штаба
            addPoint($chat_id, $settings['last_level_id'], $lat, $lon, $title, $sender_name, $sender_username, 1);

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
    $settings['admins'] = botAdmins();

    // Если это вызов "игры"
    if(isset($cbq['game_short_name']))
    {
        $game_short_name = $cbq['game_short_name'];

        if($settings['status']) // Если есть активная игра с участием игрока
        {
            $return_url = mapUrl($settings['chat_id'], $settings['last_level_id']);
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
        $toggle = parseSettingsCallback($cbq['data']);

        if($toggle === false)
        {
            // Данные, которые бот не подписывал: устаревшая кнопка или подделка
            apiRequest("answerCallbackQuery",
                array(
                    'callback_query_id' => $callback_id,
                    "text" => "Кнопка устарела, откройте /settings заново",
                )
            );
        } else
        {
            $chat_id = $toggle['chat_id'];
            $cb_username = isset($cbq['from']['username']) ? $cbq['from']['username'] : '';

            // Управлять настройками может только админ
            if(canManageGame($chat_id, $from_id, $cb_username, $settings['admins']))
            {
                $currentValue = get_setting($toggle['name'], $chat_id);
                set_setting($toggle['name'], $currentValue == 'true' ? 'false' : 'true', $chat_id);

                apiRequest("answerCallbackQuery",
                    array(
                        'callback_query_id' => $callback_id,
                        "text" => "Готово",
                    )
                );

                // Обновляем сообщение с настройками на месте, не плодя новых
                $keyboard = Array('inline_keyboard' => getSettingsButtons($chat_id));
                apiRequestJSON("editMessageText", array('chat_id' => $chat_id, 'message_id' => intval(get_setting('last_settings_message_id', $chat_id)), "text" => "Настройки игры", 'reply_markup' => json_encode($keyboard)));
            } else
            {
                apiRequest("answerCallbackQuery",
                    array(
                        'callback_query_id' => $callback_id,
                        "text" => "Изменять настройки могут только администраторы чата и администраторы бота",
                    )
                );
            }
        }
    }
}
}

// Оставляем прямой вызов удобным для локальной диагностики. В контейнере
// обновления передаются в handleUpdate() процессом polling.php.
if (realpath(isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '') === __FILE__) {
    $content = file_get_contents("php://input");
    handleUpdate(json_decode($content, true));
}
