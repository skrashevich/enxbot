<?php
require_once 'Html2Text.php';

function auth($domain,$login,$pass)
{
    $post = Array(
        'Login' => $login,
        'Password' => $pass,
    );

    $ch = curl_init('http://'.$domain.'/login/signin/?return=%2f');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_HEADER, true);

    // execute!
    $response = curl_exec($ch);

    // close the connection, release resources used
    curl_close($ch);
    
    list($h1, $header, $body) = explode("\r\n\r\n", $response, 3);

    $cookies = Array();
    $rawcookies = '';

    $authflag = false;

    $hlines = explode("\n",$header);
    foreach($hlines as $line)
    {
        $line = trim($line);
        if(strpos($line,'Set-Cookie: ')===0)
        {
            list($hren, $cookie) = explode(': ',$line,2);
            list($cookie,$hren) = explode('; ',$cookie,2);
            $rawcookies.="$cookie; ";
            list($cookiename,$cookieval) = explode('=', $cookie,2);

            $cookies[$cookiename]=$cookieval;
            if($cookiename == 'atoken')
            {
                $authflag = true;
            }
        }
    }

    if($authflag) // идем на второй этап авторизации
    {
      $ch = curl_init('http://'.$domain.'/login/checkcookie?return=%252f');
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
      curl_setopt($ch, CURLOPT_HEADER, true);
      curl_setopt($ch, CURLOPT_COOKIE, $rawcookies);

      // execute!
      $response = curl_exec($ch);

      // close the connection, release resources used
      curl_close($ch);
      
      list($h1, $header, $body) = explode("\r\n\r\n", $response, 3);

      $hlines = explode("\n",$header);
      foreach($hlines as $line)
      {
          $line = trim($line);
          if(strpos($line,'Set-Cookie: ')===0)
          {
              list($hren, $cookie) = explode(': ',$line,2);
              list($cookie,$hren) = explode('; ',$cookie,2);
              list($cookiename,$cookieval) = explode('=', $cookie,2);

              $cookies[$cookiename]=$cookieval;
              if($cookiename == 'stoken')
              {
                  $authflag = true;
              } else
              {
                  $authflag = false;
              }
          }
      }
    }

    $rawcookies = '';
    foreach($cookies as $k=>$v)
    {
        $rawcookies .= "$k=$v; ";
    }

    return $authflag ? $rawcookies : false;
}

function testGame($cookies,$domain,$gameid)
{
  //

  $ch = curl_init('http://'.$domain.'/gameengines/encounter/play/'.$gameid.'?lang=ru');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $cookies);

    $response = curl_exec($ch);

    // close the connection, release resources used
    curl_close($ch);

    // Если короткий ответ, значит это заглушка-перенаправление
    if(strlen($response)<170)
    {
        return "У команды бота нет доступа к игре";
    }
    // Вычленяем название игры

    preg_match('#<a href="/games/details/'.$gameid.'/">(.*)</a>#',$response,$matches);

    $levelName = $matches[1];

    return $levelName;
}

function getLevelText($cookies,$domain,$gameid)
{
  $ch = curl_init('http://'.$domain.'/gameengines/encounter/play/'.$gameid.'?lang=ru');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $cookies);

    $response = curl_exec($ch);

    // close the connection, release resources used
    curl_close($ch);

    // Вычленяем текст задания

    preg_match('#<h3>Задание</h3>.*?<p>(.*?)(<h3|<div)#ms',$response,$matches);

    $levelText = $matches[1];

    preg_match('#<h2>Уровень <span>(\d+)</span> из (\d+).*?</h2>#', $response, $matches);
    $levelNum = $matches[1];
    $levelTotal = $matches[2];

    $text_clean = html2text($levelText);
    
    $result = "<b>Уровень $levelNum из $levelTotal</b>\n$text_clean";

    return $result;
}

