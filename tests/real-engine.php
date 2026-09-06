<?php
//
// Смоук обёрток движка из functions.php против БОЕВОГО Encounter.
// Запускается из tests/real-engine.sh. Только чтение, коды не отправляются.
//
// Окружение: EN_DOMAIN, EN_LOGIN, EN_PASS, EN_GAMEID, ENCX_BINDINGS_PATH.
//
error_reporting(E_ALL);
ini_set('display_errors', '1');

$diag = [];
set_error_handler(function ($n, $s, $f, $l) use (&$diag) {
    if (error_reporting() & $n) $diag[] = "[$n] $s @ ".basename($f).':'.$l;
    return true;
});

define('BOT_TOKEN', 'x');
define('API_URL', 'http://127.0.0.1:9/');
define('ENCRYPTION_KEY', 'k');
define('BOT_USERNAME', 'b');
define('ADMIN_USERNAME', 'a');
define('PHANTOMJS', '/nonexistent');
$sqlite_path = sys_get_temp_dir().'/enxbot-real-'.getmypid().'.sqlite';
register_shutdown_function(function () use ($sqlite_path) { @unlink($sqlite_path); @unlink($sqlite_path.'-wal'); @unlink($sqlite_path.'-shm'); });

$root = dirname(__DIR__);
require $root.'/db.php';
require $root.'/functions.php';

$dom   = getenv('EN_DOMAIN') ?: 'demo.en.cx';
$login = getenv('EN_LOGIN');
$pass  = getenv('EN_PASS');
$gid   = (int)getenv('EN_GAMEID');
if (!$login || !$pass) { fwrite(STDERR, "EN_LOGIN / EN_PASS не заданы\n"); exit(2); }

$fail = 0;
function ck($name, $cond, $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ").$name.($extra === '' ? '' : "  ($extra)")."\n";
    if (!$cond) $fail++;
}

echo "== $dom (логин $login, игра #$gid) ==\n";

$t = microtime(true);
$cookies = auth($dom, $login, $pass);
printf("  auth: %.2f c\n", microtime(true) - $t);
ck('auth() -> сериализованная сессия', is_string($cookies) && json_decode($cookies, true) !== null,
    is_string($cookies) ? 'len='.strlen($cookies) : var_export($cookies, true));
if ($cookies === false) { echo "Провалов: 1\n"; exit(1); }

ck('неверный пароль -> false', auth($dom, $login, 'nope_'.mt_rand()) === false);

if ($gid <= 0) {
    echo "  (EN_GAMEID не задан — проверен только логин)\n";
    echo 'Провалов: '.$fail.', диагностик PHP: '.count($diag)."\n";
    foreach ($diag as $d) echo "  PHP $d\n";
    exit($fail || $diag ? 1 : 0);
}

$r = testGame($cookies, $dom, $gid);
ck('testGame() не бросает исключение', $r !== null && $r !== '', var_export($r, true));

$model = null;
try { $model = encxGameModel($cookies, $dom, $gid); }
catch (Throwable $e) { echo "  encxGameModel: ".$e->getMessage()."\n"; }
ck('encxGameModel() -> массив движка с ключом Event', is_array($model) && array_key_exists('Event', $model),
    $model === null ? 'null' : ('Event='.($model['Event'] ?? '?')));

$level = is_array($model) ? encxLevel($model) : null;
echo "  движок: Event=".($model['Event'] ?? '?').", Level ".
    ($level ? 'есть (уровень '.($level['Number'] ?? '?').')' : 'нет (команда не в бою)')."\n";

$h = getHints($cookies, $dom, $gid);
ck('getHints() -> полная структура', is_array($h) && array_key_exists('levelid', $h) && array_key_exists('hints', $h)
    && array_key_exists('remains', $h) && array_key_exists('UPsecs', $h), 'levelid='.($h['levelid'] ?? '?'));

$s = getSectors($cookies, $dom, $gid);
ck('getSectors() -> ключ sectors', is_array($s) && array_key_exists('sectors', $s), $s['text'] ?? '?');

$b = getBonuses($cookies, $dom, $gid);
ck('getBonuses() -> ключ bonuses', is_array($b) && array_key_exists('bonuses', $b), $b['text'] ?? '?');

ck('getMessages() -> массив', is_array(getMessages($cookies, $dom, $gid)));

$fin = isGameFinished($cookies, $dom, $gid);
ck('isGameFinished() -> true|false|null (не исключение)', $fin === true || $fin === false || $fin === null, var_export($fin, true));

$lt = getLevelText($cookies, $dom, $gid);
ck('getLevelText() -> строка|false без фатала', is_string($lt) || $lt === false,
    is_string($lt) ? mb_substr($lt, 0, 60) : 'false');

$sc = getScheme($cookies, $dom, $gid);
ck('getScheme() -> строка|false без фатала', is_string($sc) || $sc === false);

$s2 = getSectors($cookies, $dom, $gid);
ck('сессия переиспользуется из тех же куки', is_array($s2) && array_key_exists('sectors', $s2));

echo 'Провалов: '.$fail.', диагностик PHP: '.count($diag)."\n";
foreach ($diag as $d) echo "  PHP $d\n";
exit($fail || $diag ? 1 : 0);
