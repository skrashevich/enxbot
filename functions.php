<?php
require_once 'Html2Text.php';
require_once __DIR__.'/encounter.php';

class TelegramUnauthorizedException extends RuntimeException {}

function auth($domain,$login,$pass)
{
    try {
        $client = encxClient($domain);
        try {
            $result = encxDecode($client->login((string)$login, (string)$pass));
            if (($result['Error'] ?? -1) !== 0) {
                return false;
            }
            return $client->exportCookies();
        } finally {
            $client->close();
        }
    } catch (Throwable $e) {
        error_log('Encounter auth failed: '.$e->getMessage());
        return false;
    }
}

function testGame($cookies,$domain,$gameid)
{
    try {
        $model = encxGameModel($cookies, $domain, $gameid);
    } catch (Throwable $e) {
        return "У команды бота нет доступа к игре";
    }
    return isset($model['GameTitle']) && $model['GameTitle'] !== '' ? $model['GameTitle'] : false;
}

function getLevelText($cookies,$domain,$gameid)
{
    try {
        $model = encxGameModel($cookies, $domain, $gameid);
    } catch (Throwable $e) {
        return false;
    }
    $level = encxLevel($model);
    $levelText = encxTaskHTML($level);
    if ($level === null || $levelText === '') {
        return false;
    }
    $levelNum = $level['Number'] ?? '?';
    $levelTotal = isset($model['Levels']) && is_array($model['Levels']) ? count($model['Levels']) : '?';
    return "<b>Уровень $levelNum из $levelTotal</b>\n".html2text($levelText);
}

function getHints($cookies,$domain,$gameid,$onlyOpen=false)
{
    $hints = Array();
    $remains = Array();
    $level = null;
    try {
        $level = encxLevel(encxGameModel($cookies, $domain, $gameid));
    } catch (Throwable $e) {
        error_log('Encounter getHints failed: '.$e->getMessage());
    }

    foreach (($level['Helps'] ?? array()) as $hint) {
        $number = (int)($hint['Number'] ?? 0);
        if ($number <= 0 || !empty($hint['IsPenalty'])) {
            continue;
        }
        $text = $hint['HelpText'] ?? null;
        $remain = (int)($hint['RemainSeconds'] ?? 0);
        if (is_string($text) && $text !== '') {
            $hints[$number] = Array('text' => html2text($text));
        } elseif (!$onlyOpen && $remain > 0) {
            $hints[$number] = Array('text' => "До открытия {$remain} сек.", 'remain' => $remain);
            $remains[$number] = $remain;
        }
    }

    $result = '';
    ksort($hints);
    foreach($hints as $num=>$hint)
    {
        $result .= "*$num*: `$hint[text]`\n";
    }

    if(!$result)
    {
      $result = 'Подсказок нет';
    }

    $UPsecs = $level === null ? 0 : (int)($level['TimeoutSecondsRemain'] ?? 0);
    $levelId = $level === null ? -1 : (int)($level['LevelId'] ?? -1);

    return Array(
      'result'  => $result,
      'remains' => $remains,
      'UPsecs'  => $UPsecs,
      'levelid' => $levelId,
      'hints'   => $hints,
    );
}

/**
 * Код Event игровой модели означает завершённую игру.
 * Encounter сообщает конец игры разными кодами:
 *   6  (EventGameFinished) - все уровни пройдены;
 *   17 (EventGameEnded, "Игра окончена") - так завершённую игру отдаёт боевой
 *      REST-движок (проверено на demo.en.cx: после последнего кода Event=17,
 *      Level=null, все Levels[].IsPassed=true).
 */
function isGameFinishedEvent($event)
{
    $event = (int)$event;
    return $event === 6 || $event === 17;
}

/**
 * Игра завершена по данным движка.
 * Возвращает null, если состояние выяснить не удалось: недоступность движка
 * не должна приводить к ложной остановке игры.
 */
function isGameFinished($cookies,$domain,$gameid)
{
    try {
        $model = encxGameModel($cookies, $domain, $gameid);
    } catch (Throwable $e) {
        error_log('Encounter isGameFinished failed: '.$e->getMessage());
        return null;
    }

    if (!isset($model['Event'])) {
        return null;
    }

    return isGameFinishedEvent($model['Event']);
}

function getScheme($cookies,$domain,$gameid)
{
    try {
        $task = encxTaskHTML(encxLevel(encxGameModel($cookies, $domain, $gameid)));
    } catch (Throwable $e) {
        return false;
    }
    return preg_match('#<img[^>]+src=["\'](.*?)["\']#is', $task, $matches) ? $matches[1] : false;
}

function sendCode($cookies,$domain,$gameid,$code)
{
    $bonusAccepted = false;
    try {
        $client = encxClient($domain, $cookies);
        try {
            $before = encxDecode($client->getGameModel((int)$gameid));
            $oldLevel = encxLevel($before);
            if ($oldLevel === null) {
                return Array('result' => '', 'levelid' => -1, 'UP' => false);
            }
            $levelId = (int)($oldLevel['LevelId'] ?? 0);
            $levelNumber = (int)($oldLevel['Number'] ?? 0);
            $after = encxDecode($client->sendCode((int)$gameid, $levelId, $levelNumber, (string)$code));

            // Encounter принимает ответы на секторы и на бонусы разными полями формы,
            // а игрок присылает просто код. Поэтому непринятый секторный код
            // повторно пробуем как бонусный - иначе бонусы взять невозможно.
            $accepted = $after['EngineAction']['LevelAction']['IsCorrectAnswer'] ?? null;
            if ($accepted !== true && !empty($oldLevel['Bonuses'])) {
                $afterBonus = encxDecode($client->sendBonusCode((int)$gameid, $levelId, $levelNumber, (string)$code));
                if (($afterBonus['EngineAction']['BonusAction']['IsCorrectAnswer'] ?? null) === true) {
                    $after = $afterBonus;
                    $bonusAccepted = true;
                }
            }
        } finally {
            $client->close();
        }
    } catch (Throwable $e) {
        error_log('Encounter sendCode failed: '.$e->getMessage());
        return Array('result' => '', 'levelid' => 0, 'UP' => false);
    }

    if (!empty($bonusAccepted)) {
        $accepted = true;
    } else {
        $action = $after['EngineAction']['LevelAction'] ?? array();
        $accepted = $action['IsCorrectAnswer'] ?? null;
    }
    $result = $accepted === true ? 'Код принят' : ($accepted === false ? 'Код не принят' : '');
    $newLevel = encxLevel($after);
    if ($newLevel === null) {
        return Array('result' => $result !== '' ? $result : 'Игра завершена', 'levelid' => -1, 'UP' => false);
    }

    $newLevelId = (int)($newLevel['LevelId'] ?? 0);
    if ($newLevelId > 0 && $newLevelId !== $levelId) {
        return Array('result' => $result, 'levelid' => $newLevelId, 'UP' => true);
    }

    $total = count($newLevel['Sectors'] ?? array());
    if ($total > 0) {
        $done = 0;
        foreach ($newLevel['Sectors'] as $sector) {
            $done += !empty($sector['IsAnswered']) ? 1 : 0;
        }
        $result .= " ($done/$total)";
    }

    $oldBonuses = array();
    foreach (($oldLevel['Bonuses'] ?? array()) as $bonus) {
        $oldBonuses[(int)($bonus['BonusId'] ?? 0)] = $bonus;
    }
    foreach (($newLevel['Bonuses'] ?? array()) as $bonus) {
        $id = (int)($bonus['BonusId'] ?? 0);
        if (!isset($oldBonuses[$id])) {
            continue;
        }
        $old = $oldBonuses[$id];
        $becameAvailable = ((int)($old['SecondsToStart'] ?? 0) > 0 && (int)($bonus['SecondsToStart'] ?? 0) <= 0)
            || ((string)($old['Task'] ?? '') === '' && (string)($bonus['Task'] ?? '') !== '');
        $becameAnswered = empty($old['IsAnswered']) && !empty($bonus['IsAnswered']);
        if ($becameAvailable || $becameAnswered) {
            $result .= "\nОткрылся бонус ".trim((string)($bonus['Name'] ?? ''))
                .': '.html2text((string)($bonus['Task'] ?? ''));
        }
    }

    return Array('result' => $result, 'levelid' => $newLevelId ?: $levelId, 'UP' => false);
}

