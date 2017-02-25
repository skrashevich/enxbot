<?php
error_reporting(E_ALL & ~(E_STRICT|E_NOTICE));

include('config.php');
include('db.php');
include('functions.php');

// Если запустили не из консоли, выходим
if (php_sapi_name() != 'cli') {
  die('Скрипт должен быть запущен из консоли');
}

// Если скрипт уже работает, то не запускаем второй раз
if( get_setting('cron_running') == 'true' )
{
    die('Исполняется паралельный процесс');
}

// Ставим блокировку запуска
set_setting('cron_running', 'true');

$sql = "SELECT * FROM games WHERE last_level_id >=0 AND status>0";
$gameresult = mysqli_query($db, $sql);
while($game = mysqli_fetch_assoc($gameresult))
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
            $sql = "UPDATE games SET cookies = '".mysqli_escape_string($db, $cookies)."' WHERE chat_id = $game[chat_id] AND game_id = $game[game_id]";
            mysqli_query($db, $sql);
        }
    }

    //
    // Этап 1 - загрузка текущего уровня, проверка АП и подсказок, установка таймеров
    //
    $array = getHints($game['cookies'],$game["game_domain"],$game["game_id"]);
    
    // Смотрим, есть ли уже таймеры на подсказки
    $sql = "SELECT * FROM timers WHERE game_id = $game[game_id] AND level_id = ".intval($array['levelid'])." AND chat_id = $game[chat_id] AND type=1";
    $result = mysqli_query($db, $sql);
    if(mysqli_num_rows($result)!=count($array['remains']))
    {
        print "Подсказки на игре $game[game_id] на уровне $array[levelid] обновились\n";
        $sql="DELETE FROM timers WHERE game_id = $game[game_id] AND level_id = ".intval($array['levelid'])." AND chat_id = $game[chat_id] AND type=1 AND ABS('.time().'-`time`)>60";
        mysqli_query($db, $sql);

        // Забиваем подсказки в базу заново
        foreach($array['remains'] as $hint=>$secs)
        {
            $sql="INSERT INTO timers (game_id, chat_id, level_id, hint, time, type) VALUES ($game[game_id], $game[chat_id], ".intval($array['levelid']).", $hint, ".(time()+$secs).", 1)";
            $result = mysqli_query($db, $sql);
        }
    } else {
        // Обновляем подсказки в базе на всякий случай
        foreach($array['remains'] as $hint=>$secs)
        {
            $sql="UPDATE timers SET time= ".(time()+$secs)." WHERE game_id = $game[game_id] AND chat_id = $game[chat_id] AND level_id = ".intval($array['levelid'])." AND hint=$hint";
            $result = mysqli_query($db, $sql);
        }
    }

    if($array['UPsecs']>0)
    {
        // Проверяем наличие таймера на АП
        $sql = "SELECT * FROM timers WHERE game_id = $game[game_id] AND level_id = ".intval($array['levelid'])." AND chat_id = $game[chat_id] AND type=2";
        $result = mysqli_query($db, $sql);

        if(mysqli_num_rows($result)>0)
        {
            // Обновляем время АП на всякий случай
            $sql="UPDATE timers SET time= ".(time()+$array['UPsecs'])." WHERE game_id = $game[game_id] AND level_id = ".intval($array['levelid'])." AND chat_id = $game[chat_id] AND type=2";
            $result = mysqli_query($db, $sql);
        } else {
            // Добавляем АП в базу
            $sql="INSERT INTO timers (game_id, chat_id, level_id, hint, time, type) VALUES ($game[game_id], $game[chat_id], ".intval($array['levelid']).", 0, ".(time()+$array['UPsecs']).", 2)";
            $result = mysqli_query($db, $sql);
        }
    }

    // Обновляем текущий levelid в базе (на всякий случай)
    $sql = "UPDATE games SET last_level_id = ".intval($array['levelid'])." WHERE game_id = $game[game_id] AND chat_id = $game[chat_id]";
    mysqli_query($db, $sql);
}

//
// По всем играм - проверяем сколько осталось до подсказок и до АПа, шлём информацию
//

$sql = "SELECT timers.* FROM timers, games WHERE games.last_level_id=timers.level_id AND games.status>0 AND timers.game_id = games.game_id AND games.chat_id = timers.chat_id AND timers.time >= ".time();
$sqlresult = mysqli_query($db, $sql);
while($timer = mysqli_fetch_assoc($sqlresult))
{
    $remain = $timer['time']-time();
    $remain_text = floor($remain/60)." мин ".($remain-floor($remain/60)*60)." сек";
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

//
// Удаляем таймеры, просроченные более чем на 1 час
//
$sql = "DELETE FROM timers WHERE ".time()." > time+60*60";
mysqli_query($db, $sql);


// Снимаем блокировку запуска
set_setting('cron_running', 'false');
