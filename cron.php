<?php
error_reporting(E_ALL & ~(E_STRICT|E_NOTICE));

include('config.php');
include('db.php');
include('functions.php');

// Если запустили не из консоли, выходим
if (php_sapi_name() != 'cli') {
  die('Скрипт должен быть запущен из консоли');
}

$sql = "SELECT * FROM games WHERE last_level_id >=0 AND status=1";
$gameresult = mysql_query($sql);
while($game = mysql_fetch_assoc($gameresult))
{
    //
    // Этап 0 - проверка что мы авторизированы и авторизация если нет
    //
    $result = testGame($game['cookies'],$game["game_domain"],$game["game_id"]);
    if(!$result)
    {
        $cookies = auth($game["game_domain"], $game["game_login"], $game["game_pass"]);
        $game['cookies'] = $cookies;
        if($cookies===false)
        {
            $result = "Не проходит авторизация на игровом движке";
        } else {
            $result = "Авторизация успешно пройдена";
            $sql = "UPDATE games SET cookies = '".mysql_escape_string($cookies)."' WHERE chat_id = $chat_id";
            mysql_query($sql);
        }
    }

    //
    // Этап 1 - загрузка текущего уровня, проверка АП и подсказок, установка таймеров
    //
    $array = getHints($game['cookies'],$game["game_domain"],$game["game_id"]);
    
    // Смотрим, есть ли уже таймеры на подсказки
    $sql = "SELECT * FROM timers WHERE game_id = $game[game_id] AND level_id = ".intval($array['levelid']).' AND type=1';
    $result = mysql_query($sql);
    if(mysql_num_rows($result)!=count($array['remains']))
    {
        print "Подсказки на игре $game[game_id] на уровне $array[levelid] обновились\n";
        $sql="DELETE FROM timers WHERE game_id = $game[game_id] AND level_id = ".intval($array['levelid']).' AND type=1';
        mysql_query($sql);

        // Забиваем подсказки в базу заново
        foreach($array['remains'] as $hint=>$secs)
        {
            $sql="INSERT INTO timers (game_id, level_id, hint, time, type) VALUES ($game[game_id], ".intval($array['levelid']).", $hint, ".(time()+$secs).", 1)";
            $result = mysql_query($sql);
        }
    } else {
        // Обновляем подсказки в базе на всякий случай
        foreach($array['remains'] as $hint=>$secs)
        {
            $sql="UPDATE timers SET time= ".(time()+$secs)." WHERE game_id = $game[game_id] AND level_id = ".intval($array['levelid'])." AND hint=$hint";
            $result = mysql_query($sql);
        }
    }

    // Проверяем наличие таймера на АП
    $sql = "SELECT * FROM timers WHERE game_id = $game[game_id] AND level_id = ".intval($array['levelid']).' AND type=2';
    $result = mysql_query($sql);

    if(mysql_num_rows($result)>0)
    {
        // Обновляем время АП на всякий случай
        $sql="UPDATE timers SET time= ".(time()+$array['UPsecs'])." WHERE game_id = $game[game_id] AND level_id = ".intval($array['levelid'])." AND type=2";
        $result = mysql_query($sql);
    } else {
        // Добавляем АП в базу
        $sql="INSERT INTO timers (game_id, level_id, hint, time, type) VALUES ($game[game_id], ".intval($array['levelid']).", 0, ".(time()+$array['UPsecs']).", 2)";
        $result = mysql_query($sql);
    }

    // Обновляем текущий levelid в базе (на всякий случай)
    $sql = "UPDATE timers SET level_id = ".intval($array['levelid'])." WHERE game_id = $game[game_id]";
    mysql_query($sql);
}

//
// По всем играм - проверяем сколько осталось до подсказок и до АПа, шлём информацию
//

$sql = "SELECT timers.*,games.chat_id FROM timers, games WHERE games.last_level_id >=0 AND games.status=1 AND timers.game_id = games.game_id";
$sqlresult = mysql_query($sql);
while($timer = mysql_fetch_assoc($sqlresult))
{
    $remain = $timer['time']-time();
    $remain_text = round($remain/60)." мин ".($remain-round($remain/60)*60)." сек";
    // Если меньше 15 или 5 минут до события
    if( 
        ($remain<=60*15 && $remain>60*14) ||
         ($remain<=60*5 && $remain>60*4)
      )
    {
        switch($timer['type'])
        {
            case 1:
                $text = "Осталось $remain_text до $timer[hint] подсказки";
            break;
            case 2:
                $text = "Осталось $remain_text до АПа";
            break;
            default:
                $text = "Осталось $remain_text";
            break;
        }

        apiRequestJSON("sendMessage", array('chat_id' => $timer['chat_id'], "parse_mode" => 'Markdown', "text" => "*$text*"));
    }
}