function parseCode($text, $chat_id, $sender, $location=Array())
{
  global $db;
  $sender = db_escape($db, (string)$sender);
  $chat_id = intval($chat_id);
  $sql = "SELECT * FROM games WHERE chat_id = $chat_id";
  $sqlresult = db_query($db, $sql);
  $settings = db_fetch_assoc($sqlresult);

  if(!$settings || !$settings['status'])
  {
    return "Нет активной игры";
  }
  if($chat_id > 0)
  {
    // Если код пришел в личку, то ругаемся
    return "Отправка кодов возможна только в публичные чаты";
  }

  // Пробиваем в движок все до символов //, остальное - комментарий
  $parts = explode('//', substr($text,1), 2);
  $code = $parts[0];
  $comment = isset($parts[1]) ? $parts[1] : '';

  // Если не принимать код без комментария и комментария нет
  if(get_setting('nocomment', $chat_id)=='true' && strlen($comment)==0)
  {
    return "Прием кода возможен только с комментарием";
  }

  // Обрабатываем геокоды: статус 3 - с пробитием в движок, статус 4 - только в лог
  if($settings['status']==3 || $settings['status']==4)
  {
    if(!isset($location['latitude']) || !isset($location['longitude']))
    {
      return "Прием кодов возможен только с геолокацией";
    }
    $comment .= " ($location[latitude], $location[longitude])";
  }

  if( ($settings['status']==2) || ($settings['status']==4) )
  {
    // Заносим код в лог но не пробиваем его
    $sql = "INSERT INTO codeslog (chat_id, level, code, comment, `time`, sender, `return`) VALUES
            (
                $chat_id, ".intval($settings['last_level_id']).", '".db_escape($db, $code)."', '".db_escape($db, $comment)."', ".time().", '$sender', 'NOT SENDED'
            )";
    db_query($db, $sql);

    return "Код записан, но не передан в движок";
  }

  // если status = 1 или 3
  if(!$settings["cookies"])
  {
    $cookies = auth($settings["game_domain"], $settings["game_login"], $settings["game_pass"]);
    if($cookies !== false)
    {
      $sql = "UPDATE games SET cookies = '".db_escape($db, $cookies)."' WHERE chat_id = $settings[chat_id]";
      db_query($db, $sql);
    }
  }
  else
  {
    $cookies = $settings["cookies"];
  }
  if($cookies===false)
  {
    return "Не проходит авторизация на игровом движке";
  }

  $sectorstmp = getSectors($cookies,$settings["game_domain"],$settings["game_id"]);
  $sectorsBefore = $sectorstmp['sectors'];

  $array = sendCode($cookies,$settings["game_domain"],$settings["game_id"],$code);
  $result = $array['result'];
  $levelId = $array['levelid'];

  // levelid == -1 означает завершённую игру и записывается намеренно,
  // а 0 - что движок не отдал LevelId: такое значение теряет текущий уровень
  if($levelId > 0 || $levelId == -1)
  {
    $sql = "UPDATE games SET last_level_id = ".intval($levelId)." WHERE chat_id = $settings[chat_id]";
    db_query($db, $sql);
  }
  $sql = "INSERT INTO codeslog (chat_id, level, code, comment, `time`, sender, `return`) VALUES
          (
            $chat_id, ".intval($levelId).", '".db_escape($db, $code)."', '".db_escape($db, $comment)."', ".time().", '$sender', '".db_escape($db, $result)."'
          )";
  db_query($db, $sql);

  $sectorstmp = getSectors($cookies,$settings["game_domain"],$settings["game_id"]);
  $sectorsAfter = $sectorstmp['sectors'];

  foreach($sectorsAfter as $num => $sector)
  {
      // Мы открыли код
      if(isset($sectorsBefore[$num]) && $sectorsBefore[$num]['found'] < $sector['found'])
      {
          $sql = "UPDATE codes SET code_status = 1, code = "."'".db_escape($db, $sector['code'])."' WHERE code_number = ".intval($num)." AND code_type = 1 AND chat_id = $settings[chat_id] AND level = ".intval($levelId);
          db_query($db, $sql);
      }
  }

  return $result;
}

/**
 * Уровень без разбивки на секторы. Если у него есть проходной код
 * (RequiredSectorsCount > 0) и он не снят, отдаём один синтетический
 * сектор №1: движок сам код уровня не возвращает даже после ввода, поэтому
 * важен только факт "введён / не введён". У чисто бонусных и экшн-уровней
 * RequiredSectorsCount == 0 - сектор не выдумываем. Наличие бонусов на
 * уровне на это не влияет: у уровня с проходным кодом часто есть и бонусы.
 *
 * Возвращает ['sectors' => [1 => [...]], 'text' => '...'] либо
 * ['sectors' => [], 'text' => null], если синтетический сектор не нужен.
 */