function getHints($cookies,$domain,$gameid,$onlyOpen=false)
{
  $ch = curl_init('http://'.$domain.'/gameengines/encounter/play/'.$gameid.'?lang=ru');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $cookies);

    $response = curl_exec($ch);

    // close the connection, release resources used
    curl_close($ch);

    $hints = Array();

    // Вычленяем подсказки

    if(!$onlyOpen)
    {
      preg_match_all('#<span class="color_dis"><b>Подсказка&nbsp;([0-9]+)</b>&nbsp;будет через&nbsp;<span class="bold_off color_dis" id="time[0-9]*?">(.*?)</span><script type="text/javascript">.*?"StartCounter":([0-9]+),.*?</script>#ms',$response,$matches,PREG_SET_ORDER);
      foreach($matches as $match)
      {
        $hint = $match[1];
        $remain = $match[2];
        $remain_sec = $match[3];

        $hints[$hint]['text'] = "До открытия $remain";
        $remains[$hint] = $remain_sec;
        $hints[$hint]['remain'] = $remain_sec;
      }
    }

    preg_match_all('#<h3>Подсказка ([0-9]+)</h3>(.*?)</p>#sm',$response,$matches,PREG_SET_ORDER);
    foreach($matches as $match)
    {
      $hint = $match[1];
      $text = trim($match[2]);

      $text = html2text($text);

      $hints[$hint] = $text;
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

    preg_match('#<strong>Автопереход</strong> на следующий уровень через&nbsp;<span class="bold_off timer" id="time[0-9]*">(.*?)</span><script type="text/javascript">.*?"StartCounter":([0-9]+),.*?</script>?#ms',$response,$matches);
    if($matches)
    {
      $UPtime = $matches[1];
      $UPsecs = $matches[2];
    } else {
      $UPsecs = 0;
    }

    // Вычленяем LevelId
    preg_match('#<input type="hidden" name="LevelId" value="(\d+)" />#',$response,$matches);
    $levelId = $matches[1];
    if(!$levelId)
      $levelId=-1;

    $array['result'] = $result;
    $array['remains'] = $remains;
    $array['UPsecs'] = $UPsecs;
    $array['levelid'] = $levelId;
    $array['hints'] = $hints;

    return $array;
}

function getScheme($cookies,$city,$gamepin)
{
    $ch = curl_init('http://'.$domain.'/gameengines/encounter/play/'.$gameid.'?lang=ru');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $cookies);

    $response = curl_exec($ch);

    // close the connection, release resources used
    curl_close($ch);
    
    // Вычленяем текст задания

    preg_match('#<img src="(.*?)"#ms',$response,$matches);

    $imgLink = $matches[1];

    return $imgLink;
}

function sendCode($cookies,$domain,$gameid,$code)
{
    $ch = curl_init('http://'.$domain.'/gameengines/encounter/play/'.$gameid);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $cookies);

    $response = curl_exec($ch);

    // close the connection, release resources used
    curl_close($ch);

    // Вычленяем LevelId
    preg_match('#<input type="hidden" name="LevelId" value="(\d+)" />#',$response,$matches);

    $levelId = $matches[1];

    // Вычленяем LevelNumber
    preg_match('#<input type="hidden" name="LevelNumber" value="(\d+)" />#',$response,$matches);

    $levelNumber = $matches[1];

    // Работаем с открытымибонусами
    preg_match_all('#<h3 class="color_correct">(.*?)Бонус (\d+):(.*?)<span class="color_sec">\((.*?)\)</span>.*?<p>(.*?)</p>#mis', $response, $matches, PREG_SET_ORDER);
    $Bonuses = Array();
    foreach($matches as $k=>$v)
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
    foreach($matches as $k=>$v)
    {
      $Bonuses[$v[2]] = Array(
        'open' => false,
        'name' => trim($v[3]),
        'status' => '',
        'text' => ''
      );
    }

    unset($ch,$response);

    // Отправляем код

    $post = Array(
        'LevelId' => $levelId,
        'LevelNumber' => $levelNumber,
        'LevelAction.Answer' => $code,
    );

    $ch = curl_init('http://'.$domain.'/gameengines/encounter/play/'.$gameid.'?lang=ru');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $cookies);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

    // execute
    $response = curl_exec($ch);

    // close the connection, release resources used
    curl_close($ch);

    preg_match('#<span class="color_[i]?[n]?correct".*?>(.*)</span>#',$response,$matches);

    $result = html2text(str_replace('&quot;','', $matches[1]));

    // Если вдруг закончили игру
    if( preg_match('#<center class="gameCongratulation">(.*)</center>#ms', $response,$matches) )
    {
      $result = html2text($matches[1]);
      $array['result'] = $result;
      $array['levelid'] = '-1';
      $array['UP'] = false;

      return $array; // Если закончили игру, то всё не имеет смысла
    }

    // Проверяем на АП
    // Вычленяем LevelId
    preg_match('#<input type="hidden" name="LevelId" value="(\d+)" />#',$response,$matches);

    if($levelId != $matches[1])
    {
        $array['result'] = $result;
        $array['levelid'] = $matches[1];
        $array['UP'] = true;

        return $array; // Если апнулись, то остальное не имеет смысла
    }

    // Считаем сектора
    preg_match('#<h3>.*На уровне ([0-9]*) сектор.*?<span class="color_sec">\(осталось закрыть ([0-9]*)\)</span>#ms',$response, $matches);
    
    if($matches) // Если есть результаты - значит на уровне есть сектора
    {
      $sectors_total = $matches[1];
      $sectors_rem = $matches[2];

      $sectors_done = $sectors_total-$sectors_rem;
    
      $result .= " ($sectors_done/$sectors_total)";
    }

    // Работаем с вновь открытымибонусами
    preg_match_all('#<h3 class="color_correct">(.*?)Бонус (\d+):(.*?)<span class="color_sec">\((.*?)\)</span>.*?<p>(.*?)</p>#mis', $response, $matches, PREG_SET_ORDER);
    foreach($matches as $k=>$v)
    {
      if($Bonuses[$v[2]]['open'] == false) // Если бонус был не открыт
      {
        $result .= "\nОткрылся бонус ".trim($v[3]).": ".html2text($v[5])." ($v[4])";
      }
    }


    $array['result'] = $result;
    $array['levelid'] = $levelId;
    $array['UP'] = false;

    return $array;
}

