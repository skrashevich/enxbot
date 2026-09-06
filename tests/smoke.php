<?php
/*
    Смоук-тест функций бота на PHP 8.

    Любой Warning / Notice / Deprecated превращается в падение теста:
    именно они и составляют основную массу несовместимостей PHP 8.

    Запускается через tests/run.sh из временного каталога, где лежит config.php.
*/

error_reporting(E_ALL);
ini_set('display_errors', '1');

$GLOBALS['php_issues'] = array();

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    // Уважаем оператор подавления @: такие места подавлены намеренно
    if (!(error_reporting() & $errno)) {
        return true;
    }
    $GLOBALS['php_issues'][] = sprintf('[%d] %s в %s:%d', $errno, $errstr, basename($errfile), $errline);
    return true;
});

set_exception_handler(function ($e) {
    fwrite(STDERR, 'НЕПЕРЕХВАЧЕННОЕ ИСКЛЮЧЕНИЕ: '.$e->getMessage()."\n");
    exit(1);
});

require 'config.php';
require 'db.php';
require 'functions.php';

$failures = array();
$checks = 0;

function check($name, $condition, $details = '')
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "  ok   $name\n";
        return true;
    }
    echo "  FAIL $name".($details === '' ? '' : " ($details)")."\n";
    $failures[] = $name;
    return false;
}

$domain = '127.0.0.1:18081';
$gameid = 12345;
$chat_id = -1001;

echo "== Шифрование (openssl вместо удалённого mcrypt) ==\n";
$secret = 'Пароль движка №1';
$encrypted = encrypt($secret, ENCRYPTION_KEY);
check('encrypt() возвращает строку', is_string($encrypted) && $encrypted !== '');
check('decrypt(encrypt(x)) == x', decrypt($encrypted, ENCRYPTION_KEY) === $secret);
check('decrypt() с чужим ключом не падает', decrypt($encrypted, 'other-key') !== $secret);
check('decrypt() мусора возвращает false', decrypt('не base64 !!!', ENCRYPTION_KEY) === false);
check('decrypt(null) возвращает false', decrypt(null, ENCRYPTION_KEY) === false);
check('mcrypt не используется', !preg_match('/mcrypt_/', file_get_contents('functions.php')));

echo "== Формат сессии encx ==\n";
$cookies = encxNormalizeCookies('atoken=A; stoken=S; GUID=G; Domain=D;');
$decodedCookies = json_decode($cookies, true);
check('старые cookies переводятся в формат encx', is_array($decodedCookies) && count($decodedCookies) === 4, $cookies);
check('JSON-сессия не изменяется', encxNormalizeCookies($cookies) === $cookies);

echo "== Настройки ==\n";
check('get_setting() отсутствующей настройки', get_setting('нет-такой', $chat_id) === false);
set_setting('nocomment', 'false', $chat_id);
check('set_setting() создала настройку', get_setting('nocomment', $chat_id) === 'false');
set_setting('nocomment', 'true', $chat_id);
check('set_setting() обновила настройку', get_setting('nocomment', $chat_id) === 'true');
$dup = db_fetch_assoc(db_query($db, "SELECT COUNT(*) AS cnt FROM settings WHERE chat_id = $chat_id AND name = 'nocomment'"));
check('set_setting() не плодит дубликаты строк', $dup['cnt'] == 1, "строк: $dup[cnt]");
set_setting('nocomment', 'false', $chat_id);

echo "== Геокодирование ==\n";
check('geocoder(): адрес получен', geocoder(55.755814, 37.617635) === 'Россия, Москва, Красная площадь');
check('geocoder(): второй вызов берёт адрес из кэша', geocoder(55.755814, 37.617635) === 'Россия, Москва, Красная площадь');
$cached = db_fetch_assoc(db_query($db, "SELECT COUNT(*) AS cnt FROM geocache"));
check('geocoder(): кэш не дублируется', $cached['cnt'] == 1, "строк: $cached[cnt]");

echo "== Разбор координат ==\n";
$coords = getCoordsFromText('<p>Точка 55.755814, 37.617635 рядом</p>');
check('getCoordsFromText(): координаты найдены', count($coords) === 1 && $coords[0]['lat'] === '55.755814', var_export($coords, true));
check('getCoordsFromText(): адрес подставлен', $coords[0]['address'] === 'Россия, Москва, Красная площадь');
check('getCoordsFromText(null) не падает', getCoordsFromText(null) === array());
check('getCoordsFromText(false) не падает', getCoordsFromText(false) === array());

