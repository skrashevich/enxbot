<?php
require_once 'Html2Text.php';

/**
 * Запрос к игровому движку. Всегда возвращает строку: при ошибке сети - пустую,
 * чтобы вызывающий код не передавал false/null в строковые функции (deprecated с PHP 8.1).
 *
 * @param string        $url
 * @param string|null   $cookies заголовок Cookie
 * @param array|null    $post    поля POST-запроса; null - обычный GET
 * @param string[]|null &$setCookies сюда складываются значения заголовков Set-Cookie
 * @return string
 */
function engineRequest($url, $cookies = null, $post = null, &$setCookies = null)
{
    $setCookies = Array();

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    if ($cookies !== null && $cookies !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $cookies);
    }
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    // Заголовки собираем колбэком: разбор сырого ответа ломается на
    // промежуточных ответах вроде "100 Continue" и редиректах
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($handle, $line) use (&$setCookies) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $setCookies[] = trim(substr($line, strlen('Set-Cookie:')));
        }
        return strlen($line);
    });

    $response = curl_exec($ch);

    if ($response === false) {
        error_log('engineRequest failed: '.curl_error($ch));
        $response = '';
    }

    // curl_close() не нужен: начиная с PHP 8.0 дескриптор - объект,
    // который освобождается сборщиком мусора, а сама функция устарела в 8.5
    return (string)$response;
}

/**
 * Превращает список заголовков Set-Cookie в пары имя => значение.
 *
 * @param  string[] $setCookies
 * @return array<string,string>
 */
function parseSetCookies($setCookies)
{
    $cookies = Array();

    foreach ($setCookies as $line) {
        $pair = explode('=', explode(';', $line, 2)[0], 2);
        $name = trim($pair[0]);
        if ($name === '') {
            continue;
        }
        $cookies[$name] = isset($pair[1]) ? $pair[1] : '';
    }

    return $cookies;
}

function auth($domain,$login,$pass)
{
    $post = Array(
        'Login' => $login,
        'Password' => $pass,
    );

    engineRequest('http://'.$domain.'/login/signin/?return=%2f', null, $post, $setCookies);

    $cookies = parseSetCookies($setCookies);

    if(!isset($cookies['atoken'])) // первый этап авторизации не пройден
    {
      return false;
    }

    // идем на второй этап авторизации
    $rawcookies = '';
    foreach($cookies as $k=>$v)
    {
        $rawcookies .= "$k=$v; ";
    }

    engineRequest('http://'.$domain.'/login/checkcookie?return=%252f', $rawcookies, $post, $setCookies);

    $cookies = array_merge($cookies, parseSetCookies($setCookies));

    if(!isset($cookies['stoken']))
    {
      return false;
    }

    $rawcookies = '';
    foreach($cookies as $k=>$v)
    {
        $rawcookies .= "$k=$v; ";
    }

    return $rawcookies;
}

function testGame($cookies,$domain,$gameid)
{
    $response = engineRequest('http://'.$domain.'/gameengines/encounter/play/'.$gameid.'?lang=ru', $cookies);

    // Если короткий ответ, значит это заглушка-перенаправление
    if(strlen($response)<170)
    {
        return "У команды бота нет доступа к игре";
    }
    // Вычленяем название игры

    if(!preg_match('#<a href="/games/details/'.$gameid.'/">(.*)</a>#',$response,$matches))
    {
        return false;
    }

    return $matches[1];
}

function getLevelText($cookies,$domain,$gameid)
{
    $response = engineRequest('http://'.$domain.'/gameengines/encounter/play/'.$gameid.'?lang=ru', $cookies);

    // Вычленяем текст задания

    if(!preg_match('#<h3>Задание</h3>.*?<p>(.*?)(<h3|<div)#ms',$response,$matches))
    {
        return false;
    }

    $levelText = $matches[1];

    $levelNum = '?';
    $levelTotal = '?';
    if(preg_match('#<h2>Уровень <span>(\d+)</span> из (\d+).*?</h2>#', $response, $matches))
    {
        $levelNum = $matches[1];
        $levelTotal = $matches[2];
    }

    $text_clean = html2text($levelText);

    return "<b>Уровень $levelNum из $levelTotal</b>\n$text_clean";
}

