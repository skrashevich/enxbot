<?php
error_reporting(E_ALL & ~(E_STRICT|E_NOTICE));

include('config.php');
include('db.php');
include('functions.php');

// Если запустили не из консоли, выходим
if (php_sapi_name() != 'cli') {
  die('Скрипт должен быть запущен из консоли');
}

while(true)
{
    $sql = "SELECT timers.*,games.chat_id,games.cookies,games.game_domain,games.game_id,games.cookies
            FROM timers, games
            WHERE games.last_level_id=timers.level_id
            AND games.status>0
            AND games.chat_id = timers.chat_id
            AND timers.game_id = games.game_id
            AND timers.time <= ".time();
    $sqlresult = mysqli_query($db, $sql);
    while($timer = mysqli_fetch_assoc($sqlresult))
    {
        $secs = time()-$timer['time'];
        if($secs >= 2)
        {
            switch($timer['type'])
            {
                case 1:
                    $array = getHints($timer['cookies'],$timer["game_domain"],$timer["game_id"]);
                    $hints = $array['result'];
                    apiRequestJSON("sendMessage", array('chat_id' => $timer['chat_id'], "parse_mode" => 'Markdown', "text" => $hints));
                break;
                case 2:
                    apiRequestJSON("sendMessage", array('chat_id' => $timer['chat_id'], "parse_mode" => 'Markdown', "text" => "*АП*"));
                    // Получаем текст нового уровня и выдаем его в чат
                    $levelText = getLevelText($timer['cookies'],$timer["game_domain"],$timer["game_id"]);

                    apiRequestJSON("sendMessage", array('chat_id' => $timer['chat_id'], "parse_mode" => 'HTML', "text" => $levelText ? $levelText : 'Ошибка получения текста уровня'));

                    $coords = getCoordsFromText($levelText);
                    foreach($coords as $match)
                    {
                        $text = $match['text'];
                        $lat = $match['lat'];
                        $lon = $match['lon'];

                        $address = $match['address'];

                        apiRequestJSON("sendLocation", array('chat_id' => $timer['chat_id'], "latitude" => $lat, "longitude" => $lon));
                        apiRequestJSON("sendMessage", array('chat_id' => $timer['chat_id'], "text" => "$lat $lon"));
                        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "parse_mode" => 'HTML', "text" => $address));
                    }

                    // Обновляем LevelID в базе
                    $array = getHints($game['cookies'],$game["game_domain"],$game["game_id"]);
                    $levelId = $array['levelid'];
                    $sql = "UPDATE games SET last_level_id = ".intval($levelId)." WHERE chat_id = $timer[chat_id]";
                    mysqli_query($db, $sql);
                break;
                default:
                    apiRequestJSON("sendMessage", array('chat_id' => $timer['chat_id'], "parse_mode" => 'Markdown', "text" => "*Что-то обновилось*"));
                break;
            }
            $sql="DELETE FROM timers WHERE id=$timer[id]";
            mysqli_query($db, $sql);
        }
    }

    // Смотрим в базе изменение ID уровня. Если поменялся, значит АП мимо бота и надо об этом сообщить.
    $sql="SELECT * FROM games WHERE status>0 AND last_level_id>0";
    $result = mysqli_query($db, $sql);
    while($row = mysqli_fetch_assoc($result))
    {
        if( ($row['last_level_id'] != $levels[$row['chat_id']]) && $levels[$row['chat_id']])
        {
            apiRequestJSON("sendMessage", array('chat_id' => $row['chat_id'], "parse_mode" => 'Markdown', "text" => "*АП* (по движку)"));
            // Получаем текст нового уровня и выдаем его в чат
            $levelText = getLevelText($row['cookies'],$row["game_domain"],$row["game_id"]);

            apiRequestJSON("sendMessage", array('chat_id' => $row['chat_id'], "parse_mode" => 'HTML', "text" => $levelText ? $levelText : 'Ошибка получения текста уровня'));

            $coords = getCoordsFromText($levelText);
            foreach($coords as $match)
            {
                $text = $match['text'];
                $lat = $match['lat'];
                $lon = $match['lon'];

                $address = $match['address'];

                apiRequestJSON("sendLocation", array('chat_id' => $row['chat_id'], "latitude" => $lat, "longitude" => $lon));
                apiRequestJSON("sendMessage", array('chat_id' => $row['chat_id'], "text" => "$lat $lon"));
                apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "parse_mode" => 'HTML', "text" => $address));
            }
        }
        $levels[$row['chat_id']]=$row['last_level_id'];
    }
    sleep(1);
}