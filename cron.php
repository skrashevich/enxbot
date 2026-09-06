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

// Блокировка от параллельного запуска.
// Хранит время старта и PID, поэтому упавший процесс не вешает cron навсегда:
// протухшая блокировка снимается по таймауту.
$lockTimeout = (int)(getenv('ENXBOT_CRON_LOCK_TIMEOUT') ?: 300);

if( get_setting('cron_running') == 'true' )
{
    $lockStarted = (int)get_setting('cron_starttime');
    $lockPid = (int)get_setting('cron_pid');
    $lockAge = time() - $lockStarted;

    if($lockStarted > 0 && $lockAge < $lockTimeout)
    {
        die("Исполняется параллельный процесс (PID $lockPid, $lockAge с назад)\n");
    }

    error_log("cron: снимаю протухшую блокировку (PID $lockPid, возраст $lockAge с)");
}

// Ставим блокировку запуска
set_setting('cron_running', 'true');
set_setting('cron_starttime', time());
set_setting('cron_pid', getmypid());

// Блокировку снимаем в любом случае: и при нормальном выходе, и при фатальной
// ошибке. Иначе одно падение остановило бы cron до ручного вмешательства.
function releaseCronLock()
{
    set_setting('cron_running', 'false');
}
register_shutdown_function('releaseCronLock');

// Невалидный токен Telegram - не повод сыпать необработанным исключением
// в каждом прогоне: сообщаем внятно и выходим.
set_exception_handler(function (Throwable $e) {
    if ($e instanceof TelegramUnauthorizedException) {
        fwrite(STDERR, 'cron: '.$e->getMessage()."\n");
        error_log('cron: '.$e->getMessage());
        exit(2);
    }
    throw $e;
});