check('координаты: все пары, включая отрицательные и границы', count(getCoordsFromText('55.75 37.61; -90.00 -180.00; 90.00 180.00')) === 3);
foreach (array('91.00 37.00', '-91.00 37.00', '55.00 181.00', '1000.00 37.00', 'Уровень 1 из 3', '55.123456789012 37.00') as $invalid) {
    check('координаты: отклоняется '.$invalid, getCoordsFromText($invalid) === array());
}
saveLevelPoint(-801, 999, 55.75, 37.61);
check('история точек не предполагает возрастания LevelId', (float)lastLevelPoint(-801, 100)['lat'] === 55.75);
check('маршрут из заглушки', routeSummary(55.75, 37.61, 55.80, 37.70) === 'От предыдущей точки: 12.5 км, ~15 мин в пути');
check('ошибка маршрутов не выдаёт ложное расстояние', routeSummary(0, 0, 55.80, 37.70) === '');
check('публикация координат работает без инфоканала', publishCoords('55.80 37.70', -801, null, 100, true) === 1);
check('координаты нового уровня сохранены', $db->query('SELECT COUNT(*) FROM coords WHERE chat_id=-801 AND level=100')->fetchColumn() == 1);
db_query($db, 'DELETE FROM coords WHERE chat_id=-801');

echo "== Приём кодов ==\n";
// Личный чат: строка игры существует, но приём кодов в личку запрещён
db_query($db, "INSERT INTO games (chat_id, game_id, game_domain, status) VALUES (555, 12345, '127.0.0.1:18081', 1)");
check('parseCode(): в личке код не принимается', parseCode('&right', 555, 'Игрок') === 'Отправка кодов возможна только в публичные чаты');
db_query($db, "DELETE FROM games WHERE chat_id = 555");

// Сбой движка не должен затирать текущий уровень: иначе игра выпадет
// из выборки cron.php по условию last_level_id >= 0
db_query($db, "UPDATE games SET status = 1, last_level_id = 777, game_domain = '127.0.0.1:1' WHERE chat_id = $chat_id");
parseCode('&right//коммент', $chat_id, 'Игрок');
$row = db_fetch_assoc(db_query($db, "SELECT last_level_id FROM games WHERE chat_id = $chat_id"));
check('parseCode(): недоступный движок не затирает last_level_id',
    $row['last_level_id'] == 777, "стало: $row[last_level_id]");
db_query($db, "UPDATE games SET game_domain = '127.0.0.1:18081' WHERE chat_id = $chat_id");

set_setting('nocomment', 'true', $chat_id);
check('parseCode(): код без комментария отклонён', parseCode('&right', $chat_id, 'Игрок') === 'Прием кода возможен только с комментарием');
set_setting('nocomment', 'false', $chat_id);

db_query($db, "UPDATE games SET status = 2 WHERE chat_id = $chat_id");
$r = parseCode("&code'sql//комментарий", $chat_id, "Игрок O'Брайен");
check('parseCode(): статус 2 пишет код в лог без пробития', $r === 'Код записан, но не передан в движок', $r);
$logged = db_fetch_assoc(db_query($db, "SELECT * FROM codeslog WHERE chat_id = $chat_id ORDER BY id DESC LIMIT 1"));
check('parseCode(): кавычки в коде не искажены экранированием', $logged['code'] === "code'sql", var_export($logged['code'], true));

db_query($db, "UPDATE games SET status = 3 WHERE chat_id = $chat_id");
check('parseCode(): геокод без локации отклонён',
    parseCode('&right//коммент', $chat_id, 'Игрок') === 'Прием кодов возможен только с геолокацией');

db_query($db, "UPDATE games SET status = 4 WHERE chat_id = $chat_id");
$r = parseCode('&right//коммент', $chat_id, 'Игрок', array('latitude' => 55.75, 'longitude' => 37.61));
check('parseCode(): статус 4 принимает код с локацией', $r === 'Код записан, но не передан в движок', $r);

