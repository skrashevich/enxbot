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
    $sql = "SELECT timers.*,games.chat_id,games.cookies,games.game_domain,games.game_id
            FROM timers, games
            WHERE games.last_level_id=timers.level_id
            AND games.status=1
            AND timers.game_id = games.game_id
            AND timers.time <= ".time();
    $sqlresult = mysql_query($sql);
    while($timer = mysql_fetch_assoc($sqlresult))
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
                break;
                default:
                    apiRequestJSON("sendMessage", array('chat_id' => $timer['chat_id'], "parse_mode" => 'Markdown', "text" => "*Что-то обновилось*"));
                break;
            }
            $sql="DELETE FROM timers WHERE id=$timer[id]";
            mysql_query($sql);
        }
    }

    // Смотрим в базе изменение ID уровня. Если поменялся, значит АП мимо бота и надо об этом сообщить.
    $sql="SELECT last_level_id, chat_id, game_id FROM games WHERE status=1 AND last_level_id>0";
    $result = mysql_query($sql);
    while($row = mysql_fetch_assoc($result))
    {
        if( ($row['last_level_id'] != $levels[$row['game_id']]) && $levels[$row['game_id']])
        {
            apiRequestJSON("sendMessage", array('chat_id' => $row['chat_id'], "parse_mode" => 'Markdown', "text" => "*АП* (по движку)"));
        }
        $levels[$row['game_id']]=$row['last_level_id'];
    }
    sleep(1);
}