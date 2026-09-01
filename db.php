<?php

// config.php:
// $sqlite_path = __DIR__.'/data/enxbot.sqlite';

final class DbResult
{
    private $rows;
    private $position = 0;

    public function __construct($rows = array())
    {
        $this->rows = $rows;
    }

    public function fetchAssoc()
    {
        if (!isset($this->rows[$this->position])) {
            return null;
        }

        return $this->rows[$this->position++];
    }

    public function numRows()
    {
        return count($this->rows);
    }
}

function db_query($db, $sql)
{
    try {
        $statement = $db->query($sql);
        if ($statement->columnCount() === 0) {
            return new DbResult();
        }

        return new DbResult($statement->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        error_log('Ошибка SQLite: '.$e->getMessage().' [SQL: '.$sql.']');
        return false;
    }
}

function db_fetch_assoc($result)
{
    return $result instanceof DbResult ? $result->fetchAssoc() : null;
}

function db_num_rows($result)
{
    return $result instanceof DbResult ? $result->numRows() : 0;
}

function db_escape($db, $value)
{
    $quoted = $db->quote((string)$value, PDO::PARAM_STR);
    return substr($quoted, 1, -1);
}

if (!isset($sqlite_path) || $sqlite_path === '') {
    $sqlite_path = __DIR__.'/enxbot.sqlite';
}

$sqlite_path = (string)$sqlite_path;
$sqlite_dir = dirname($sqlite_path);
if (!is_dir($sqlite_dir) && !mkdir($sqlite_dir, 0770, true) && !is_dir($sqlite_dir)) {
    error_log('Не удалось создать каталог SQLite: '.$sqlite_dir);
    die('Ошибка подключения к БД');
}

try {
    $db = new PDO('sqlite:'.$sqlite_path, null, null, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ));
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA foreign_keys = ON');

    $schema = file_get_contents(__DIR__.'/db.sql');
    if ($schema === false) {
        throw new RuntimeException('Не удалось прочитать db.sql');
    }
    $db->exec($schema);
} catch (Throwable $e) {
    error_log('Не удалось открыть SQLite: '.$e->getMessage());
    die('Ошибка подключения к БД');
}

unset($sqlite_dir, $schema);