function parseCode($text, $chat_id, $sender, $location=Array())
{
  global $db;
  $sender = mysqli_escape_string($db, $sender);
  $sql = "SELECT * FROM games WHERE chat_id = $chat_id";
  $sqlresult = mysqli_query($db, $sql);
  $settings = mysqli_fetch_assoc($sqlresult);
  $ch=mb_substr($text,0,1);
  if(!$settings['status'])
  {
    $result = "Нет активной игры";
  } elseif($chat_id > 0)
  {
      // Если код пришел в личку, то ругаемся
      $result = "Отправка кодов возможна только в публичные чаты";
  } elseif( ($settings['status']==2) || ($settings['status']==4) )
  {
    // Заносим код в лог но не пробиваем его
    $code=mysqli_escape_string($db, substr($text,1));
    list($code,$comment) = explode('//', $code, 2);
    // Если не принимать код без комментария и комментария нет
    if(get_setting('nocomment', $chat_id)=='true' && strlen($comment)==0)
    {
      $result = "Прием кода возможен только с комментарием";
      return $result;
    }
    if($settings['status']==4) // Если включен режим геокодов, без пробития в двигло
    {
      if(isset($location['lat']) && isset($location['lon']))
      {
        $comment .= " ($location[lat], $location[lon])";
      } else
      {
        $result = "Прием кодов возможен только с геолокацией";
        return $result;
      }
    }
    $sql = "INSERT INTO codeslog (chat_id, level, code, comment, `time`, sender, `return`) VALUES
            (
                $chat_id, ".intval($settings['last_level_id']).", '".mysqli_escape_string($db, $code)."', '".mysqli_escape_string($db, $comment)."', ".time().", '$sender', 'NOT SENDED'
            )";
    mysqli_query($db, $sql);
    $result = "Код записан, но не передан в движок";
  } else // если status = 1 или 3
  {
      $code=substr($text,1);
      // Пробиваем в движок все до символов //
      list($code,$comment) = explode('//', $code, 2);
      // Если не принимать код без комментария и комментария нет
      if(get_setting('nocomment', $chat_id)=='true' && strlen($comment)==0)
      {
        $result = "Прием кода возможен только с комментарием";
        return $result;
      }
      
      // Обрабатываем геокоды
      if($settings['status']==3) // Если включен режим геокодов
      {
        if(isset($location['latitude']) && isset($location['longitude']))
        {
          $comment .= " ($location[lat], $location[lon])";
        } else
        {
          $result = "Прием кодов возможен только с геолокацией";
          return $result;
        }
      }
      if(!$settings["cookies"])
      {
        $cookies = auth($settings["game_domain"], $settings["game_login"], $settings["game_pass"]);
        $sql = "UPDATE games SET cookies = '".mysqli_escape_string($db, $cookies)."' WHERE chat_id = $settings[chat_id]";
        mysqli_query($db, $sql);
      }
      else
      {
        $cookies = $settings["cookies"];
      }
      if($cookies===false)
      {
        $result = "Не проходит авторизация на игровом движке";
      } else
      {
          $sectorstmp = getSectors($settings['cookies'],$settings["game_domain"],$settings["game_id"]);
          $sectorsBefore = $sectorstmp['sectors'];
          $array = sendCode($cookies,$settings["game_domain"],$settings["game_id"],$code);
          
          
          $result = $array['result'];
        
        
        $levelId = $array['levelid'];
        $sql = "UPDATE games SET last_level_id = ".intval($levelId)." WHERE chat_id = $settings[chat_id]";
        mysqli_query($db, $sql);
        $sql = "INSERT INTO codeslog (chat_id, level, code, comment, `time`, sender, `return`) VALUES
                (
                  $chat_id, ".intval($levelId).", '".mysqli_escape_string($db, $code)."', '".mysqli_escape_string($db, $comment)."', ".time().", '$sender', '".mysqli_escape_string($db, $array['result'])."'
                )";
        mysqli_query($db, $sql);

        $sectorstmp = getSectors($settings['cookies'],$settings["game_domain"],$settings["game_id"]);
        $sectorsAfter = $sectorstmp['sectors'];

        foreach($sectorsAfter as $num => $code)
        {
            if($sectorsBefore[$num])
            {
                if($sectorsBefore[$num]['found'] < $code['found']) // Мы открыли код
                {
                    $sql = "UPDATE codes SET code_status = 1 WHERE code_number = $num AND chat_id = $settings[chat_id] AND level = $levelId";
                    mysqli_query($db, $sql);
                }
            }
        }
      }
    }
    return $result;
}

