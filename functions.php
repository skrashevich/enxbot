<?php
require_once 'HTMLPurifier.standalone.php';
$htmlpurconfig = HTMLPurifier_Config::createDefault();
$htmlpurconfig->set('HTML', 'Allowed', '');
$purifier = new HTMLPurifier($htmlpurconfig);

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

    $cookies = '';

    $authflag = false;

    $hlines = explode("\n",$header);
    foreach($hlines as $line)
    {
        $line = trim($line);
        if(strpos($line,'Set-Cookie: ')===0)
        {
            list($hren, $cookie) = explode(': ',$line,2);
            list($cookie,$hren) = explode('; ',$cookie,2);

            $cookies.="$cookie; ";
            if(substr_count($cookie,'atoken'))
            {
                $authflag = true;
            }
        }
    }

    return $authflag ? $cookies : false;
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
  global $purifier;

  $ch = curl_init('http://'.$domain.'/gameengines/encounter/play/'.$gameid.'?lang=ru');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $cookies);

    $response = curl_exec($ch);

    // close the connection, release resources used
    curl_close($ch);

    // Вычленяем текст задания

    preg_match('#<h3>Задание</h3>.*?<p>(.*?)(<h3|<div)#ms',$response,$matches);

    $levelText = $matches[1];

    preg_match('#<h2>Уровень <span>(\d+)</span> из (\d+)</h2>#', $response, $matches);
    $levelNum = $matches[1];
    $levelTotal = $matches[2];

    $text_clean = $purifier->purify($levelText);
    
    $result = "<b>Уровень $levelNum из $levelTotal</b>\n$text_clean";

    return $result;
}

function getHints($cookies,$domain,$gameid)
{
  global $purifier;

  $ch = curl_init('http://'.$domain.'/gameengines/encounter/play/'.$gameid.'?lang=ru');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $cookies);

    $response = curl_exec($ch);

    // close the connection, release resources used
    curl_close($ch);

    $hints = Array();

    // Вычленяем подсказки

    preg_match_all('#<span class="color_dis"><b>Подсказка&nbsp;([0-9]+)</b>&nbsp;будет через&nbsp;<span class="bold_off color_dis" id="time[0-9]*?">(.*?)</span><script type="text/javascript">.*?"StartCounter":([0-9]+),.*?</script>#ms',$response,$matches,PREG_SET_ORDER);
    foreach($matches as $match)
    {
      $hint = $match[1];
      $remain = $match[2];
      $remain_sec = $match[3];

      $hints[$hint] = "До открытия $remain";
      $remains[$hint] = $remain_sec;
    }

    preg_match_all('#<h3>Подсказка ([0-9]+)</h3>(.*?)</p>#sm',$response,$matches,PREG_SET_ORDER);
    foreach($matches as $match)
    {
      $hint = $match[1];
      $text = trim($match[2]);

      $text = $purifier->purify($text);

      $hints[$hint] = $text;
    }

    $result = '';
    ksort($hints);
    foreach($hints as $hint=>$text)
    {
        $result .= "*$hint*: `$text`\n";
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

    return $array;
}

function sendCode($cookies,$domain,$gameid,$code)
{
    global $purifier;
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

    $result = $purifier->purify(str_replace('&quot;','', $matches[1]));

    // Если вдруг закончили игру
    if( preg_match('#<center class="gameCongratulation">(.*)</center>#ms', $response,$matches) )
    {
      $result = $purifier->purify($matches[1]);
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
    preg_match('#<h3>.*На уровне ([0-9]*) сектора.*?<span class="color_sec">\(осталось закрыть ([0-9]*)\)</span>#ms',$response, $matches);
    
    if($matches) // Если есть результаты - значит на уровне есть сектора
    {
      $sectors_total = $matches[1];
      $sectors_rem = $matches[2];

      $sectors_done = $sectors_total-$sectors_rem;
    
      $result .= " ($sectors_done/$sectors_total)";
    }

    $array['result'] = $result;
    $array['levelid'] = $levelId;
    $array['UP'] = false;

    return $array;
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
    preg_match('#<h3>.*На уровне ([0-9]*) сектора#ms',$response, $matches);
    
    if($matches) // Если есть результаты - значит на уровне есть сектора
    {
      $sectors_total = $matches[1];
      preg_match('#<h3>.*На уровне ([0-9]*) сектора.*?<span class="color_sec">\(осталось закрыть ([0-9]*)\)</span>#ms', $response, $matches);
      $sectors_rem = intval($matches[2]);

      if($sectors_rem == 0)
        $sectors_rem = $sectors_total;

      $sectors_done = $sectors_total-$sectors_rem;
    
      $result = "На уровне $sectors_total сектора. Закрыто $sectors_done. Осталось закрыть $sectors_rem";
    } else
    {
      $result = "На уровне нет разделения по секторам";
    }

    return $result;
}

function getMessages($cookies,$domain,$gameid)
{
    global $purifier;

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
        $messages[$i] = $purifier->purify($messages[$i]);
      }
    } else
    {
      $messages = Array('Нет сообщений организатора');
    }

    return $messages;
}

function getCoordsFromText($text)
{
  global $purifier;
  $result = Array();
   // Ищем в тексте координаты
   $levelTextClean = $purifier->purify($text);
   preg_match_all('#(.*?)[\s:,;\.](-?[1-8]?\d(?:\.\d{1,8})?|90(?:\.0{1,8})?)[,\s]+?(-?(?:1[0-7]|[1-9])?\d(?:\.\d{1,8})?|180(?:\.0{1,8})?)#', $levelTextClean, $matches, PREG_SET_ORDER);
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

    // Геокодирование адреса
    $url = "https://geocode-maps.yandex.ru/1.x/?format=json&sco=latlong&geocode=$lat,$lon";
    $geocoder = file_get_contents($url);
    $geodata = json_decode($geocoder, true);
                        
    $address = $geodata['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['metaDataProperty']['GeocoderMetaData']['text'];

    $address .= "\n<a href='yandexmaps://build_route_on_map/?lat_to=$lat&lon_to=$lon'>яндекс</a> <a href='comgooglemaps://?daddr=$lat,$lon&zoom=12&directionsmode=driving'>google</a>";

    $result[] = Array('lat' => $lat, 'lon' => $lon, 'text' => $text, 'address' => $address);
   }

   return $result;
}




//
// Telegram Bot Functions
//

function logMessage($message, $type=0)
{
    $sql = "INSERT INTO log (time, message_id, chat_id, chat_title, text, type, sender_id, sender_username)
    VALUES (
        ".time().",
        $message[message_id],
        ".$message['chat']['id'].",
        '".mysql_escape_string($message['chat']['title'])."',
        '".mysql_escape_string($message['text'])."',
        $type,
        ".$message['from']['id'].",
        '".mysql_escape_string($message['from']['username'])."'
    )
    ";
    mysql_query($sql);
    return true;
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