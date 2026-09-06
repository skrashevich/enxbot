<?php
//
// Полный прогон боевого движка Encounter через код-путь бота: parseCode() и
// обёртки из functions.php. Запускается из tests/real-game.sh на игре, которую
// создал tests/lib/enxsetup.go: уровень 1 — 2 сектора (ENXBOT-L1, ENXBOT-L1B),
// уровень 2 — 1 сектор (ENXBOT-L2). Коды ОТПРАВЛЯЮТСЯ в движок.
//
// Env: EN_DOMAIN, EN_LOGIN, EN_PASS, EN_GAMEID, ENCX_BINDINGS_PATH.
//
error_reporting(E_ALL);
ini_set('display_errors', '1');
$diag = [];
set_error_handler(function ($n, $s, $f, $l) use (&$diag) {
    if (error_reporting() & $n) $diag[] = "[$n] $s @ ".basename($f).':'.$l;
    return true;
});

define('BOT_TOKEN', 'x');
define('API_URL', 'http://127.0.0.1:9/');   // Telegram не нужен: parseCode() его не трогает
define('ENCRYPTION_KEY', 'k');
define('BOT_USERNAME', 'b');
define('ADMIN_USERNAME', 'a');
define('PHANTOMJS', '/nonexistent');
$sqlite_path = sys_get_temp_dir().'/enxbot-realgame-'.getmypid().'.sqlite';
register_shutdown_function(fn () => @array_map('unlink', glob($sqlite_path.'*')));

$root = dirname(__DIR__);
require $root.'/db.php';
require $root.'/functions.php';

$dom   = getenv('EN_DOMAIN') ?: 'demo.en.cx';
$gid   = (int)getenv('EN_GAMEID');
$login = getenv('EN_LOGIN');
$pass  = getenv('EN_PASS');
if (!$login || !$pass || !$gid) { fwrite(STDERR, "EN_LOGIN / EN_PASS / EN_GAMEID не заданы\n"); exit(2); }

$chat = -424242;
$fail = 0;
function ck($name, $cond, $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ").$name.($extra === '' ? '' : "  ($extra)")."\n";
    if (!$cond) $fail++;
}

// строка игры, как её собирают /game domain + id + login + pass + start
db_query($db, "INSERT INTO games (chat_id, game_id, game_domain, game_login, game_pass, cookies, last_level_id, status)
    VALUES ($chat, $gid, '".db_escape($db, $dom)."', '".db_escape($db, $login)."', '".db_escape($db, $pass)."', '', 0, 1)");

echo "== $dom игра #$gid ==\n";

$cookies = auth($dom, $login, $pass);
ck('auth() на боевом движке', is_string($cookies) && json_decode($cookies, true) !== null);
db_query($db, "UPDATE games SET cookies = '".db_escape($db, $cookies)."' WHERE chat_id = $chat");

$model = encxGameModel($cookies, $dom, $gid);
$lvl = encxLevel($model);
echo "  Event=".($model['Event'] ?? '?').", Level.Number=".($lvl['Number'] ?? '-')
    .", Sectors=".(isset($lvl['Sectors']) ? count($lvl['Sectors']) : 0)
    .", Helps=".(isset($lvl['Helps']) ? count($lvl['Helps']) : 0)."\n";
ck('игра в бою: активен уровень 1', $lvl !== null && ($lvl['Number'] ?? 0) == 1, 'Event='.($model['Event'] ?? '?'));

$lt = getLevelText($cookies, $dom, $gid);
ck('getLevelText() читает реальное задание уровня 1',
    is_string($lt) && mb_strpos($lt, 'Уровень 1 из 2') !== false && mb_strpos($lt, 'ENXBOT-L1') !== false,
    is_string($lt) ? str_replace("\n", ' / ', mb_substr($lt, 0, 90)) : var_export($lt, true));

$h = getHints($cookies, $dom, $gid);
ck('getHints() читает подсказку уровня 1', is_array($h) && $h['levelid'] > 0 && count($h['hints']) >= 1,
    'levelid='.$h['levelid'].' hints='.count($h['hints']));

$s = getSectors($cookies, $dom, $gid);
ck('getSectors(): 2 сектора, оба открыты', count($s['sectors']) === 2
    && ($s['sectors'][1]['found'] ?? true) === false && ($s['sectors'][2]['found'] ?? true) === false, $s['text']);

$r = parseCode('&ENXBOT-NOPE', $chat, 'Тестер');
ck('parseCode(): неверный код не принят', mb_strpos((string)$r, 'не принят') !== false, var_export($r, true));

$r = parseCode('&ENXBOT-L1', $chat, 'Тестер');
ck('parseCode(): 1-й сектор уровня 1 принят', mb_strpos((string)$r, 'Код принят') !== false, var_export($r, true));
$s = getSectors($cookies, $dom, $gid);
$done = count(array_filter($s['sectors'], fn ($x) => $x['found']));
ck('getSectors(): закрыт ровно 1 из 2 секторов', $done === 1, $s['text']);

$r = parseCode('&ENXBOT-L1B', $chat, 'Тестер');
ck('parseCode(): 2-й сектор уровня 1 принят', mb_strpos((string)$r, 'Код принят') !== false, var_export($r, true));

$model2 = encxGameModel($cookies, $dom, $gid);
$lvl2 = encxLevel($model2);
ck('движок перешёл на уровень 2 после закрытия всех секторов', ($lvl2['Number'] ?? 0) == 2,
    'Level.Number='.($lvl2['Number'] ?? '?'));
$ll = db_fetch_assoc(db_query($db, "SELECT last_level_id FROM games WHERE chat_id=$chat"))['last_level_id'];
ck('parseCode обновил last_level_id на id уровня 2', (int)$ll === (int)($lvl2['LevelId'] ?? -1) && (int)$ll > 0,
    'll='.$ll.' engine='.($lvl2['LevelId'] ?? '?'));

$lt2 = getLevelText($cookies, $dom, $gid);
ck('getLevelText() читает задание уровня 2', is_string($lt2) && mb_strpos($lt2, 'ENXBOT-L2') !== false,
    is_string($lt2) ? str_replace("\n", ' / ', mb_substr($lt2, 0, 90)) : var_export($lt2, true));

$r = parseCode('&ENXBOT-L2', $chat, 'Тестер');
ck('parseCode(): код уровня 2 принят (игра завершается)',
    mb_strpos((string)$r, 'Код принят') !== false || mb_strpos((string)$r, 'Игра завершена') !== false, var_export($r, true));

$fin = isGameFinished($cookies, $dom, $gid);
$finModel = encxGameModel($cookies, $dom, $gid);
ck('isGameFinished() == true после последнего кода', $fin === true, 'реальный Event движка = '.($finModel['Event'] ?? '?'));

$rows = db_num_rows(db_query($db, "SELECT id FROM codeslog WHERE chat_id=$chat"));
ck('codeslog: 4 попытки записаны (NOPE, L1, L1B, L2)', $rows === 4, 'строк='.$rows);
$llFinal = db_fetch_assoc(db_query($db, "SELECT last_level_id FROM games WHERE chat_id=$chat"))['last_level_id'];
ck('last_level_id = -1 (завершённая игра выпадает из выборки cron.php)', (int)$llFinal === -1, 'll='.$llFinal);

echo 'Провалов: '.$fail.', диагностик PHP: '.count($diag)."\n";
foreach ($diag as $d) echo "  PHP $d\n";
exit($fail || $diag ? 1 : 0);