function getSectors($cookies,$domain,$gameid)
{
    $ch = curl_init('http://'.$domain.'/gameengines/encounter/play/'.$gameid);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $cookies);

    $response = curl_exec($ch);

    // close the connection, release resources used
    curl_close($ch);

    // Считаем сектора
    preg_match('#<h3>.*На уровне ([0-9]*) сектор#ms',$response, $matches);
    
    if($matches) // Если есть результаты - значит на уровне есть сектора
    {
      $sectors_total = $matches[1];
      preg_match('#<h3>.*На уровне ([0-9]*) сектор.*?<span class="color_sec">\(осталось закрыть ([0-9]*)\)</span>#ms', $response, $matches);
      $sectors_rem = intval($matches[2]);

      if($sectors_rem == 0)
        $sectors_rem = $sectors_total;

      $sectors_done = $sectors_total-$sectors_rem;
    
      $result['text'] = "На уровне $sectors_total сектора. Закрыто $sectors_done. Осталось закрыть $sectors_rem";

      // Закрытые сектора
      preg_match_all('#<p>(\d+): <span class="color_correct">(.*?)</span> <span class="color_sec">\((.*?) <a href=".*?">(.*?)</a>\)</span></p>#ms', $response, $matches, PREG_SET_ORDER);
      foreach($matches as $match)
      {
        $num = $match[1];
        $code = $match[2];

        $result['sectors'][$num]['found'] = true;
        $result['sectors'][$num]['code'] = $code;
      }
      // Открытые сектора
      preg_match_all('#<p>(\d+): <span class="color_dis">код не введён</span></p>#', $response, $matches, PREG_SET_ORDER);
      foreach($matches as $match)
      {
        $num = $match[1];
        $code = $match[2];

        $result['sectors'][$num]['found'] = false;
      }

      ksort($result['sectors'], SORT_NUMERIC);
    } else
    {
      $result['text'] = "На уровне нет разделения по секторам";
    }

    return $result;
}

function getMessages($cookies,$domain,$gameid)
{
    $ch = curl_init('http://'.$domain.'/gameengines/encounter/play/'.$gameid);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $cookies);

    $response = curl_exec($ch);

    // close the connection, release resources used
    curl_close($ch);

    // Получаем сообщения
    preg_match('#<p class="globalmess">(.*?)</p>#ms',$response, $matches);
    
    if($matches) // Если есть результаты - значит на уровне есть сектора
    {
      $messages = $matches[1];

      $messages = explode('<br />', $messages);

      for($i=0;$i<count($messages);$i++)
      {
        $messages[$i] = html2text($messages[$i]);
      }
    } else
    {
      $messages = Array('Нет сообщений организатора');
    }

    return $messages;
}