$sql = "SELECT * FROM games WHERE last_level_id >=0 AND status>0";
$gameresult = db_query($db, $sql);
while($game = db_fetch_assoc($gameresult))
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
            $sql = "UPDATE games SET cookies = '".db_escape($db, $cookies)."' WHERE chat_id = $game[chat_id] AND game_id = $game[game_id]";
            db_query($db, $sql);
        }
    }

    //
    // Этап 1 - загрузка текущего уровня, проверка АП и подсказок, установка таймеров
    //
    $array = getHints($game['cookies'],$game["game_domain"],$game["game_id"]);
    
    if($array['levelid'] > 0)
    {
        // Смотрим, есть ли уже таймеры на подсказки
        $sql = "SELECT * FROM timers WHERE game_id = $game[game_id] AND level_id = ".intval($array['levelid'])." AND chat_id = $game[chat_id] AND type=1";
        $result = db_query($db, $sql);
        if(db_num_rows($result)!=count($array['remains']))
        {
            print "Подсказки на игре $game[game_id] на уровне $array[levelid] обновились\n";
            $sql="DELETE FROM timers WHERE game_id = $game[game_id] AND level_id = ".intval($array['levelid'])." AND chat_id = $game[chat_id] AND type=1 AND ABS(".time()."-`time`)>60";
            db_query($db, $sql);

            // Забиваем подсказки в базу заново
            foreach($array['remains'] as $hint=>$secs)
            {
                $sql="INSERT INTO timers (game_id, chat_id, level_id, hint, time, type) VALUES ($game[game_id], $game[chat_id], ".intval($array['levelid']).", $hint, ".(time()+$secs).", 1)";
                $result = db_query($db, $sql);
            }
        } else {
            // Обновляем подсказки в базе на всякий случай
            foreach($array['remains'] as $hint=>$secs)
            {
                $sql="UPDATE timers SET time= ".(time()+$secs)." WHERE game_id = $game[game_id] AND chat_id = $game[chat_id] AND level_id = ".intval($array['levelid'])." AND hint=$hint";
                $result = db_query($db, $sql);
            }
        }

        if($array['UPsecs']>0)
        {
            // Проверяем наличие таймера на АП
            $sql = "SELECT * FROM timers WHERE game_id = $game[game_id] AND level_id = ".intval($array['levelid'])." AND chat_id = $game[chat_id] AND type=2";
            $result = db_query($db, $sql);

            if(db_num_rows($result)>0)
            {
                // Обновляем время АП на всякий случай
                $sql="UPDATE timers SET time= ".(time()+$array['UPsecs'])." WHERE game_id = $game[game_id] AND level_id = ".intval($array['levelid'])." AND chat_id = $game[chat_id] AND type=2";
                $result = db_query($db, $sql);
            } else {
                // Добавляем АП в базу
                $sql="INSERT INTO timers (game_id, chat_id, level_id, hint, time, type) VALUES ($game[game_id], $game[chat_id], ".intval($array['levelid']).", 0, ".(time()+$array['UPsecs']).", 2)";
                $result = db_query($db, $sql);
            }
        }
    }

    // Обновляем текущий levelid в базе (на всякий случай).
    // При сбое движка getHints() возвращает -1; записывать его нельзя, иначе
    // игра перестанет попадать в выборку "last_level_id >= 0" и бот её потеряет.
    if($array['levelid'] > 0)
    {
        $sql = "UPDATE games SET last_level_id = ".intval($array['levelid'])." WHERE game_id = $game[game_id] AND chat_id = $game[chat_id]";
        db_query($db, $sql);
    }

    // Работа с секторами и бонусами (кодами)

    $levelid = intval($array['levelid']);

    // Состояние кодов накапливается отдельно для каждой игры,
    // основные метки (тип 1) и бонусы (тип 2) не смешиваются
    $codesDB = Array();
    $sql = "SELECT * FROM codes WHERE chat_id = $game[chat_id] AND level = $levelid";
    $cresult = db_query($db, $sql);
    while($crow = db_fetch_assoc($cresult))
    {
        $codesDB[intval($crow['code_type']).':'.intval($crow['code_number'])] = Array(
            'found' => $crow['code_status'],
            'id' => $crow['id'],
        );
    }

    $sectorsActual = getSectors($game['cookies'],$game["game_domain"],$game["game_id"]);
    $bonusesActual = getBonuses($game['cookies'],$game["game_domain"],$game["game_id"]);

    $groups = Array(
        Array('type' => 1, 'label' => 'Сектор', 'items' => $sectorsActual['sectors']),
        Array('type' => 2, 'label' => 'Бонус',  'items' => $bonusesActual['bonuses']),
    );

    foreach($groups as $group)
    {
        foreach($group['items'] as $num => $item)
        {
            $num = intval($num);
            $type = $group['type'];
            $code = db_escape($db, $item['code']);
            $key = $type.':'.$num;

            if(!isset($codesDB[$key])) // Если мы еще не видели этого кода
            {
                if(!$item['found'])
                {
                    $sql = "INSERT INTO codes (chat_id, level, time, code_number, code_type, code_status)
                    VALUES ($game[chat_id], $levelid, ".time().", $num, $type, 0)";
                } else
                {
                    $sql = "INSERT INTO codes (chat_id, level, time, code_number, code_type, code_status, code)
                    VALUES ($game[chat_id], $levelid, ".time().", $num, $type, 1, '$code')";
                }
                db_query($db, $sql);
            } else if($codesDB[$key]['found'] < $item['found']) // Если код мы уже знаем и только что открыли
            {
                $sql = "UPDATE codes SET code_status = 1, code = '$code' WHERE id = ".intval($codesDB[$key]['id']);
                db_query($db, $sql);

                apiRequestJSON("sendMessage", array('chat_id' => $game['chat_id'], "parse_mode" => 'Markdown', "text" => "$group[label] *$num* закрыт через движок"));
            }
        }
    }

    //
    // Сообщения организаторов: публикуем только новые
    //
    publishNewOrgMessages(
        $game['chat_id'],
        $game['infochannel'],
        getMessages($game['cookies'],$game["game_domain"],$game["game_id"]),
        $levelid
    );

    //
    // Автоматическая остановка игры. Два независимых признака:
    //   - движок сообщил, что игра завершена;
    //   - в чате давно нет активности (считается по своей таблице log,
    //     поэтому не зависит от доступности движка).
    // Недоступность движка сама по себе игру не останавливает.
    //
    $stopReason = '';
    $finished = isGameFinished($game['cookies'],$game["game_domain"],$game["game_id"]);

    if($finished === true)
    {
        $stopReason = 'Игра завершена. Бот остановлен.';
    }
    else
    {
        $idleTimeout = (int)(getenv('ENXBOT_IDLE_TIMEOUT') ?: 86400);
        $lastActivity = db_fetch_assoc(db_query($db, "SELECT MAX(time) AS last FROM log WHERE chat_id = $game[chat_id]"));
        $last = isset($lastActivity['last']) ? (int)$lastActivity['last'] : 0;

        if($last > 0 && (time() - $last) > $idleTimeout)
        {
            $hours = round((time() - $last) / 3600);
            $stopReason = "В чате нет активности $hours ч. Бот остановлен, запустите командой /game start.";
        }
    }

    if($stopReason !== '')
    {
        db_query($db, "UPDATE games SET status = 0 WHERE chat_id = $game[chat_id]");
        apiRequestJSON("sendMessage", array('chat_id' => $game['chat_id'], "text" => $stopReason));
    }
}

//
// По всем играм - проверяем сколько осталось до подсказок и до АПа, шлём информацию
//

$sql = "SELECT timers.* FROM timers, games WHERE games.last_level_id=timers.level_id AND games.status>0 AND timers.game_id = games.game_id AND games.chat_id = timers.chat_id AND timers.time >= ".time();
$sqlresult = db_query($db, $sql);
while($timer = db_fetch_assoc($sqlresult))
{
    $remain = $timer['time']-time();
    $remain_text = floor($remain/60)." мин ".($remain-floor($remain/60)*60)." сек";

    // Порог считается пройденным, как только до события осталось меньше.
    // Отметка о выдаче не даёт повторов, поэтому пропущенный прогон cron
    // не теряет предупреждение, а лишь сдвигает его на следующий прогон.
    foreach(array(15, 5) as $threshold)
    {
        if($remain > 60 * $threshold)
        {
            continue;
        }

        $warnKey = 'warned_'.intval($timer['id']).'_'.$threshold;
        if(get_setting($warnKey, $timer['chat_id']) == 'true')
        {
            continue;
        }
        set_setting($warnKey, 'true', $timer['chat_id']);

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
db_query($db, $sql);

// И отметки о выданных предупреждениях по уже несуществующим таймерам
db_query($db, "DELETE FROM settings
               WHERE name LIKE 'warned\\_%' ESCAPE '\\'
               AND CAST(SUBSTR(name, 8, INSTR(SUBSTR(name, 8), '_') - 1) AS INTEGER)
                   NOT IN (SELECT id FROM timers)");