function synthSectorForLevel($level)
{
    if (!is_array($level)) {
        return Array('sectors' => Array(), 'text' => null);
    }
    $requiredSectors = (int)($level['RequiredSectorsCount'] ?? 0);
    if ($requiredSectors <= 0 || !empty($level['Dismissed'])) {
        return Array('sectors' => Array(), 'text' => null);
    }
    $passed = !empty($level['IsPassed']) || (int)($level['PassedSectorsCount'] ?? 0) > 0;
    return Array(
        'sectors' => Array(1 => Array('found' => $passed, 'code' => '')),
        'text' => $passed
            ? "На уровне один проходной код, введён"
            : "На уровне один проходной код, ещё не введён",
    );
}

function getSectors($cookies,$domain,$gameid)
{
    $result = Array(
      'text' => "На уровне нет разделения по секторам",
      'sectors' => Array(),
    );
    try {
        $level = encxLevel(encxGameModel($cookies, $domain, $gameid));
    } catch (Throwable $e) {
        error_log('Encounter getSectors failed: '.$e->getMessage());
        return $result;
    }
    if ($level === null) {
      return $result;
    }
    if (empty($level['Sectors'])) {
      $synth = synthSectorForLevel($level);
      if ($synth['sectors']) {
          $result['sectors'] = $synth['sectors'];
          $result['text'] = $synth['text'];
      }
      return $result;
    }
    $sectors_total = count($level['Sectors']);
    $sectors_done = 0;
    foreach ($level['Sectors'] as $sector) {
        $number = (int)($sector['Order'] ?? 0);
        if ($number <= 0) {
            $number = count($result['sectors']) + 1;
        }
        $found = !empty($sector['IsAnswered']);
        if ($found) {
            $sectors_done++;
        }
        $result['sectors'][$number] = Array(
            'found' => $found,
            'code' => $found ? encxAnswerText($sector['Answer'] ?? null) : '',
        );
    }
    $sectors_rem = $sectors_total - $sectors_done;
    $result['text'] = "На уровне $sectors_total сектора. Закрыто $sectors_done. Осталось закрыть $sectors_rem";
    ksort($result['sectors'], SORT_NUMERIC);
    return $result;
}

/**
 * Бонусы текущего уровня из игровой модели движка.
 * Возвращает сводный текст и массив вида номер => [found, code, name].
 */
function getBonuses($cookies,$domain,$gameid)
{
    $result = Array(
      'text' => "На уровне нет бонусов",
      'bonuses' => Array(),
    );
    try {
        $level = encxLevel(encxGameModel($cookies, $domain, $gameid));
    } catch (Throwable $e) {
        error_log('Encounter getBonuses failed: '.$e->getMessage());
        return $result;
    }
    if ($level === null || empty($level['Bonuses'])) {
      return $result;
    }
    $total = count($level['Bonuses']);
    $done = 0;
    foreach ($level['Bonuses'] as $bonus) {
        $number = (int)($bonus['Number'] ?? 0);
        if ($number <= 0) {
            $number = count($result['bonuses']) + 1;
        }
        $found = !empty($bonus['IsAnswered']);
        if ($found) {
            $done++;
        }
        $result['bonuses'][$number] = Array(
            'found' => $found,
            'code' => $found ? encxAnswerText($bonus['Answer'] ?? null) : '',
            'name' => trim((string)($bonus['Name'] ?? '')),
        );
    }
    $result['text'] = "На уровне $total бонусов. Взято $done. Осталось ".($total - $done);
    ksort($result['bonuses'], SORT_NUMERIC);
    return $result;
}

function getMessages($cookies,$domain,$gameid)
{
    try {
        $level = encxLevel(encxGameModel($cookies, $domain, $gameid));
    } catch (Throwable $e) {
      return Array('Нет сообщений организатора');
    }
    $messages = Array();
    foreach (($level['Messages'] ?? array()) as $message) {
        $text = (string)($message['WrappedText'] ?? $message['MessageText'] ?? '');
        if ($text !== '') {
            $messages[] = html2text($text);
        }
    }
    return $messages ?: Array('Нет сообщений организатора');
}

/**
 * Извлекает из произвольного текста все пары координат.
 *
 * Координату от обычного числа в тексте уровня отличает дробная часть:
 * "Уровень 1 из 3" парой координат быть не должен. Поэтому требуем минимум
 * два знака после запятой, а диапазоны широты и долготы проверяем явно,
 * а не кодируем в регулярном выражении.
 */
function getCoordsFromText($text)
{
  $result = Array();
  $levelTextClean = html2text((string)$text);

  if(!preg_match_all('#(?<![\d.])(-?\d+\.\d{2,10})[,\s]+(-?\d+\.\d{2,10})(?![\d.])#u', $levelTextClean, $matches, PREG_SET_ORDER))
  {
    return $result;
  }

  foreach($matches as $match)
  {
    $lat = (float)$match[1];
    $lon = (float)$match[2];

    if($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180)
    {
      continue;
    }

    $address = geocoder($lat, $lon);
    $links = "\n<a href='https://yandex.ru/maps/?pt=".rawurlencode($match[2]).','.rawurlencode($match[1])."&z=17&l=map'>Яндекс</a>"
      ." <a href='https://maps.google.com/?q=".rawurlencode($match[1].','.$match[2])."'>Google</a>";

    $result[] = Array('lat' => $match[1], 'lon' => $match[2], 'text' => $match[0], 'address' => $address, 'links' => $links);
  }

  return $result;
}

function get_setting($name, $chat_id=0)
{
  global $db;
  $chat_id = intval($chat_id);
  $name = db_escape($db, (string)$name);
  $sql="SELECT * FROM settings WHERE chat_id = $chat_id AND name = '$name' LIMIT 1";
  $result = db_query($db, $sql);
  if(db_num_rows($result)==0)
  {
    return false;
  }
  $row = db_fetch_assoc($result);
  $value = $row['value'];
  return $value;
}
function set_setting($name,$value,$chat_id=0)
{
  global $db;
  $chat_id = intval($chat_id);
  $exists = get_setting($name, $chat_id) !== false;

  $name = db_escape($db, (string)$name);
  $value = db_escape($db, (string)$value);

  if(!$exists)
  {
    $sql = "INSERT INTO settings (chat_id,name,value) VALUES ($chat_id, '$name', '$value')";
  } else
  {
    $sql = "UPDATE settings SET value = '$value' WHERE name = '$name' AND chat_id = $chat_id";
  }
  db_query($db, $sql);

  return true;
}

