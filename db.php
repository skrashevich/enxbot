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

// Колонки, добавленные к таблицам уже после первого релиза схемы.
// CREATE TABLE IF NOT EXISTS их не добавит в существующую базу, поэтому
// недостающие досоздаются через ALTER TABLE при каждом подключении.
function db_added_columns()
{
    return array(
        'messages' => array(
            'runtime_delivery' => 'TEXT',
        ),
        'games' => array(
            'shtab_id' => 'TEXT',
        ),
        'codes' => array(
            'code_type' => 'INTEGER NOT NULL DEFAULT 1',
            'find' => 'INTEGER NOT NULL DEFAULT 0',
        ),
    );
}

function db_migrate_columns($db)
{
    foreach (db_added_columns() as $table => $columns) {
        $existing = array();
        foreach ($db->query('PRAGMA table_info('.$table.')') as $column) {
            if (isset($column['name'])) {
                $existing[$column['name']] = true;
            }
        }

        // Таблицы нет вовсе - её создаст db.sql, мигрировать нечего.
        if (!$existing) {
            continue;
        }

        foreach ($columns as $name => $definition) {
            if (!isset($existing[$name])) {
                $db->exec('ALTER TABLE '.$table.' ADD COLUMN '.$name.' '.$definition);
            }
        }
    }
}

/** Open a fresh PDO connection; also used by the long-poll recovery path. */
function db_connect($sqlite_path)
{
    $sqlite_dir = dirname($sqlite_path);
    if (!is_dir($sqlite_dir) && !mkdir($sqlite_dir, 0770, true) && !is_dir($sqlite_dir)) {
        throw new RuntimeException('Не удалось создать каталог SQLite: '.$sqlite_dir);
    }
    $connection = new PDO('sqlite:'.$sqlite_path, null, null, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ));
    $connection->exec('PRAGMA busy_timeout = 5000');
    $connection->exec('PRAGMA journal_mode = WAL');
    $connection->exec('PRAGMA foreign_keys = ON');
    // Serialize concurrent startup migrations, including column discovery.
    $connection->exec('BEGIN IMMEDIATE');
    try {
        $schema = file_get_contents(__DIR__.'/db.sql');
        if ($schema === false) {
            throw new RuntimeException('Не удалось прочитать db.sql');
        }
        $connection->exec($schema);
        db_migrate_columns($connection);
        $connection->exec('COMMIT');
    } catch (Throwable $e) {
        $connection->exec('ROLLBACK');
        throw $e;
    }
    return $connection;
}

if (!isset($sqlite_path) || $sqlite_path === '') {
    $sqlite_path = __DIR__.'/enxbot.sqlite';
}
$sqlite_path = (string)$sqlite_path;
try {
    $db = db_connect($sqlite_path);
} catch (Throwable $e) {
    error_log('Не удалось открыть SQLite: '.$e->getMessage());
    die('Ошибка подключения к БД');
}
