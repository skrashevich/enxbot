<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

$issues = array();
set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$issues) {
    if (error_reporting() & $errno) {
        $issues[] = sprintf('[%d] %s в %s:%d', $errno, $errstr, basename($errfile), $errline);
    }
    return true;
});

require 'config.php';
require 'db.php';
require 'functions.php';

$domain = getenv('ENCX_MOCK_DOMAIN');
if (!$domain) {
    fwrite(STDERR, "Не задан ENCX_MOCK_DOMAIN\n");
    exit(2);
}

$failed = array();
function check($name, $condition, $details = '')
{
    global $failed;
    if ($condition) {
        echo "  ok   $name\n";
    } else {
        echo "  FAIL $name".($details === '' ? '' : " ($details)")."\n";
        $failed[] = $name;
    }
}

$gameid = 424242;
$cookies = auth($domain, 'demo', 'demo');
check('auth() вернула сериализованную сессию encx', is_string($cookies) && json_decode($cookies, true) !== null, var_export($cookies, true));
check('неверные реквизиты отклоняются', auth($domain, 'fail', 'fail') === false);
check('testGame() читает GameModel', testGame($cookies, $domain, $gameid) === 'Mock Game 2026');

$level = getLevelText($cookies, $domain, $gameid);
check('getLevelText() читает номер и количество уровней', is_string($level) && strpos($level, 'Уровень 1 из 3') !== false, var_export($level, true));

$hints = getHints($cookies, $domain, $gameid);
check('getHints() возвращает структуру из GameModel', is_array($hints) && $hints['levelid'] === 1400001 && is_array($hints['hints']), var_export($hints, true));

$sectors = getSectors($cookies, $domain, $gameid);
check('getSectors() читает четыре сектора', count($sectors['sectors']) === 4, var_export($sectors, true));
check('до отправки кода сектора не закрыты', $sectors['sectors'][1]['found'] === false);
check('getMessages() корректно обрабатывает пустой список', getMessages($cookies, $domain, $gameid) === array('Нет сообщений организатора'));

$wrong = sendCode($cookies, $domain, $gameid, 'wrong');
check('sendCode() передаёт неверный код', strpos($wrong['result'], 'Код не принят') !== false, var_export($wrong, true));

$right = sendCode($cookies, $domain, $gameid, '1');
check('sendCode() передаёт верный секторный код', strpos($right['result'], 'Код принят') !== false, var_export($right, true));
$after = getSectors($cookies, $domain, $gameid);
check('состояние сессии восстанавливается из cookies', $after['sectors'][1]['found'] === true, var_export($after, true));
check('ответ сектора доступен из модели', $after['sectors'][1]['code'] === '1', var_export($after['sectors'][1], true));

// Бонусы
$bonuses = getBonuses($cookies, $domain, $gameid);
check('getBonuses() читает бонусы уровня', count($bonuses['bonuses']) === 4, var_export($bonuses, true));
check('getBonuses() считает взятые бонусы', strpos($bonuses['text'], 'Взято 0') !== false, $bonuses['text']);
check('до отправки кода бонусы не взяты', $bonuses['bonuses'][1]['found'] === false);
check('имя бонуса читается из модели', $bonuses['bonuses'][1]['name'] !== '', var_export($bonuses['bonuses'][1], true));

// Мок генерирует бонусные коды случайно, поэтому берём задание бонуса из модели
$levelModel = encxLevel(encxGameModel($cookies, $domain, $gameid));
$bonusTask = '';
$bonusNumber = 0;
foreach (($levelModel['Bonuses'] ?? array()) as $bonus) {
    if (!empty($bonus['Task']) && empty($bonus['IsAnswered'])) {
        $bonusTask = (string)$bonus['Task'];
        $bonusNumber = (int)$bonus['Number'];
        break;
    }
}
check('в модели есть бонус с заданием, который можно взять', $bonusTask !== '' && $bonusNumber > 0, var_export($levelModel['Bonuses'] ?? null, true));

$bonusCode = sendCode($cookies, $domain, $gameid, $bonusTask);
check('sendCode() передаёт верный бонусный код', strpos($bonusCode['result'], 'Код принят') !== false, var_export($bonusCode, true));
$bonusesAfter = getBonuses($cookies, $domain, $gameid);
check('взятый бонус отмечается в модели', $bonusesAfter['bonuses'][$bonusNumber]['found'] === true, var_export($bonusesAfter['bonuses'][$bonusNumber] ?? null, true));
check('getBonuses() пересчитывает остаток', strpos($bonusesAfter['text'], 'Взято 1') !== false, $bonusesAfter['text']);

// Списки бонусов формируются по своим режимам отбора
$openBonuses = formatCodeList($bonusesAfter['bonuses'], 'open', 'Незакрытые бонусы:');
$closedBonuses = formatCodeList($bonusesAfter['bonuses'], 'closed', 'Закрытые бонусы:');
check('список незакрытых бонусов не содержит взятый', strpos($openBonuses, "_{$bonusNumber} ") === false && strpos($openBonuses, "_{$bonusNumber}_") === false, $openBonuses);
// Взятый бонус выводится жирным: "*N имя:<tab>код*"
$closedMarker = strpos($closedBonuses, "*{$bonusNumber} ") !== false || strpos($closedBonuses, "*{$bonusNumber}:") !== false;
check('список закрытых бонусов содержит взятый', $closedMarker, $closedBonuses);
check('в списке закрытых бонусов ровно одна строка', substr_count($closedBonuses, "\n") === 1, $closedBonuses);
check('пустой список бонусов выводится явно', formatCodeList(array(), 'all', 'Все бонусы:') === "Все бонусы:\n(пусто)");
check('бонусы не попадают в список меток', strpos(formatCodeList($sectors['sectors'], 'all', 'Все метки:'), 'name') === false);

check('неизвестная игра обрабатывается как отсутствие доступа', testGame($cookies, $domain, 999999) === 'У команды бота нет доступа к игре');
check('запрос без сессии безопасно деградирует', getSectors('', $domain, $gameid)['sectors'] === array());
check('без сессии бонусы деградируют так же', getBonuses('', $domain, $gameid)['bonuses'] === array());

if ($issues) {
    foreach ($issues as $issue) {
        echo "  PHP $issue\n";
    }
}
echo 'Провалов: '.count($failed).', диагностик PHP: '.count($issues)."\n";
exit($failed || $issues ? 1 : 0);
