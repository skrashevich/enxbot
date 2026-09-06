<?php
/**
 * Проверка миграции схемы на базе, созданной ДО бэкпорта.
 *
 * tests/schema.legacy.sql - замороженный снимок db.sql на момент коммита a669b6e.
 * Подключение через db.php обязано досоздать недостающие колонки и таблицы,
 * не потеряв уже существующие данные.
 *
 * Запуск: php tests/migration.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$failures = 0;

function check($name, $condition, $details = '')
{
    global $failures;
    if ($condition) {
        echo "  ok   $name\n";
        return;
    }
    $failures++;
    echo "  ПРОВАЛ: $name".($details !== '' ? " ($details)" : '')."\n";
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    global $failures;
    $failures++;
    echo "  ПРОВАЛ: диагностика PHP: $errstr в $errfile:$errline\n";
    return true;
});

$root = dirname(__DIR__);
$sqlite_path = tempnam(sys_get_temp_dir(), 'enxbot-migr').'.sqlite';

// 1. Создаём базу по старой схеме и кладём в неё данные.
$legacy = new PDO('sqlite:'.$sqlite_path, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$legacy->exec(file_get_contents(__DIR__.'/schema.legacy.sql'));
$legacy->exec("INSERT INTO games (chat_id, game_id, game_domain, last_level_id, status) VALUES (-4242, 999, 'example.org', 555, 1)");
$legacy->exec("INSERT INTO codes (chat_id, level, code_number, code_status, code) VALUES (-4242, 555, 3, 1, 'секрет')");
$legacy->exec("INSERT INTO settings (chat_id, name, value) VALUES (-4242, 'noprefix', 'true')");
$legacy = null;

// 2. Подключаемся штатным способом - db.php должен мигрировать схему.
require $root.'/db.php';

$columns = function ($table) use ($db) {
    $names = array();
    foreach ($db->query('PRAGMA table_info('.$table.')') as $column) {
        $names[] = $column['name'];
    }
    return $names;
};

$tables = array();
foreach ($db->query("SELECT name FROM sqlite_master WHERE type = 'table'") as $row) {
    $tables[] = $row['name'];
}

echo "== Миграция со старой схемы ==\n";

check('games.shtab_id добавлена', in_array('shtab_id', $columns('games'), true));
check('codes.code_type добавлена', in_array('code_type', $columns('codes'), true));
check('codes.find добавлена', in_array('find', $columns('codes'), true));
check('таблица messages создана', in_array('messages', $tables, true));
check('таблица coords создана', in_array('coords', $tables, true));

// 3. Данные не потеряны, у новых колонок сработали значения по умолчанию.
$game = db_fetch_assoc(db_query($db, 'SELECT * FROM games WHERE chat_id = -4242'));
check('строка games сохранилась', is_array($game) && (int)$game['last_level_id'] === 555, 'last_level_id');
check('games.shtab_id пуста у старой строки', is_array($game) && $game['shtab_id'] === null);

$code = db_fetch_assoc(db_query($db, 'SELECT * FROM codes WHERE chat_id = -4242'));
check('строка codes сохранилась', is_array($code) && $code['code'] === 'секрет');
check('codes.code_type по умолчанию 1', is_array($code) && (int)$code['code_type'] === 1);
check('codes.find по умолчанию 0', is_array($code) && (int)$code['find'] === 0);

$setting = db_fetch_assoc(db_query($db, "SELECT value FROM settings WHERE chat_id = -4242 AND name = 'noprefix'"));
check('строка settings сохранилась', is_array($setting) && $setting['value'] === 'true');

// 4. Повторный прогон миграции на уже мигрированной базе ничего не меняет
// и не пытается добавить колонку второй раз (ALTER TABLE упал бы с ошибкой).
$before = $columns('codes');
db_migrate_columns($db);
$after = $columns('codes');
check('повторная миграция идемпотентна', $before === $after, implode(',', $after));

// 5. Новые колонки пригодны для записи.
$db->exec('UPDATE codes SET find = 1, code_type = 2 WHERE chat_id = -4242');
$code = db_fetch_assoc(db_query($db, 'SELECT * FROM codes WHERE chat_id = -4242'));
check('в новые колонки можно писать', (int)$code['find'] === 1 && (int)$code['code_type'] === 2);

$db = null;
@unlink($sqlite_path);
@unlink(substr($sqlite_path, 0, -7));

echo $failures === 0 ? "  Миграция: всё зелёное\n" : "  Миграция: провалов $failures\n";
exit($failures === 0 ? 0 : 1);