/**
 * Переключаемые настройки чата: имя настройки => подпись на кнопке.
 * Единый источник и для клавиатуры, и для обработчика нажатий.
 */
function settingsToggles()
{
  return Array(
    'noprefix'        => 'Прием стандартных кодов без префикса',
    'nocomment'       => 'Не принимать код без примечания',
    'megadzr'         => 'Принимать любое число как код',
    'optimize_chat'   => 'Удалять устаревшие списки в чате',
    'pinnedtochannel' => 'Пересылать закрепы в инфоканал',
    'KOtoinfochannel' => 'Выдавать коды уровня в инфоканал при АПе',
    'autoscheme'      => 'Присылать схему дохода при АПе',
  );
}

/**
 * Подпись полезной нагрузки кнопки настроек.
 *
 * Шифровать callback_data нельзя: AES-256-CBC с IV даёт ровно 64 байта -
 * жёсткий предел Telegram, без запаса. Подпись решает ту же задачу
 * (чужую и подделанную кнопку бот не принимает) и укладывается вдвое короче.
 */
function settingsCallbackSignature($payload)
{
  return substr(hash_hmac('sha256', (string)$payload, ENCRYPTION_KEY), 0, 10);
}

function settingsCallbackData($name, $chat_id)
{
  $payload = $name.' '.intval($chat_id);
  return $payload.' '.settingsCallbackSignature($payload);
}

/**
 * Разбирает и проверяет callback_data кнопки настроек.
 * Возвращает false для любых данных, которые бот не подписывал.
 */
function parseSettingsCallback($data)
{
  $parts = explode(' ', (string)$data);
  if(count($parts) !== 3)
  {
    return false;
  }

  $payload = $parts[0].' '.$parts[1];
  if(!hash_equals(settingsCallbackSignature($payload), $parts[2]))
  {
    return false;
  }

  if(!array_key_exists($parts[0], settingsToggles()))
  {
    return false;
  }

  return Array('name' => $parts[0], 'chat_id' => intval($parts[1]));
}

function getSettingsButtons($chat_id)
{
  $buttons = Array();

  foreach(settingsToggles() as $name => $label)
  {
    $buttons[] = Array(
      Array(
        'text' => $label.': '.(get_setting($name, $chat_id) == 'true' ? '✅' : '🚫'),
        'callback_data' => settingsCallbackData($name, $chat_id),
      ),
    );
  }

  return $buttons;
}

/**
 * Отправляет в чат ответ-список определённого типа.
 * При включённой настройке optimize_chat предыдущее сообщение этого же типа
 * удаляется, чтобы чат не зарастал устаревшими списками.
 * $key - короткое имя типа списка; у каждого типа свой ключ настройки.
 */
function sendChatList($chat_id, $key, $text, $parse_mode = 'Markdown')
{
  $settingName = 'last_'.$key.'_message_id';

  if(get_setting('optimize_chat', $chat_id) == 'true')
  {
    $previous = intval(get_setting($settingName, $chat_id));
    if($previous > 0)
    {
      // Сообщение могло быть удалено вручную или устареть - ошибка не важна
      apiRequestJSON("deleteMessage", array('chat_id' => $chat_id, 'message_id' => $previous));
    }
  }

  $result = apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "parse_mode" => $parse_mode, "text" => $text));

  if(is_array($result) && isset($result['message_id']))
  {
    set_setting($settingName, $result['message_id'], $chat_id);
  }

  return $result;
}

/**
 * Формирует текст списка меток или бонусов.
 * $mode: all - все, open - только незакрытые, closed - только закрытые.
 * Найденная в поле, но ещё не взятая метка (find) помечается отдельно.
 */
function formatCodeList($items, $mode, $title)
{
  $lines = Array();

  foreach($items as $num => $item)
  {
    if(!is_int($num))
      continue; // не обрабатываем если это не метка кода

    $found = !empty($item['found']);

    if(($mode === 'open' && $found) || ($mode === 'closed' && !$found))
      continue;

    $label = (string)$num;
    if(!empty($item['name']))
      $label .= ' '.$item['name'];

    if($found)
    {
      $lines[] = "*$label:\t".$item['code']."*";
    } else
    {
      $lines[] = empty($item['find']) ? "_{$label}_" : "_{$label}_ 📍";
    }
  }

  if(!$lines)
  {
    return $title."\n(пусто)";
  }

  return $title."\n".implode("\n", $lines);
}

/**
 * Отмечает метку найденной в поле, когда код ещё не взят (команда ?N).
 * Возвращает текст ответа для чата.
 */
function markSectorFound($chat_id, $level, $number)
{
  global $db;
  $chat_id = intval($chat_id);
  $level = intval($level);
  $number = intval($number);

  if($level <= 0)
  {
    return "Нет активного уровня";
  }

  // Строки секторов создаёт cron по данным движка; своих номеров не выдумываем
  $sql = "SELECT id, code_status FROM codes WHERE chat_id = $chat_id AND level = $level AND code_type = 1 AND code_number = $number LIMIT 1";
  $result = db_query($db, $sql);
  $row = db_fetch_assoc($result);

  if(!$row)
  {
    return "На уровне нет метки $number";
  }

  if($row['code_status'])
  {
    return "Метка $number уже закрыта";
  }

  db_query($db, "UPDATE codes SET find = 1 WHERE id = ".intval($row['id']));

  return "Метка $number отмечена как найденная";
}

/**
 * Дополняет список секторов признаком «метка найдена в поле, код не взят»
 * из таблицы codes (команда ?N).
 */
function markFoundSectors($sectors, $chat_id, $level)
{
  global $db;
  $chat_id = intval($chat_id);
  $level = intval($level);

  $result = db_query($db, "SELECT code_number FROM codes WHERE chat_id = $chat_id AND level = $level AND code_type = 1 AND find = 1");
  while($row = db_fetch_assoc($result))
  {
    $num = intval($row['code_number']);
    if(isset($sectors[$num]))
    {
      $sectors[$num]['find'] = true;
    }
  }

  return $sectors;
}

