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
        var_dump($timer);
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
    sleep(1);
}