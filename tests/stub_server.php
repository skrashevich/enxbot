<?php
/*
    Заглушка внешних сервисов для смоук-теста:
      /login/signin/, /login/checkcookie   - авторизация на движке Encounter
      /gameengines/encounter/play/<id>     - страница игры
      /tg/<method>                         - Telegram Bot API

    Запускается как router-скрипт встроенного веб-сервера PHP:
      php -S 127.0.0.1:18081 tests/stub_server.php
*/

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/route') {
    header('Content-Type: application/xml');
    if (str_starts_with((string)($_GET['rll'] ?? ''), '0,')) {
        http_response_code(503);
        echo 'unavailable';
    } else {
        echo '<route xmlns:r="urn:route"><r:length>12500</r:length><r:time>900</r:time></route>';
    }
    return true;
}

//
// Авторизация на движке
//
if (strpos($path, '/login/signin') === 0) {
    header('Set-Cookie: atoken=AAA111; path=/', false);
    header('Set-Cookie: GUID=GGG222; path=/', false);
    header('Set-Cookie: Domain=demo.en.cx; path=/', false);
    echo "signin ok";
    return true;
}

if (strpos($path, '/login/checkcookie') === 0) {
    header('Set-Cookie: stoken=SSS333; path=/');
    echo "checkcookie ok";
    return true;
}

//
// Геокодер (формат ответа Яндекса)
//
if (strpos($path, '/geocode') === 0) {
    header('Content-Type: application/json');
    echo json_encode(array('response' => array('GeoObjectCollection' => array('featureMember' => array(
        array('GeoObject' => array('metaDataProperty' => array('GeocoderMetaData' => array(
            'text' => 'Россия, Москва, Красная площадь',
        )))),
    )))));
    return true;
}

//
// Страница игры
//
if (strpos($path, '/gameengines/encounter/play/') === 0) {
    $gameid = basename($path);

    $answer = postField('LevelAction.Answer');

    header('Content-Type: text/html; charset=utf-8');
    echo enginePage($gameid, $answer);
    return true;
}

//
// Telegram Bot API
//
if (strpos($path, '/tg/') === 0) {
    $method = substr($path, 4);

    // apiRequestJSON присылает метод в теле запроса
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = array();
    }
    if (isset($body['method'])) {
        $method = $body['method'];
    } elseif (isset($_POST['method'])) {
        $method = $_POST['method'];
    }

    logTelegramCall($method, $body);

    // Режимы отказа для проверки устойчивости polling.
    // Файл-флаг, а не переменная окружения: заглушка уже запущена.
    $failMode = @file_get_contents(__DIR__.'/../.stub_fail_mode');
    if ($failMode === false) {
        $failMode = getenv('ENXBOT_STUB_FAIL_MODE');
    }
    $failMode = trim((string)$failMode);

    if ($failMode !== '' && $method === 'getUpdates') {
        if ($failMode === '401') {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(array('ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'));
            return true;
        }

        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(array('ok' => false, 'error_code' => 500, 'description' => 'Internal Server Error'));
        return true;
    }

    header('Content-Type: application/json');
    echo json_encode(array('ok' => true, 'result' => telegramResult($method, $body)));
    return true;
}

http_response_code(404);
echo 'not found';
return true;

/**
 * Читает POST-поле, имя которого содержит точку.
 * PHP заменяет точки на подчёркивания при заполнении $_POST,
 * а для multipart-запросов php://input уже пуст.
 */
function postField($field)
{
    if (isset($_POST[$field])) {
        return $_POST[$field];
    }

    $mangled = str_replace('.', '_', $field);

    return isset($_POST[$mangled]) ? $_POST[$mangled] : null;
}

/**
 * Пишет каждый вызов Telegram API в JSONL-журнал, чтобы тесты могли
 * проверять не только "не упало", но и что именно бот отправил.
 * Путь задаётся переменной окружения ENXBOT_TG_LOG.
 */