function geocoder($lat, $lon)
{
  global $db;
  $lat = floatval($lat);
  $lon = floatval($lon);

  // Проверяем наличие координат в кэше
  $sql = "SELECT * FROM geocache WHERE lat=$lat AND lon=$lon";
  $result = db_query($db, $sql);
  if(db_num_rows($result)>0)
  {
    $cacherow = db_fetch_assoc($result);
    return $cacherow['address'];
  }

  // Геокодирование адреса. Базовый адрес можно переопределить в config.php
  $base = defined('GEOCODER_URL') ? GEOCODER_URL : 'https://geocode-maps.yandex.ru/1.x/';
  $url = $base."?format=json&sco=latlong&geocode=$lat,$lon";
  $geocoder = @file_get_contents($url, false, stream_context_create(array('http' => array('timeout' => 5))));
  if($geocoder === false)
  {
    error_log("geocoder: не удалось получить данные по $lat,$lon");
    return '';
  }

  $geodata = json_decode($geocoder, true);
  $feature = isset($geodata['response']['GeoObjectCollection']['featureMember'][0])
    ? $geodata['response']['GeoObjectCollection']['featureMember'][0]
    : null;

  if(!isset($feature['GeoObject']['metaDataProperty']['GeocoderMetaData']['text']))
  {
    return '';
  }

  $address = $feature['GeoObject']['metaDataProperty']['GeocoderMetaData']['text'];

  // Добавляем адрес в кэш
  $sql = "INSERT INTO geocache (lat,lon,added,address) VALUES ($lat, $lon, ".time().", '".db_escape($db, $address)."')";
  db_query($db, $sql);

  return $address;
}

function html2text($text)
{
  $text = (string)$text;

  // Авторы заданий Encounter часто пишут перенос строки как </br> (невалидно,
  // но повсеместно) - Html2Text распознаёт только <br>, из-за чего весь текст
  // уровня склеивается в одну строку. Нормализуем все варианты в <br>.
  $text = preg_replace('#<\s*/?\s*br\s*/?\s*>#i', '<br>', $text);

  // <details><summary>...</summary>...</details> (спойлер с картой/схемой):
  // Html2Text тега не знает и вклеивает содержимое в текст без разделителей.
  // Разворачиваем в явные переносы.
  $text = preg_replace('#<\s*/?\s*(details|summary)\b[^>]*>#i', '<br>', $text);

  // width=0: не переносим строки по колонкам - в Telegram wordwrap по 70 байт
  // рвёт кириллицу каждые ~35 символов; клиент переносит сам.
  $html = new Html2Text($text, array('width' => 0));
  return $html->getText();
}


function gameSettingsbyUser($user_id)
{
  global $db;
  $settings = Array();

  $sql = "SELECT * FROM games WHERE status>0 AND last_level_id >= 0";
  $result = db_query($db, $sql);
  while($row = db_fetch_assoc($result))
  {
    $chatMember = apiRequestJSON("getChatMember", array('chat_id' => $row['chat_id'], "user_id" => $user_id));
    if(!is_array($chatMember) || !isset($chatMember['status']))
    {
      continue;
    }
    switch($chatMember['status'])
    {
      case 'creator':
      case 'administrator':
      case 'member':
        $settings = $row;
      break;
    }
  }
  return $settings;
}

/**
 * Список username администраторов бота из таблицы admins.
 */
function botAdmins()
{
  global $db;
  $admins = Array();

  $result = db_query($db, "SELECT admin_username FROM admins");
  while($row = db_fetch_assoc($result))
  {
    if($row['admin_username'] !== null && $row['admin_username'] !== '')
    {
      $admins[] = $row['admin_username'];
    }
  }

  return $admins;
}

/**
 * Администратор самого бота: главный админ из config.php либо запись в таблице admins.
 */
function isBotAdmin($username, $admins = null)
{
  $username = (string)$username;
  if($username === '')
  {
    return false;
  }

  if(defined('ADMIN_USERNAME') && $username === ADMIN_USERNAME)
  {
    return true;
  }

  if($admins === null)
  {
    $admins = botAdmins();
  }

  return in_array($username, $admins, true);
}

/**
 * Статус пользователя в чате по данным Telegram: creator, administrator, member и т.д.
 * Пустая строка означает, что статус выяснить не удалось.
 */
function chatMemberStatus($chat_id, $user_id)
{
  $user_id = intval($user_id);
  if($user_id === 0)
  {
    return '';
  }

  $chatMember = apiRequestJSON("getChatMember", array('chat_id' => $chat_id, "user_id" => $user_id));

  return is_array($chatMember) && isset($chatMember['status']) ? (string)$chatMember['status'] : '';
}

/**
 * Право управлять игрой в чате: менять настройки, запускать и останавливать бота,
 * удалять данные. Есть у администраторов бота и у администраторов самого чата.
 * В личке пользователь распоряжается только собственной строкой игры.
 */
function canManageGame($chat_id, $user_id, $username, $admins = null)
{
  if(isBotAdmin($username, $admins))
  {
    return true;
  }

  // Личный чат: единственная затрагиваемая игра - своя собственная.
  if(intval($chat_id) > 0)
  {
    return true;
  }

  return in_array(chatMemberStatus($chat_id, $user_id), array('creator', 'administrator'), true);
}

function navi($lat1,$lon1,$lat2,$lon2,$engine='yandex',$expire = 600)
{
  global $db;
  $lat1 = (float)$lat1;
  $lon1 = (float)$lon1;
  $lat2 = (float)$lat2;
  $lon2 = (float)$lon2;
  if (!is_finite($lat1) || !is_finite($lon1) || !is_finite($lat2) || !is_finite($lon2)
      || abs($lat1) > 90 || abs($lat2) > 90 || abs($lon1) > 180 || abs($lon2) > 180) {
    return false;
  }
  // Проверяем наличие данных в кэше
  $sql = "SELECT * FROM directionscache WHERE lat1=$lat1 AND lon1=$lon1 AND lat2=$lat2 AND lon2=$lon2 AND added>".(time()-$expire);
  $sqlresult = db_query($db, $sql);
  if(db_num_rows($sqlresult)>0)
  {
      $row = db_fetch_assoc($sqlresult);
      $result = Array(
        'length' => $row['length'],
        'time' => $row['time'],
        'url' => 'cached'
      );
  } else
  {
    $length = 0;
    $time = 0;

    switch($engine)
    {
      case 'yandex':
        // Базовый адрес маршрутизатора можно переопределить в config.php
        $base = defined('ROUTER_URL') ? ROUTER_URL : 'https://geointernal.mob.maps.yandex.net/v1/router';
        $url = $base.'?rll='.$lon1.','.$lat1.'~'.$lon2.','.$lat2.'&output=time&mode=jams&_='.time();
        $xml = @file_get_contents($url, false, stream_context_create(array('http' => array('timeout' => 5))));
        if($xml === false)
        {
          return false;
        }
        if(preg_match('#<r:length>([0-9\.]*?)</r:length>#', $xml, $matches))
        {
          $length = $matches[1];
        }
        if(preg_match('#<r:time>([0-9\.]*?)</r:time>#', $xml, $matches))
        {
          $time = $matches[1];
        }
      break;
      case 'google':
        $url = 'https://maps.googleapis.com/maps/api/directions/json?origin='.$lat1.','.$lon1.'&destination='.$lat2.','.$lon2;
        $json = @file_get_contents($url, false, stream_context_create(array('http' => array('timeout' => 5))));
        if($json === false)
        {
          return false;
        }
        $data = json_decode($json, true);
        if(isset($data['status']) && $data['status']=='OK')
        {
          $length = $data['routes'][0]['legs'][0]['distance']['value'];
          $time = $data['routes'][0]['legs'][0]['duration']['value'];
        }
      break;
      default:
        return false;
    }

    if (!is_numeric($length) || !is_numeric($time) || $length <= 0 || $time < 0) {
      return false;
    }
    $result = Array(
      'length' => $length,
      'time' => $time,
      'url' => $url
    );

    $sql = "INSERT INTO directionscache (lat1,lon1,lat2,lon2,added,length,time)
    VALUES ($lat1, $lon1, $lat2, $lon2,".time().",$length,$time)";
    db_query($db,$sql);
  }
  return $result;
}