function getCoordsFromText($text)
{
  $result = Array();
   // Ищем в тексте координаты
   $levelTextClean = html2text($text);
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
  $name = mysqli_escape_string($db, $name);
  $sql="SELECT * FROM settings WHERE chat_id = $chat_id AND name = '$name' LIMIT 1";
  $result = mysqli_query($db, $sql);
  if(mysqli_num_rows($result)==0)
  {
    return false;
  }
  $row = mysqli_fetch_assoc($result);
  $value = $row['value'];
  return $value;
}
function set_setting($name,$value,$chat_id=0)
{
  global $db;
  $chat_id = intval($chat_id);
  if(!get_setting($name, $chat_id))
  {
    $sql = "INSERT INTO settings (chat_id,name,value) VALUES ($chat_id, '".mysqli_escape_string($db, $name)."', '".mysqli_escape_string($db, $value)."')";
    mysqli_query($db, $sql);
  } else
  {
    $sql = "UPDATE settings SET value = '".mysqli_escape_string($db, $value)."' WHERE name = '".mysqli_escape_string($db, $name)."' AND chat_id = $chat_id";
    mysqli_query($db, $sql);
  }
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
  // Проверяем наличие координат в кэше
  $sql = "SELECT * FROM geocache WHERE lat=$lat AND lon=$lon";
  $result = mysqli_query($db, $sql);
  if(mysqli_num_rows($result)>0)
  {
    $cacherow = mysqli_fetch_assoc($result);
    $address = $cacherow['address'];
  } else
  {
    // Геокодирование адреса
    $url = "https://geocode-maps.yandex.ru/1.x/?format=json&sco=latlong&geocode=$lat,$lon";
    $geocoder = file_get_contents($url);
    $geodata = json_decode($geocoder, true);
                        
    $address = $geodata['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['metaDataProperty']['GeocoderMetaData']['text'];
    // Добавляем адрес в кэш
    $sql = "INSERT INTO geocache (lat,lon,added,address) VALUES ($lat, $lon, ".time().", '".mysqli_escape_string($db, $address)."')";
    mysqli_query($db, $sql);
  }
  return $address;
}

function html2text($text)
{
  $html = new Html2Text($text);
  return  $html->getText();
}


function gameSettingsbyUser($user_id)
{
  global $db;
  $settings = Array();
  
  $sql = "SELECT * FROM games WHERE status>0 AND last_level_id >= 0";
  $result = mysqli_query($db, $sql);
  while($row = mysqli_fetch_assoc($result))
  {
    $bresult = apiRequestJSON("getChatMember", array('chat_id' => $row['chat_id'], "user_id" => $user_id));
    $chatMember = $bresult;
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
  $sqlresult = mysqli_query($db, $sql);
  if(mysqli_num_rows($sqlresult)>0)
  {
      $row = mysqli_fetch_assoc($sqlresult);
      $result = Array(
        'length' => $row['length'],
        'time' => $row['time'],
        'url' => 'cached'
      );
  } else
  {
    switch($engine)
    {
      case 'yandex':
        $url = 'https://geointernal.mob.maps.yandex.net/v1/router?rll='.$lon1.','.$lat1.'~'.$lon2.','.$lat2.'&output=time&mode=jams&_='.time();
        $xml = file_get_contents($url);
        preg_match('#<r:length>([0-9\.]*?)</r:length>#', $xml, $matches);
        $length = $matches[1];
        preg_match('#<r:time>([0-9\.]*?)</r:time>#', $xml, $matches);
        $time = $matches[1];
        $result = Array(
          'length' => $length,
          'time' => $time,
          'url' => $url
        );
      break;
      case 'google':
        $url = 'https://maps.googleapis.com/maps/api/directions/json?origin='.$lat1.','.$lon1.'&destination='.$lat2.','.$lon2;
        $json = file_get_contents($url);
        $data = json_decode($json, true);
        if($data['status']=='OK')
        {
          $length = $data['routes'][0]['legs'][0]['distance']['value'];
          $time = $data['routes'][0]['legs'][0]['duration']['value'];
        }
        $result = Array(
          'length' => $length,
          'time' => $time,
          'url' => $url
        );
      break;
      default:
        return false;
      break;
    }
    $sql = "INSERT INTO directionscache (lat1,lon1,lat2,lon2,added,length,time)
    VALUES ($lat1, $lon1, $lat2, $lon2,".time().",$length,$time)";
    mysqli_query($db,$sql);
  }
  return $result;
}

function screenshot($sendFlag, $chat_id, $cookies, $domain, $gameid, $lastlevelid)
{
  $url = 'http://'.$domain.'/gameengines/encounter/play/'.$gameid.'?lang=ru';
  $filename = "screens/".$chat_id.".$gameid.".intval($lastlevelid).".".microtime(true).'.png';
  // Получаем нужные куки
  preg_match('#atoken=(.*?);#', $cookies, $matches);
  $atoken = $matches[1];
  preg_match('#stoken=(.*?);#', $cookies, $matches);
  $stoken = $matches[1];
  preg_match('#GUID=(.*?);#', $cookies, $matches);
  $guid = $matches[1];
  preg_match('#Domain=(.*?);#', $cookies, $matches);
  $domaincookie = $matches[1];

  if(!file_exists(PHANTOMJS))
  {
    if($sendFlag)
    {
      apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => "Ошибка: не найден phantomjs"));
    }
    return false;
  } else
  {
    exec(PHANTOMJS.' enscreen.js "'.$url.'" "'.$filename.'" "'.$guid.'" "'.$stoken.'" "'.$domaincookie.'" "'.$atoken.'"');
    if($sendFlag)
    {
      if(file_exists($filename))
      {
        apiRequestPOST("sendPhoto", array('chat_id' => $chat_id, "photo" => '@'.$filename));
      } else
      {
        apiRequestJSON("sendMessage", array('chat_id' => $chat_id, "text" => "Ошибка: не удалось получить скриншот"));
        return '';
      }
    }
    return $filename;
  }
}