function logTelegramCall($method, $body)
{
    $logPath = getenv('ENXBOT_TG_LOG');
    if ($logPath === false || $logPath === '') {
        return;
    }

    $params = $body;
    unset($params['method']);

    // GET-запросы (apiRequest) и multipart (apiRequestPOST) кладут параметры мимо тела
    foreach ($_GET as $key => $value) {
        if (!isset($params[$key])) {
            $params[$key] = $value;
        }
    }
    foreach ($_POST as $key => $value) {
        if ($key !== 'method' && !isset($params[$key])) {
            $params[$key] = $value;
        }
    }

    $entry = json_encode(
        array('method' => $method, 'params' => $params),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    file_put_contents($logPath, $entry."\n", FILE_APPEND | LOCK_EX);
}

function telegramResult($method, $body = array())
{
    switch ($method) {
        case 'getUpdates':
            $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
            if ($offset <= 9001) {
                return array(array(
                    'update_id' => 9001,
                    'message' => array(
                        'message_id' => 9001,
                        'chat' => array('id' => -1001, 'title' => 'Polling'),
                        'from' => array('id' => 7, 'username' => 'rootadmin'),
                        'text' => '/help',
                    ),
                ));
            }
            return array();
        case 'getChatMember':
            // Пользователь 555 - администратор чата, остальные - рядовые участники.
            // Так проверяется ветка прав «админ чата, но не админ бота».
            $userId = isset($body['user_id']) ? intval($body['user_id']) : 0;
            if ($userId === 0 && isset($_GET['user_id'])) {
                $userId = intval($_GET['user_id']);
            }

            return array(
                'status' => $userId === 555 ? 'administrator' : 'member',
                'user' => array('id' => $userId),
            );
        default:
            return array('message_id' => 4242, 'date' => 1700000000);
    }
}

function enginePage($gameid, $answer)
{
    // Ответ движка на введённый код
    $answerBlock = '';
    if ($answer !== null) {
        $answerBlock = $answer === 'right'
            ? '<span class="color_correct" id="answer">Код принят</span>'."\n"
            : '<span class="color_incorrect" id="answer">Код не принят</span>'."\n";
    }

    return <<<HTML
<html><head><title>Игра</title></head><body>
<a href="/games/details/$gameid/">Демонстрационная игра</a>
<h2>Уровень <span>3</span> из 12 <span class="hidden">x</span></h2>
<input type="hidden" name="LevelId" value="777" />
<input type="hidden" name="LevelNumber" value="3" />
<h3>Задание</h3>
<p>Найдите точку 55.755814, 37.617635 и введите код<div class="tail"></div>
$answerBlock
<h3>Подсказка 1</h3>
<p>Смотрите под лавочкой</p>
<span class="color_dis"><b>Подсказка&nbsp;2</b>&nbsp;будет через&nbsp;<span class="bold_off color_dis" id="time2">00:10:00</span><script type="text/javascript">var t2={"StartCounter":600,"Id":2};</script>
<strong>Автопереход</strong> на следующий уровень через&nbsp;<span class="bold_off timer" id="time9">00:30:00</span><script type="text/javascript">var t9={"StartCounter":1800,"Id":9};</script>
<h3>Сектора. На уровне 4 сектора <span class="color_sec">(осталось закрыть 2)</span></h3>
<p>1: <span class="color_correct">alpha</span> <span class="color_sec">(12:00 <a href="/u/1">Игрок1</a>)</span></p>
<p>2: <span class="color_correct">beta</span> <span class="color_sec">(12:05 <a href="/u/2">Игрок2</a>)</span></p>
<p>3: <span class="color_dis">код не введён</span></p>
<p>4: <span class="color_dis">код не введён</span></p>
<h3 class="color_correct">Открыт Бонус 1: Быстрый старт <span class="color_sec">(+10 мин)</span></h3><p>Текст бонуса</p>
<h3 class="color_bonus">Бонус 2: Закрытый бонус</h3>
<p class="globalmess">Первое сообщение<br />Второе сообщение</p>
<img src="http://127.0.0.1:18081/scheme.png" alt="схема" />
</body></html>
HTML;
}