function getHints($cookies,$domain,$gameid,$onlyOpen=false)
{
    $response = engineRequest('http://'.$domain.'/gameengines/encounter/play/'.$gameid.'?lang=ru', $cookies);

    // Каждый элемент $hints - массив с ключом 'text' (и 'remain' у закрытых подсказок).
    $hints = Array();
    $remains = Array();

    // Вычленяем подсказки

    if(!$onlyOpen)
    {
      preg_match_all('#<span class="color_dis"><b>Подсказка&nbsp;([0-9]+)</b>&nbsp;будет через&nbsp;<span class="bold_off color_dis" id="time[0-9]*?">(.*?)</span><script type="text/javascript">.*?"StartCounter":([0-9]+),.*?</script>#ms',$response,$matches,PREG_SET_ORDER);
      foreach($matches as $match)
      {
        $hint = $match[1];
        $remain = $match[2];
        $remain_sec = $match[3];

        $hints[$hint] = Array(
          'text' => "До открытия $remain",
          'remain' => $remain_sec,
        );
        $remains[$hint] = $remain_sec;
      }
    }

    preg_match_all('#<h3>Подсказка ([0-9]+)</h3>(.*?)</p>#sm',$response,$matches,PREG_SET_ORDER);
    foreach($matches as $match)
    {
      $hint = $match[1];

      $hints[$hint] = Array('text' => html2text(trim($match[2])));
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

    //
    // Вычленяем время автоперехода
    //

    $UPsecs = 0;
    if(preg_match('#<strong>Автопереход</strong> на следующий уровень через&nbsp;<span class="bold_off timer" id="time[0-9]*">(.*?)</span><script type="text/javascript">.*?"StartCounter":([0-9]+),.*?</script>?#ms',$response,$matches))
    {
      $UPsecs = $matches[2];
    }

    // Вычленяем LevelId
    $levelId = -1;
    if(preg_match('#<input type="hidden" name="LevelId" value="(\d+)" />#',$response,$matches) && $matches[1] > 0)
    {
      $levelId = $matches[1];
    }

    return Array(
      'result'  => $result,
      'remains' => $remains,
      'UPsecs'  => $UPsecs,
      'levelid' => $levelId,
      'hints'   => $hints,
    );
}

function getScheme($cookies,$domain,$gameid)
{
    $response = engineRequest('http://'.$domain.'/gameengines/encounter/play/'.$gameid.'?lang=ru', $cookies);

    // Вычленяем картинку схемы

    if(!preg_match('#<img src="(.*?)"#ms',$response,$matches))
    {
        return false;
    }

    return $matches[1];
}

function sendCode($cookies,$domain,$gameid,$code)
{
    $response = engineRequest('http://'.$domain.'/gameengines/encounter/play/'.$gameid, $cookies);

    // Вычленяем LevelId
    $levelId = 0;
    if(preg_match('#<input type="hidden" name="LevelId" value="(\d+)" />#',$response,$matches))
    {
        $levelId = $matches[1];
    }

    // Вычленяем LevelNumber
    $levelNumber = 0;
    if(preg_match('#<input type="hidden" name="LevelNumber" value="(\d+)" />#',$response,$matches))
    {
        $levelNumber = $matches[1];
    }

    // Работаем с открытымибонусами
    preg_match_all('#<h3 class="color_correct">(.*?)Бонус (\d+):(.*?)<span class="color_sec">\((.*?)\)</span>.*?<p>(.*?)</p>#mis', $response, $matches, PREG_SET_ORDER);
    $Bonuses = Array();
    foreach($matches as $v)
    {
      $Bonuses[$v[2]] = Array(
        'open' => true,
        'name' => trim($v[3]),
        'status' => $v[4],
        'text' => $v[5]
      );
    }

    // Работаем с закрытыми мибонусами
    preg_match_all('#<h3 class="color_bonus">(.*?)Бонус (\d+):(.*?)</h3>#mis', $response, $matches, PREG_SET_ORDER);
    foreach($matches as $v)
    {
      $Bonuses[$v[2]] = Array(
        'open' => false,
        'name' => trim($v[3]),
        'status' => '',
        'text' => ''
      );
    }

    unset($response);

    // Отправляем код

    $post = Array(
        'LevelId' => $levelId,
        'LevelNumber' => $levelNumber,
        'LevelAction.Answer' => $code,
    );

    $response = engineRequest('http://'.$domain.'/gameengines/encounter/play/'.$gameid.'?lang=ru', $cookies, $post);

    $result = '';
    if(preg_match('#<span class="color_[i]?[n]?correct".*?>(.*)</span>#',$response,$matches))
    {
      $result = html2text(str_replace('&quot;','', $matches[1]));
    }

    // Если вдруг закончили игру
    if( preg_match('#<center class="gameCongratulation">(.*)</center>#ms', $response,$matches) )
    {
      // Если закончили игру, то всё остальное не имеет смысла
      return Array(
        'result'  => html2text($matches[1]),
        'levelid' => '-1',
        'UP'      => false,
      );
    }

    // Проверяем на АП
    // Вычленяем LevelId
    if(preg_match('#<input type="hidden" name="LevelId" value="(\d+)" />#',$response,$matches) && $levelId != $matches[1])
    {
        // Если апнулись, то остальное не имеет смысла
        return Array(
          'result'  => $result,
          'levelid' => $matches[1],
          'UP'      => true,
        );
    }

    // Считаем сектора
    // Если есть совпадение - значит на уровне есть сектора
    if(preg_match('#<h3>.*На уровне ([0-9]*) сектор.*?<span class="color_sec">\(осталось закрыть ([0-9]*)\)</span>#ms',$response, $matches))
    {
      $sectors_total = $matches[1];
      $sectors_rem = $matches[2];

      $sectors_done = $sectors_total-$sectors_rem;

      $result .= " ($sectors_done/$sectors_total)";
    }

    // Работаем с вновь открытымибонусами
    preg_match_all('#<h3 class="color_correct">(.*?)Бонус (\d+):(.*?)<span class="color_sec">\((.*?)\)</span>.*?<p>(.*?)</p>#mis', $response, $matches, PREG_SET_ORDER);
    foreach($matches as $v)
    {
      if(isset($Bonuses[$v[2]]) && $Bonuses[$v[2]]['open'] == false) // Если бонус был не открыт
      {
        $result .= "\nОткрылся бонус ".trim($v[3]).": ".html2text($v[5])." ($v[4])";
      }
    }

    return Array(
      'result'  => $result,
      'levelid' => $levelId,
      'UP'      => false,
    );
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
          $sql = "UPDATE codes SET code_status = 1 WHERE code_number = ".intval($num)." AND chat_id = $settings[chat_id] AND level = ".intval($levelId);
          db_query($db, $sql);
      }
  }

  return $result;
}