//
// Telegram Bot Functions
//

function logMessage($message, $type=0)
{
    global $db;
    $sql = "INSERT INTO log (time, message_id, chat_id, chat_title, text, type, sender_id, sender_username)
    VALUES (
        ".time().",
        $message[message_id],
        ".$message['chat']['id'].",
        '".mysqli_escape_string($db, $message['chat']['title'])."',
        '".mysqli_escape_string($db, $message['text'])."',
        $type,
        ".$message['from']['id'].",
        '".mysqli_escape_string($db, $message['from']['username'])."'
    )
    ";
    mysqli_query($db, $sql);
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
  curl_setopt($handle, CURLOPT_SAFE_UPLOAD, false);
  curl_setopt($handle, CURLOPT_POSTFIELDS, $parameters);
  //curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-Type: multipart/form-data"));

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
    curl_close($handle);
    return false;
  }

  $http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));
  curl_close($handle);

  if ($http_code >= 500) {
    // do not wat to DDOS server if something goes wrong
    sleep(10);
    return false;
  } else if ($http_code != 200) {
    $response = json_decode($response, true);
    error_log("Request has failed with error {$response['error_code']}: {$response['description']}\n");
    if ($http_code == 401) {
      throw new Exception('Invalid access token provided');
    }
    return false;
  } else {
    $response = json_decode($response, true);
    if (isset($response['description'])) {
      error_log("Request was successfull: {$response['description']}\n");
    }
    $response = $response['result'];
  }

  return $response;
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

  foreach ($parameters as $key => &$val) {
    // encoding to JSON array parameters, for example reply_markup
    if (!is_numeric($val) && !is_string($val)) {
      $val = json_encode($val);
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

function sendMap($chat_id,$map=""){
    $bot_url    = "https://api.telegram.org/bot***REMOVED-TOKEN***/";
    $url        = $bot_url . "sendPhoto?chat_id=" . $chat_id ;

    $post_fields = array('chat_id'   => $chat_id,
        'photo'     => new CURLFile(realpath("https://static-maps.yandex.ru/1.x/?ll=37.620070,55.753630&size=450,450&z=13&l=map&pt=37.620070,55.753630,pmwtm1~37.64,55.76363,pmwtm99"))
    );

    $ch = curl_init(); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type:multipart/form-data"
    ));
    curl_setopt($ch, CURLOPT_URL, $url); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields); 
    $output = curl_exec($ch);
}

/**
 * Returns an encrypted & utf8-encoded
 */
function encrypt($pure_string, $encryption_key) {
    $iv_size = mcrypt_get_iv_size(MCRYPT_BLOWFISH, MCRYPT_MODE_ECB);
    $iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
    $encrypted_string = mcrypt_encrypt(MCRYPT_BLOWFISH, $encryption_key, utf8_encode($pure_string), MCRYPT_MODE_ECB, $iv);
    return base64_encode($encrypted_string);
}

/**
 * Returns decrypted original string
 */
function decrypt($encrypted_string, $encryption_key) {
    $iv_size = mcrypt_get_iv_size(MCRYPT_BLOWFISH, MCRYPT_MODE_ECB);
    $iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
    $decrypted_string = mcrypt_decrypt(MCRYPT_BLOWFISH, $encryption_key, base64_decode($encrypted_string), MCRYPT_MODE_ECB, $iv);
    return $decrypted_string;
}