/**
 * Сохраняет координату уровня в историю точек.
 */
function saveLevelPoint($chat_id, $level, $lat, $lon)
{
  global $db;
  $chat_id = intval($chat_id);
  $level = intval($level);

  if($level <= 0)
  {
    return;
  }

  db_query($db, "INSERT INTO coords (chat_id, level, lat, lon, time) VALUES ($chat_id, $level, ".floatval($lat).", ".floatval($lon).", ".time().")");
}

/**
 * Последняя сохранённая точка предыдущих уровней этого чата.
 * Нужна, чтобы посчитать перегон от прошлого КП до нового.
 */
function lastLevelPoint($chat_id, $beforeLevel)
{
  global $db;
  $chat_id = intval($chat_id);
  $beforeLevel = intval($beforeLevel);

  $result = db_query($db, "SELECT lat, lon FROM coords WHERE chat_id = $chat_id AND level != $beforeLevel ORDER BY id DESC LIMIT 1");
  $row = db_fetch_assoc($result);

  return $row ? $row : null;
}

/**
 * Человекочитаемый перегон между двумя точками.
 * Возвращает пустую строку, если маршрутный сервис недоступен - публикация
 * координат от этого страдать не должна.
 */
function routeSummary($fromLat, $fromLon, $toLat, $toLon)
{
  $route = navi($fromLat, $fromLon, $toLat, $toLon);

  if(!is_array($route) || !isset($route['length']) || $route['length'] <= 0)
  {
    return '';
  }

  $km = round($route['length'] / 1000, 1);
  $minutes = (int)round($route['time'] / 60);

  return "От предыдущей точки: $km км".($minutes > 0 ? ", ~$minutes мин в пути" : '');
}

/**
 * Публикует найденные в тексте координаты в чат и, если задан, в инфоканал.
 * Для первой точки нового уровня добавляет перегон от точки предыдущего уровня.
 * Возвращает количество опубликованных точек.
 */
function publishCoords($text, $chat_id, $infochannel = '', $level = 0, $withDistance = false)
{
  $coords = getCoordsFromText($text);
  if(!$coords)
  {
    return 0;
  }

  $previous = ($withDistance && $level > 0) ? lastLevelPoint($chat_id, $level) : null;
  $targets = array_unique(array_filter(array($chat_id, $infochannel), static fn($id) => $id !== null && (string)$id !== ''));

  foreach($coords as $index => $match)
  {
    $caption = $match['lat'].' '.$match['lon'];

    if($index === 0 && $previous !== null)
    {
      $summary = routeSummary($previous['lat'], $previous['lon'], $match['lat'], $match['lon']);
      if($summary !== '')
      {
        $caption .= "\n".$summary;
      }
    }

    foreach($targets as $target)
    {
      apiRequestJSON("sendVenue", array('chat_id' => $target, "latitude" => $match['lat'], "longitude" => $match['lon'], "title" => $match['text'], "address" => $match['address']));
      apiRequestJSON("sendMessage", array('chat_id' => $target, "parse_mode" => 'HTML', "text" => $caption));
    }

    saveLevelPoint($chat_id, $level, $match['lat'], $match['lon']);
  }

  return count($coords);
}

/**
 * Публикует сообщения организаторов, которых чат ещё не видел.
 * Дедупликация по тексту через таблицу messages: повторный прогон cron
 * не должен присылать то же самое ещё раз.
 * Возвращает количество опубликованных сообщений.
 */
function publishNewOrgMessages($chat_id, $infochannel, $messages, $level = 0)
{
  global $db;
  $chat_id = intval($chat_id);
  $published = 0;

  foreach($messages as $message)
  {
    $message = (string)$message;

    if($message === '' || $message === 'Нет сообщений организатора')
    {
      continue;
    }

    $escaped = db_escape($db, $message);
    if(db_num_rows(db_query($db, "SELECT id FROM messages WHERE chat_id = $chat_id AND message = '$escaped' LIMIT 1")) > 0)
    {
      continue;
    }

    db_query($db, "INSERT INTO messages (chat_id, time, whom, message) VALUES ($chat_id, ".time().", 'орг', '$escaped')");

    foreach(array_filter(array($chat_id, $infochannel), 'strlen') as $target)
    {
      apiRequestJSON("sendMessage", array('chat_id' => $target, "parse_mode" => 'HTML', "text" => $message));
    }

    publishCoords($message, $chat_id, $infochannel, $level);
    $published++;
  }

  return $published;
}

/**
 * Выдаёт в инфоканал список кодов уровня (настройка KOtoinfochannel).
 */
function publishLevelCodes($chat_id, $infochannel, $level)
{
  global $db;
  if (empty($infochannel) || get_setting('KOtoinfochannel', $chat_id) != 'true') {
    return false;
  }
  $query = $db->prepare('SELECT code_number, code, code_type FROM codes WHERE chat_id = ? AND level = ? AND code_status = 1 ORDER BY code_type, code_number');
  $query->execute(array($chat_id, $level));
  $lines = array('Коды завершённого уровня:');
  foreach ($query as $row) {
    $lines[] = ($row['code_type'] == 2 ? 'Бонус ' : 'Метка ').$row['code_number'].': '.$row['code'];
  }
  if (count($lines) === 1) {
    return false;
  }
  return apiRequestJSON('sendMessage', array('chat_id' => $infochannel, 'text' => implode("\n", $lines))) !== false;
}