function getSectors($cookies,$domain,$gameid)
{
    $response = engineRequest('http://'.$domain.'/gameengines/encounter/play/'.$gameid, $cookies);

    $result = Array(
      'text' => "На уровне нет разделения по секторам",
      'sectors' => Array(),
    );

    // Считаем сектора. Есть совпадение - значит на уровне есть сектора
    if(!preg_match('#<h3>.*На уровне ([0-9]*) сектор#ms',$response, $matches))
    {
      return $result;
    }

    $sectors_total = $matches[1];
    $sectors_rem = $sectors_total;
    if(preg_match('#<h3>.*На уровне ([0-9]*) сектор.*?<span class="color_sec">\(осталось закрыть ([0-9]*)\)</span>#ms', $response, $matches) && intval($matches[2]) > 0)
    {
      $sectors_rem = intval($matches[2]);
    }

    $sectors_done = $sectors_total-$sectors_rem;

    $result['text'] = "На уровне $sectors_total сектора. Закрыто $sectors_done. Осталось закрыть $sectors_rem";

    // Закрытые сектора
    preg_match_all('#<p>(\d+): <span class="color_correct">(.*?)</span> <span class="color_sec">\((.*?) <a href=".*?">(.*?)</a>\)</span></p>#ms', $response, $matches, PREG_SET_ORDER);
    foreach($matches as $match)
    {
      $result['sectors'][$match[1]] = Array('found' => true, 'code' => $match[2]);
    }
    // Открытые сектора
    preg_match_all('#<p>(\d+): <span class="color_dis">код не введён</span></p>#', $response, $matches, PREG_SET_ORDER);
    foreach($matches as $match)
    {
      $result['sectors'][$match[1]] = Array('found' => false, 'code' => '');
    }

    ksort($result['sectors'], SORT_NUMERIC);

    return $result;
}