db_query($db, "UPDATE games SET status = 0 WHERE chat_id = $chat_id");
check('parseCode(): без активной игры', parseCode('&right', $chat_id, 'Игрок') === 'Нет активной игры');
check('parseCode(): неизвестный чат', parseCode('&right', -9999, 'Игрок') === 'Нет активной игры');
db_query($db, "UPDATE games SET status = 1 WHERE chat_id = $chat_id");

echo "== Логирование сообщений ==\n";
logMessage(array('message_id' => 1, 'chat' => array('id' => $chat_id), 'from' => array('id' => 7)));
$row = db_fetch_assoc(db_query($db, "SELECT * FROM log ORDER BY id DESC LIMIT 1"));
check('logMessage() переживает сообщение без text/title/username', $row['message_id'] == 1 && $row['text'] === '');

echo "== Скриншот без phantomjs ==\n";
check('screenshot() без phantomjs возвращает false',
    screenshot(false, $chat_id, 'atoken=A; stoken=S; GUID=G; Domain=D;', $domain, $gameid, 777) === false);
check('screenshot() без нужных кук не падает', screenshot(false, $chat_id, '', $domain, $gameid, 777) === false);

echo "== Синтетический сектор уровня без разбивки по секторам ==\n";
// Уровень с одним проходным кодом и бонусами: сектор №1 обязан появиться,
// иначе !всеко/!нко и синхронизация cron не видят проходной код (баг про
// "не видит коды с движка" на уровнях с бонусами).
$synthOpen = synthSectorForLevel(array(
    'RequiredSectorsCount' => 1, 'PassedSectorsCount' => 0, 'IsPassed' => false,
    'Bonuses' => array(array('Number' => 1, 'IsAnswered' => false)),
));
check('synthSectorForLevel(): проходной код + бонусы -> есть сектор №1',
    isset($synthOpen['sectors'][1]) && $synthOpen['sectors'][1]['found'] === false, var_export($synthOpen, true));
$synthDone = synthSectorForLevel(array(
    'RequiredSectorsCount' => 1, 'PassedSectorsCount' => 1, 'IsPassed' => false,
));
check('synthSectorForLevel(): введённый код помечает сектор закрытым',
    $synthDone['sectors'][1]['found'] === true, var_export($synthDone, true));
check('synthSectorForLevel(): IsPassed тоже закрывает сектор',
    synthSectorForLevel(array('RequiredSectorsCount' => 1, 'IsPassed' => true))['sectors'][1]['found'] === true);
check('synthSectorForLevel(): бонусный/экшн-уровень (Required=0) - без сектора',
    synthSectorForLevel(array('RequiredSectorsCount' => 0, 'Bonuses' => array(array('Number' => 1))))['sectors'] === array());
check('synthSectorForLevel(): снятый уровень - без сектора',
    synthSectorForLevel(array('RequiredSectorsCount' => 1, 'Dismissed' => true))['sectors'] === array());
check('synthSectorForLevel(): не-массив не роняет функцию',
    synthSectorForLevel(null)['sectors'] === array());

echo "== Списки меток и найденные метки ==\n";
$items = array(
    1 => array('found' => true,  'code' => 'en1', 'name' => ''),
    2 => array('found' => false, 'code' => '',    'name' => ''),
    3 => array('found' => false, 'code' => '',    'name' => ''),
);
$all = formatCodeList($items, 'all', 'Все метки:');
check('formatCodeList(): режим all показывает и закрытые, и открытые',
    strpos($all, '*1:') !== false && strpos($all, '_2_') !== false, $all);
check('formatCodeList(): режим open скрывает закрытые',
    strpos(formatCodeList($items, 'open', 'Незакрытые:'), '*1:') === false);
check('formatCodeList(): режим closed скрывает открытые',
    strpos(formatCodeList($items, 'closed', 'Закрытые:'), '_2_') === false);
check('formatCodeList(): закрытая метка выводится с кодом',
    formatCodeList($items, 'closed', 'Закрытые:') === "Закрытые:\n*1:\ten1*");
check('formatCodeList(): пустой результат обозначен явно',
    formatCodeList(array(), 'all', 'Все метки:') === "Все метки:\n(пусто)");
check('formatCodeList(): нечисловые ключи пропускаются',
    formatCodeList(array('text' => 'мусор', 1 => array('found' => false)), 'all', 'T') === "T\n_1_");

