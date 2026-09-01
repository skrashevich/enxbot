<?php
// E_STRICT удалён как уровень ошибок и объявлен deprecated в PHP 8.4
error_reporting(E_ALL & ~E_NOTICE);

include('config.php');
include('db.php');
include('functions.php');

// Если запустили не из консоли, выходим
if (php_sapi_name() != 'cli') {
  die('Скрипт должен быть запущен из консоли');
}

$pid = getmypid();
$pidfile = BOT_USERNAME.'.pid';
file_put_contents($pidfile, $pid);

// Последний известный уровень по чатам, чтобы отследить АП мимо бота
$levels = Array();

while(true)
{
    $sql = "SELECT timers.*,games.chat_id,games.cookies,games.game_domain,games.game_id,games.cookies,games.infochannel
            FROM timers, games
            WHERE games.last_level_id=timers.level_id
            AND games.status>0
            AND games.chat_id = timers.chat_id
            AND timers.game_id = games.game_id
            AND timers.time <= ".time();
    $sqlresult = db_query($db, $sql);
    while($timer = db_fetch_assoc($sqlresult))
    {
        $secs = time()-$timer['time'];
        if($secs >= 2)
        {
            switch($timer['type'])
            {
                case 1:
                    $array = getHints($timer['cookies'],$timer["game_domain"],$timer["game_id"], true);
                    $hintText = isset($array['hints'][$timer['hint']]['text']) ? $array['hints'][$timer['hint']]['text'] : 'текст подсказки не получен';
                    $hints = '*'.$timer['hint'].':* '.$hintText;
                    apiRequestJSON("sendMessage", array('chat_id' => $timer['chat_id'], "parse_mode" => 'Markdown', "text" => $hints));
                    if($timer['infochannel'])
                    {
                        apiRequestJSON("sendMessage", array('chat_id' => $timer['infochannel'], "parse_mode" => 'Markdown', "text" => $hints));
                    }

                    $coords = getCoordsFromText($hints);
                    foreach($coords as $match)
                    {
                        $text = $match['text'];
                        $lat = $match['lat'];
                        $lon = $match['lon'];

                        $address = $match['address'];

                        apiRequestJSON("sendVenue", array('chat_id' => $timer['chat_id'], "latitude" => $lat, "longitude" => $lon, "title" => $text, "address" => $address));
                        apiRequestJSON("sendMessage", array('chat_id' => $timer['chat_id'], "parse_mode" => 'HTML', "text" => "$lat $lon"));

                        if($timer['infochannel'])
                        {
                            apiRequestJSON("sendVenue", array('chat_id' => $timer['infochannel'], "latitude" => $lat, "longitude" => $lon, "title" => $text, "address" => $address));
                            apiRequestJSON("sendMessage", array('chat_id' => $timer['infochannel'], "parse_mode" => 'HTML', "text" => "$lat $lon"));
                        }
                    }

                    // Сохраняем скриншот
                    screenshot(false, $timer['chat_id'], $timer['cookies'],$timer["game_domain"],$timer["game_id"],$timer['level_id']);
                break;
                case 2:
                    apiRequestJSON("sendMessage", array('chat_id' => $timer['chat_id'], "parse_mode" => 'Markdown', "text" => "*АП*"));
                    // Получаем текст нового уровня и выдаем его в чат
                    $levelText = getLevelText($timer['cookies'],$timer["game_domain"],$timer["game_id"]);

                    apiRequestJSON("sendMessage", array('chat_id' => $timer['chat_id'], "parse_mode" => 'HTML', "text" => $levelText ? $levelText : 'Ошибка получения текста уровня'));

                    if($timer['infochannel'])
                    {
                        apiRequestJSON("sendMessage", array('chat_id' => $timer['infochannel'], "parse_mode" => 'HTML', "text" => $levelText ? $levelText : 'Ошибка получения текста уровня'));
                    }

                    $coords = getCoordsFromText($levelText);
                    foreach($coords as $match)
                    {
                        $text = $match['text'];
                        $lat = $match['lat'];
                        $lon = $match['lon'];

                        $address = $match['address'];

                        apiRequestJSON("sendVenue", array('chat_id' => $timer['chat_id'], "latitude" => $lat, "longitude" => $lon, "title" => $text, "address" => $address));
                        apiRequestJSON("sendMessage", array('chat_id' => $timer['chat_id'], "parse_mode" => 'HTML', "text" => "$lat $lon"));

                        if($timer['infochannel'])
                        {
                            apiRequestJSON("sendVenue", array('chat_id' => $timer['infochannel'], "latitude" => $lat, "longitude" => $lon, "title" => $text, "address" => $address));
                            apiRequestJSON("sendMessage", array('chat_id' => $timer['infochannel'], "parse_mode" => 'HTML', "text" => "$lat $lon"));
                        }
                    }

                    // Обновляем LevelID в базе. При сбое движка getHints()
                    // возвращает -1 - такое значение выкинуло бы игру из
                    // выборки cron.php, поэтому его не записываем.
                    $array = getHints($timer['cookies'],$timer["game_domain"],$timer["game_id"]);
                    $levelId = $array['levelid'];
                    if($levelId > 0)
                    {
                        $sql = "UPDATE games SET last_level_id = ".intval($levelId)." WHERE chat_id = $timer[chat_id]";
                        db_query($db, $sql);
                    }

                    // Сохраняем скриншот
                    screenshot(false, $timer['chat_id'], $timer['cookies'],$timer["game_domain"],$timer["game_id"],$levelId);
                break;
                default:
                    apiRequestJSON("sendMessage", array('chat_id' => $timer['chat_id'], "parse_mode" => 'Markdown', "text" => "*Что-то обновилось*"));
                break;
            }
            $sql="DELETE FROM timers WHERE id=$timer[id]";
            db_query($db, $sql);
        }
    }

    // Смотрим в базе изменение ID уровня. Если поменялся, значит АП мимо бота и надо об этом сообщить.
    $sql="SELECT * FROM games WHERE status>0 AND last_level_id>0";
    $result = db_query($db, $sql);
    while($row = db_fetch_assoc($result))
    {
        $knownLevel = isset($levels[$row['chat_id']]) ? $levels[$row['chat_id']] : 0;
        if( $knownLevel && $row['last_level_id'] != $knownLevel )
        {
            apiRequestJSON("sendMessage", array('chat_id' => $row['chat_id'], "parse_mode" => 'Markdown', "text" => "*АП* (по движку)"));
            // Получаем текст нового уровня и выдаем его в чат
            $levelText = getLevelText($row['cookies'],$row["game_domain"],$row["game_id"]);

            apiRequestJSON("sendMessage", array('chat_id' => $row['chat_id'], "parse_mode" => 'HTML', "text" => $levelText ? $levelText : 'Ошибка получения текста уровня'));
            
            if($row['infochannel'])
            {
                apiRequestJSON("sendMessage", array('chat_id' => $row['infochannel'], "parse_mode" => 'HTML', "text" => $levelText ? $levelText : 'Ошибка получения текста уровня'));
            }

            $coords = getCoordsFromText($levelText);
            foreach($coords as $match)
            {
                $text = $match['text'];
                $lat = $match['lat'];
                $lon = $match['lon'];

                $address = $match['address'];

                apiRequestJSON("sendVenue", array('chat_id' => $row['chat_id'], "latitude" => $lat, "longitude" => $lon, "title" => $text, "address" => $address));
                apiRequestJSON("sendMessage", array('chat_id' => $row['chat_id'], "parse_mode" => 'HTML', "text" => "$lat $lon"));

                if($row['infochannel'])
                {
                    apiRequestJSON("sendVenue", array('chat_id' => $row['infochannel'], "latitude" => $lat, "longitude" => $lon, "title" => $text, "address" => $address));
                    apiRequestJSON("sendMessage", array('chat_id' => $row['infochannel'], "parse_mode" => 'HTML', "text" => "$lat $lon"));
                }
            }
            screenshot(false, $row['chat_id'], $row['cookies'],$row["game_domain"],$row["game_id"],$row['last_level_id']);
        }
        $levels[$row['chat_id']]=$row['last_level_id'];
    }
    sleep(1);
}
