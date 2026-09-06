<?php

if ($argc < 2) {
    fwrite(STDERR, "Использование: php tests/sqlite.php PATH [SQL]\n");
    exit(2);
}

$sqlite_path = $argv[1];
require dirname(__DIR__).'/db.php';

$sql = isset($argv[2]) ? $argv[2] : stream_get_contents(STDIN);
$sql = trim($sql);
if ($sql === '') {
    exit(0);
}

try {
    if (preg_match('/^\s*(SELECT|PRAGMA|WITH)\b/i', $sql)) {
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $row) {
            echo implode("\t", $row)."\n";
        }
    } else {
        $db->exec($sql);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