function getMessages($cookies,$domain,$gameid)
{
    $response = engineRequest('http://'.$domain.'/gameengines/encounter/play/'.$gameid, $cookies);

    // Получаем сообщения
    if(!preg_match('#<p class="globalmess">(.*?)</p>#ms',$response, $matches))
    {
      return Array('Нет сообщений организатора');
    }

    $messages = explode('<br />', $matches[1]);
    foreach($messages as $i => $message)
    {
      $messages[$i] = html2text($message);
    }

    return $messages;
}

function getCoordsFromText($text)
{
  $result = Array();
   // Ищем в тексте координаты
   $levelTextClean = html2text((string)$text);
   preg_match_all('#(.*?)[\s:,;\.]?(-?[1-8]?\d(?:\.\d{1,8})?|90(?:\.0{1,8})?)[,\s]+?(-?(?:1[0-7]|[1-9])?\d(?:\.\d{1,8})?|180(?:\.0{1,8})?)#ms', $levelTextClean, $matches, PREG_SET_ORDER);
   foreach($matches as $match)
   {
    $text = $match[0];
    $lat = $match[2];
    $lon = $match[3];
    // Костыли для отсечения дерьма
    if(strlen($lat)<5)
      continue;
    if(strlen($lon)<5)
      continue;
       
    $address = geocoder($lat, $lon);
    // Сразу схемы нельзя отправлять из-за ограничений телеграмма.
    //$address .= "\n<a href=\"yandexmaps://build_route_on_map/?lat_to=$lat&lon_to=$lon\">яндекс</a> <a href=\"comgooglemaps://?daddr=$lat,$lon&zoom=12&directionsmode=driving\">google</a>";
    $links = "\n<a href='http://bots.svk.su/geo.php?map=yandex&lat=$lat&lon=$lon'>яндекс</a> <a href='http://bots.svk.su/geo.php?map=google&lat=$lat&lon=$lon'>google</a>";
    $result[] = Array('lat' => $lat, 'lon' => $lon, 'text' => $text, 'address' => $address, 'links' => $links);
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

function getSettingsButtons($chat_id)
{
  $buttons = Array(
      Array(
          Array('text' => 'Прием стандартных кодов без префикса: '.(get_setting('noprefix', $chat_id) == 'true' ? '✅' : '🚫'), 'callback_data' => '/noprefix '.$chat_id),
      ),
      Array(
          Array('text' => 'Не принимать код без примечания: '.(get_setting('nocomment', $chat_id) == 'true' ? '✅' : '🚫'), 'callback_data' => '/nocomment '.$chat_id),
      )
  );
  return $buttons;
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
  $geocoder = @file_get_contents($url);
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
  $html = new Html2Text((string)$text);
  return  $html->getText();
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

function navi($lat1,$lon1,$lat2,$lon2,$engine='yandex',$expire = 600)
{
  global $db;
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
        $url = 'https://geointernal.mob.maps.yandex.net/v1/router?rll='.$lon1.','.$lat1.'~'.$lon2.','.$lat2.'&output=time&mode=jams&_='.time();
        $xml = @file_get_contents($url);
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
        $json = @file_get_contents($url);
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

function screenshot($sendFlag, $chat_id, $cookies, $domain, $gameid, $lastlevelid)
{
  $url = 'http://'.$domain.'/gameengines/encounter/play/'.$gameid.'?lang=ru';
  $filename = "screens/".$chat_id.".$gameid.".intval($lastlevelid).".".microtime(true).'.png';
  // Получаем нужные куки
  $cookies = (string)$cookies;
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


function apiRequestWebhook($method, $parameters) {
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

  header("Content-Type: application/json");
  echo json_encode($parameters);
  return true;
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
    // do not wat to DDOS server if something goes wrong
    sleep(10);
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
      throw new Exception('Invalid access token provided');
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