// Найденная в поле, но не закрытая метка помечается отдельно
db_query($db, "DELETE FROM codes WHERE chat_id = $chat_id");
db_query($db, "INSERT INTO codes (chat_id, level, time, code_number, code_type, code_status, find) VALUES ($chat_id, 777, 1, 2, 1, 0, 0)");
check('markSectorFound(): отмечает метку', markSectorFound($chat_id, 777, 2) === 'Метка 2 отмечена как найденная');
$marked = markFoundSectors($items, $chat_id, 777);
check('markFoundSectors(): переносит признак из БД', !empty($marked[2]['find']));
$openList = formatCodeList($marked, 'open', 'Незакрытые метки:');
check('найденная метка визуально отличается от ненайденной',
    strpos($openList, '_2_ 📍') !== false && strpos($openList, '_3_ 📍') === false, $openList);
check('markSectorFound(): без активного уровня отвечает внятно', markSectorFound($chat_id, 0, 2) === 'Нет активного уровня');
check('markSectorFound(): несуществующая метка', markSectorFound($chat_id, 777, 42) === 'На уровне нет метки 42');

echo "== Кнопки настроек ==\n";
$data = settingsCallbackData('optimize_chat', -1001234567890);
check('callback_data укладывается в лимит Telegram', strlen($data) <= 64, strlen($data).' байт');
$parsed = parseSettingsCallback($data);
check('подписанная callback_data разбирается', is_array($parsed) && $parsed['name'] === 'optimize_chat' && $parsed['chat_id'] === -1001234567890, var_export($parsed, true));
check('подделанная подпись отвергается', parseSettingsCallback('optimize_chat -1001234567890 0000000000') === false);
check('изменённый chat_id отвергается', parseSettingsCallback('optimize_chat -999 '.substr($data, strrpos($data, ' ') + 1)) === false);
check('неизвестная настройка отвергается', parseSettingsCallback(settingsCallbackData('rm_rf', -1001)) === false);
check('мусор отвергается без ошибок', parseSettingsCallback('чтоугодно') === false);
check('кнопок настроек столько же, сколько тумблеров', count(getSettingsButtons($chat_id)) === count(settingsToggles()));

echo "== Точки на местности ==\n";
db_query($db, "DELETE FROM locations WHERE chat_id = $chat_id");
check('addPoint(): без активного уровня отвечает внятно', addPoint($chat_id, 0, 55.75, 37.61, '', '', '') === 'Нет активного уровня');
check('listPoints(): пустой уровень', listPoints($chat_id, 777) === 'На уровне пока нет точек');
check('closePoint(): закрывать нечего', closePoint($chat_id, 777) === 'Открытых точек на уровне нет');

addPoint($chat_id, 777, 55.75, 37.61, 'Штаб', 'Командир', 'cmd', 2);
addPoint($chat_id, 777, 55.80, 37.70, "Опасный ' \" <b>", 'Игрок', 'player', 1);
$list = listPoints($chat_id, 777);
check('listPoints(): показывает обе точки со статусами',
    strpos($list, 'свободна') !== false && strpos($list, 'от поля') !== false, $list);

// Закрывается именно ближайшая к указанным координатам точка
check('closePoint(): закрывает ближайшую', closePoint($chat_id, 777, 55.79, 37.69) === 'Точка 55.8 37.7 закрыта');
check('closePoint(): закрытая точка получает статус',
    strpos(listPoints($chat_id, 777), 'закрыта') !== false, listPoints($chat_id, 777));
check('pointDistance(): считает расстояние в метрах',
    abs(pointDistance(55.75, 37.61, 55.76, 37.61) - 1112) < 30, pointDistance(55.75, 37.61, 55.76, 37.61));

echo "== Сообщения организаторов ==\n";
db_query($db, "DELETE FROM messages WHERE chat_id = $chat_id");
$orgMessages = array('Всем привет от оргов', 'Точка перенесена на 55.755814, 37.617635');

check('publishNewOrgMessages(): первый прогон публикует все сообщения',
    publishNewOrgMessages($chat_id, '', $orgMessages, 777) === 2);