/**
 * Выдаёт схему дохода в чат и инфоканал (настройка autoscheme).
 */
function publishScheme($cookies, $domain, $gameid, $chat_id, $infochannel)
{
  if(get_setting('autoscheme', $chat_id) != 'true')
  {
    return false;
  }

  $img = getScheme($cookies, $domain, $gameid);
  if($img === false)
  {
    return false;
  }

  foreach(array_unique(array_filter(array($chat_id, $infochannel), static fn($id) => $id !== null && (string)$id !== '')) as $target)
  {
    apiRequestJSON("sendPhoto", array('chat_id' => $target, "photo" => $img));
  }

  return true;
}

//
// Точки на местности (таблица locations)
//
// type: 1 - прислана игроком из поля, 2 - поставлена штабом (свободна),
//       3 - взята в работу, 4 - закрыта.
//

/**
 * Подпись ссылки на веб-карту точек.
 * Без неё любой, зная chat_id, увидел бы точки чужой команды.
 */
function mapToken($chat_id, $level)
{
  return substr(hash_hmac('sha256', intval($chat_id).'|'.intval($level), ENCRYPTION_KEY), 0, 16);
}

function mapTokenValid($chat_id, $level, $token)
{
  return hash_equals(mapToken($chat_id, $level), (string)$token);
}

/**
 * Полный адрес веб-карты точек уровня.
 */
function mapUrl($chat_id, $level)
{
  $base = defined('PUBLIC_URL') ? rtrim(PUBLIC_URL, '/') : '';

  return $base.'/map.php?c='.intval($chat_id).'&l='.intval($level).'&t='.mapToken($chat_id, $level);
}

function locationTypeName($type)
{
  switch(intval($type))
  {
    case 1: return 'от поля';
    case 2: return 'свободна';
    case 3: return 'в работе';
    case 4: return 'закрыта';
  }
  return 'неизвестно';
}

/**
 * Ставит точку на текущий уровень. Возвращает текст ответа для чата.
 */
function addPoint($chat_id, $level, $lat, $lon, $title, $sender_name, $sender_username, $type = 2)
{
  global $db;
  $chat_id = intval($chat_id);
  $level = intval($level);

  if($level <= 0)
  {
    return "Нет активного уровня";
  }

  $sql = "INSERT INTO locations (chat_id, time, sender_username, sender_name, lat, lon, title, level, type)
          VALUES ($chat_id, ".time().", '".db_escape($db, $sender_username)."', '".db_escape($db, $sender_name)."',
                  ".floatval($lat).", ".floatval($lon).", '".db_escape($db, $title)."', $level, ".intval($type).")";
  db_query($db, $sql);

  return "Точка принята: ".floatval($lat)." ".floatval($lon);
}

/**
 * Точки текущего уровня со статусами.
 */
function listPoints($chat_id, $level)
{
  global $db;
  $chat_id = intval($chat_id);
  $level = intval($level);

  if($level <= 0)
  {
    return "Нет активного уровня";
  }

  $result = db_query($db, "SELECT * FROM locations WHERE chat_id = $chat_id AND level = $level ORDER BY id");
  $lines = Array();

  while($row = db_fetch_assoc($result))
  {
    $line = count($lines) + 1;
    $who = trim((string)$row['sender_name']);
    $title = trim((string)$row['title']);

    $lines[] = "$line. ".$row['lat'].' '.$row['lon']
      .' - '.locationTypeName($row['type'])
      .($title !== '' ? " ($title)" : '')
      .($who !== '' ? " от $who" : '');
  }

  if(!$lines)
  {
    return "На уровне пока нет точек";
  }

  return "Точки уровня:\n".implode("\n", $lines);
}

/**
 * Закрывает точку: по указанным координатам либо ближайшую открытую.
 */
function closePoint($chat_id, $level, $lat = null, $lon = null)
{
  global $db;
  $chat_id = intval($chat_id);
  $level = intval($level);

  if($level <= 0)
  {
    return "Нет активного уровня";
  }

  $open = db_query($db, "SELECT * FROM locations WHERE chat_id = $chat_id AND level = $level AND type < 4 ORDER BY id");
  $best = null;
  $bestDistance = null;

  while($row = db_fetch_assoc($open))
  {
    if($lat === null || $lon === null)
    {
      // Без координат закрываем первую открытую точку
      $best = $row;
      break;
    }

    $distance = pointDistance($lat, $lon, $row['lat'], $row['lon']);
    if($bestDistance === null || $distance < $bestDistance)
    {
      $bestDistance = $distance;
      $best = $row;
    }
  }

  if($best === null)
  {
    return "Открытых точек на уровне нет";
  }

  db_query($db, "UPDATE locations SET type = 4 WHERE id = ".intval($best['id']));

  return "Точка ".$best['lat'].' '.$best['lon']." закрыта";
}

/**
 * Расстояние между точками по прямой, в метрах (формула гаверсинуса).
 * Нужна только для выбора ближайшей точки, маршрутный сервис здесь избыточен.
 */
function pointDistance($lat1, $lon1, $lat2, $lon2)
{
  $earth = 6371000;
  $dLat = deg2rad((float)$lat2 - (float)$lat1);
  $dLon = deg2rad((float)$lon2 - (float)$lon1);

  $a = sin($dLat / 2) ** 2
     + cos(deg2rad((float)$lat1)) * cos(deg2rad((float)$lat2)) * sin($dLon / 2) ** 2;

  return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function screenshot($sendFlag, $chat_id, $cookies, $domain, $gameid, $lastlevelid)
{
  $url = 'http://'.$domain.'/gameengines/encounter/play/'.$gameid.'?lang=ru';
  $filename = "screens/".$chat_id.".$gameid.".intval($lastlevelid).".".microtime(true).'.png';
  // Получаем нужные куки
  $cookies = encxCookieHeader($cookies);
  $atoken = preg_match('#atoken=(.*?);#', $cookies, $matches) ? $matches[1] : '';
  $stoken = preg_match('#stoken=(.*?);#', $cookies, $matches) ? $matches[1] : '';
  $guid = preg_match('#GUID=(.*?);#', $cookies, $matches) ? $matches[1] : '';
  $domaincookie = preg_match('#Domain=(.*?);#', $cookies, $matches) ? $matches[1] : '';

  if(!file_exists(PHANTOMJS))
  {
    if($sendFlag)
    {
      apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => "Ошибка: не найден phantomjs"));
    }
    return false;
  } else
  {
    $args = array($url, $filename, $guid, $stoken, $domaincookie, $atoken);
    exec(escapeshellcmd(PHANTOMJS).' enscreen.js '.implode(' ', array_map('escapeshellarg', $args)));
    if($sendFlag)
    {
      if(file_exists($filename))
      {
        apiRequestPOST("sendPhoto", array('chat_id' => $chat_id, "photo" => new CURLFile($filename)));
      } else
      {
        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => "Ошибка: не удалось получить скриншот"));
        return '';
      }
    }
    return $filename;
  }
}



/**
 * Читает PID демона из файла. Возвращает 0, если файла нет или он пуст.
 *
 * @param  string $pidfile
 * @return int
 */
function readPid($pidfile)
{
  if(!file_exists($pidfile))
  {
    return 0;
  }

  return intval(file_get_contents($pidfile));
}

//
// Telegram Bot Functions
//

function logMessage($message, $type=0)
{
    global $db;

    // Telegram присылает только те поля, которые есть у сообщения,
    // поэтому берём их через значения по умолчанию
    $message_id = intval(isset($message['message_id']) ? $message['message_id'] : 0);
    $chat_id    = intval(isset($message['chat']['id']) ? $message['chat']['id'] : 0);
    $sender_id  = intval(isset($message['from']['id']) ? $message['from']['id'] : 0);
    $chat_title = db_escape($db, isset($message['chat']['title']) ? $message['chat']['title'] : '');
    $text       = db_escape($db, isset($message['text']) ? $message['text'] : '');
    $username   = db_escape($db, isset($message['from']['username']) ? $message['from']['username'] : '');

    $sql = "INSERT INTO log (time, message_id, chat_id, chat_title, text, type, sender_id, sender_username)
    VALUES (
        ".time().",
        $message_id,
        $chat_id,
        '$chat_title',
        '$text',
        ".intval($type).",
        $sender_id,
        '$username'
    )
    ";
    db_query($db, $sql);
    return true;
}

function apiRequestPOST($method, $parameters) {
  if (!is_string($method)) {
    error_log("Method name must be a string\n");
    return false;
  }

  if (!$parameters) {
    $parameters = array();
  } else if (!is_array($parameters)) {
    error_log("Parameters must be an array\n");
    return false;
  }

  $parameters["method"] = $method;

  $handle = curl_init(API_URL);
  curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($handle, CURLOPT_TIMEOUT, 60);
  // Файлы передаются объектами CURLFile: поддержка префикса '@' убрана начиная с PHP 5.6/7,
  // а отключение CURLOPT_SAFE_UPLOAD в PHP 8 бросает ValueError
  curl_setopt($handle, CURLOPT_POSTFIELDS, $parameters);

  return exec_curl_request($handle);
}


function exec_curl_request($handle) {
  $response = curl_exec($handle);

  if ($response === false) {
    $errno = curl_errno($handle);
    $error = curl_error($handle);
    error_log("Curl returned error $errno: $error\n");
    return false;
  }

  // curl_close() устарела в PHP 8.5 и не имеет эффекта начиная с PHP 8.0
  $http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));

  if ($http_code >= 500) {
    return false;
  }

  $decoded = json_decode($response, true);
  if (!is_array($decoded)) {
    $decoded = array();
  }

  if ($http_code != 200) {
    $error_code = isset($decoded['error_code']) ? $decoded['error_code'] : $http_code;
    $description = isset($decoded['description']) ? $decoded['description'] : 'unknown error';
    error_log("Request has failed with error $error_code: $description\n");
    if ($http_code == 401) {
      throw new TelegramUnauthorizedException('Невалидный токен Telegram (HTTP 401)');
    }
    return false;
  }

  if (isset($decoded['description'])) {
    error_log("Request was successfull: {$decoded['description']}\n");
  }

  return isset($decoded['result']) ? $decoded['result'] : false;
}

function apiRequest($method, $parameters) {
  if (!is_string($method)) {
    error_log("Method name must be a string\n");
    return false;
  }

  if (!$parameters) {
    $parameters = array();
  } else if (!is_array($parameters)) {
    error_log("Parameters must be an array\n");
    return false;
  }

  foreach ($parameters as $key => $val) {
    // encoding to JSON array parameters, for example reply_markup
    if (!is_numeric($val) && !is_string($val)) {
      $parameters[$key] = json_encode($val);
    }
  }
  $url = API_URL.$method.'?'.http_build_query($parameters);

  $handle = curl_init($url);
  curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($handle, CURLOPT_TIMEOUT, 60);

  return exec_curl_request($handle);
}

function apiRequestJson($method, $parameters) {
  if (!is_string($method)) {
    error_log("Method name must be a string\n");
    return false;
  }

  if (!$parameters) {
    $parameters = array();
  } else if (!is_array($parameters)) {
    error_log("Parameters must be an array\n");
    return false;
  }

  $parameters["method"] = $method;

  $handle = curl_init(API_URL);
  curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($handle, CURLOPT_TIMEOUT, 60);
  curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($parameters));
  curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));

  return exec_curl_request($handle);
}

//
// Шифрование. Расширение mcrypt удалено в PHP 7.2, поэтому используется openssl.
// Формат: base64(IV[16 байт] . AES-256-CBC(данные)).
//
define('ENX_CIPHER', 'aes-256-cbc');

/**
 * Возвращает зашифрованную и закодированную в base64 строку либо false при ошибке.
 */
function encrypt($pure_string, $encryption_key) {
    $key = hash('sha256', (string)$encryption_key, true);
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(ENX_CIPHER));
    $encrypted_string = openssl_encrypt((string)$pure_string, ENX_CIPHER, $key, OPENSSL_RAW_DATA, $iv);

    if ($encrypted_string === false) {
        return false;
    }

    return base64_encode($iv.$encrypted_string);
}

/**
 * Возвращает расшифрованную строку либо false, если строка повреждена или ключ не подходит.
 */
function decrypt($encrypted_string, $encryption_key) {
    $raw = base64_decode((string)$encrypted_string, true);
    $iv_size = openssl_cipher_iv_length(ENX_CIPHER);

    if ($raw === false || strlen($raw) <= $iv_size) {
        return false;
    }

    $key = hash('sha256', (string)$encryption_key, true);

    return openssl_decrypt(substr($raw, $iv_size), ENX_CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, $iv_size));
}