check('publishNewOrgMessages(): повторный прогон не публикует ничего',
    publishNewOrgMessages($chat_id, '', $orgMessages, 777) === 0);
check('publishNewOrgMessages(): третий прогон тоже молчит',
    publishNewOrgMessages($chat_id, '', $orgMessages, 777) === 0);

$stored = db_fetch_assoc(db_query($db, "SELECT COUNT(*) AS cnt FROM messages WHERE chat_id = $chat_id"));
check('publishNewOrgMessages(): в таблице ровно два сообщения', $stored['cnt'] == 2, "строк: $stored[cnt]");

check('publishNewOrgMessages(): новое сообщение публикуется',
    publishNewOrgMessages($chat_id, '', array_merge($orgMessages, array('Ещё одно')), 777) === 1);
check('publishNewOrgMessages(): заглушка "нет сообщений" не пишется в базу',
    publishNewOrgMessages($chat_id, '', array('Нет сообщений организатора'), 777) === 0);
db_query($db, "DELETE FROM messages WHERE chat_id = $chat_id");
db_query($db, "DELETE FROM coords WHERE chat_id = $chat_id");

echo "== Ссылка на карту ==\n";
$token = mapToken($chat_id, 777);
check('mapTokenValid(): свой токен принимается', mapTokenValid($chat_id, 777, $token));
check('mapTokenValid(): чужой уровень отвергается', !mapTokenValid($chat_id, 778, $token));
check('mapTokenValid(): чужой чат отвергается', !mapTokenValid(-999, 777, $token));
check('mapTokenValid(): пустой токен отвергается', !mapTokenValid($chat_id, 777, ''));
check('mapUrl(): содержит чат, уровень и токен',
    strpos(mapUrl($chat_id, 777), 'c='.$chat_id) !== false
    && strpos(mapUrl($chat_id, 777), 'l=777') !== false
    && strpos(mapUrl($chat_id, 777), 't='.$token) !== false, mapUrl($chat_id, 777));

echo "== Разбор координат: дополнительные случаи ==\n";
check('getCoordsFromText(): порядковые числа не считаются координатами', getCoordsFromText('идём на 5 этаж, 3 подъезд') === array());
$two = getCoordsFromText('Первая 55.755814, 37.617635 и вторая 59.938784, 30.314997');
check('getCoordsFromText(): находит обе пары в одном тексте', count($two) === 2, var_export($two, true));
check('getCoordsFromText(): ссылки не ведут на сторонний редиректор',
    strpos($two[0]['links'], 'bots.svk.su') === false, $two[0]['links']);

echo "== Автоостановка: коды Event завершённой игры ==\n";
check('isGameFinishedEvent(6) == true  (EventGameFinished)', isGameFinishedEvent(6) === true);
check('isGameFinishedEvent(17) == true (EventGameEnded, боевой REST-движок)', isGameFinishedEvent(17) === true);
check('isGameFinishedEvent("17") == true (строка из JSON)', isGameFinishedEvent('17') === true);
check('isGameFinishedEvent(0) == false (игра идёт)', isGameFinishedEvent(0) === false);
check('isGameFinishedEvent(5) == false (не началась)', isGameFinishedEvent(5) === false);

echo "== Telegram API ==\n";
$res = apiRequestJSON('sendMessage', array('chat_id' => $chat_id, 'text' => 'тест'));
check('apiRequestJSON() вернул result', isset($res['message_id']) && $res['message_id'] == 4242, var_export($res, true));
$res = apiRequest('sendMessage', array('chat_id' => $chat_id, 'text' => 'тест', 'reply_markup' => array('a' => 1)));
check('apiRequest() кодирует массивы в JSON и возвращает result', isset($res['message_id']));
check('gameSettingsbyUser() находит игру пользователя', gameSettingsbyUser(7)['chat_id'] == $chat_id);

echo "\n";
if ($GLOBALS['php_issues']) {
    echo "ДИАГНОСТИКА PHP (".count($GLOBALS['php_issues']).")\n";
    foreach (array_unique($GLOBALS['php_issues']) as $issue) {
        echo "  $issue\n";
    }
}

echo "Проверок: $checks, провалов: ".count($failures).", диагностик PHP: ".count($GLOBALS['php_issues'])."\n";

exit($failures || $GLOBALS['php_issues'] ? 1 : 0);
