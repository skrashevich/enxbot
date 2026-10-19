<?php
namespace Encx {
    // Only the Encounter transport is replaced; cron and Telegram requests run as-is.
    final class Client {
        public static function newClientWithOptions(...$args) {return new self();}
        public function setCodeSendTimeoutSeconds($seconds) {}
        public function setEngine($engine) {}
        public function resolveEngine() {throw new \RuntimeException('Explicit legacy mode must not probe');}
        public function importCookies($cookies) {}
        public function getGameModel($id) {
            file_put_contents(getenv('ENXBOT_BONUS_TEST_REQUESTS'), "GET\n", FILE_APPEND);
            return file_get_contents(getenv('ENXBOT_BONUS_TEST_MODEL'));
        }
        public function close() {}
    }
}
namespace {
    if (($argv[1] ?? '') === '--cron') {
        chdir($argv[2]);
        require 'cron.php';
        exit;
    }
    $root = dirname(__DIR__);
    $work = sys_get_temp_dir().'/enxbot-bonus-test-'.bin2hex(random_bytes(6));
    mkdir($work, 0700);
    foreach (['cron.php', 'functions.php', 'encounter.php', 'db.php', 'db.sql', 'Html2Text.php'] as $file) {
        copy($root.'/'.$file, $work.'/'.$file);
    }
    $log = $work.'/telegram.jsonl';
    $modelPath = $work.'/model.json';
    putenv('ENCX_ENGINE=legacy');
    putenv('ENXBOT_TG_LOG='.$log);
    putenv('ENXBOT_BONUS_TEST_MODEL='.$modelPath);
    putenv('ENXBOT_BONUS_TEST_REQUESTS='.$work.'/requests.log');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Could not reserve a local test port');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    file_put_contents($work.'/config.php', '<?php $sqlite_path = __DIR__."/test.sqlite"; define("API_URL", '
        .var_export('http://'.$address.'/tg/', true).');');
    $server = proc_open([PHP_BINARY, '-S', $address, __DIR__.'/stub_server.php'], [
        0 => ['pipe', 'r'], 1 => ['file', $work.'/server.log', 'a'], 2 => ['file', $work.'/server.log', 'a'],
    ], $pipes);
    if (!is_resource($server)) throw new RuntimeException('Could not start Telegram stub');
    fclose($pipes[0]);
    $checks = 0;
    function bonusCheck($condition, $message) {
        global $checks;
        if (!$condition) throw new RuntimeException($message);
        $checks++;
    }
    function runBonusCron($work, $expectedError = '') {
        $requests = $work.'/requests.log';
        $before = is_file($requests) ? count(file($requests)) : 0;
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __FILE__, '--cron', $work], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $expected = $expectedError === '' ? $errors === ''
            : str_starts_with($errors, $expectedError) && !preg_match('/Fatal|Warning|Deprecated|Notice/', $errors);
        bonusCheck(proc_close($process) === 0 && $expected, 'Cron failed: '.$output.$errors);
        bonusCheck(count(file($requests)) === $before + 1, 'Cron fetched the same game more than once');
    }
    try {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            if (@file_get_contents('http://'.$address.'/tg/getMe') !== false) break;
            usleep(100000);
        }
        bonusCheck($attempt < 30, 'Telegram stub did not become ready');
        file_put_contents($log, '');
        $sqlite_path = $work.'/test.sqlite';
        require $work.'/db.php';
        $db->exec("INSERT INTO games (chat_id, game_id, game_domain, cookies, last_level_id, status)
            VALUES (-1001, 42, 'test.invalid', '[]', 101, 1)");
        $task = '<p>Точка <b>А_1*</b> [карта] &amp; &lt;вход&gt;</p><p>Далее 🚀</p>';
        $long = str_repeat('Д🚀 &< >_[]* ', 600);
        $sectorCode = 'en_23* [вход] <&';
        $longCode = str_repeat('en🚀<&', 450);
        $model = ['Event' => 0, 'GameTitle' => 'Synthetic bonus test', 'Level' => [
            'LevelId' => 101, 'Number' => 1, 'RequiredSectorsCount' => 2,
            'Sectors' => [['Order' => 10, 'IsAnswered' => false], ['Order' => 11, 'IsAnswered' => false], ['Order' => 12, 'IsAnswered' => false]],
            'Bonuses' => [
                ['Number' => 10, 'IsAnswered' => false, 'Task' => $task],
                ['Number' => 11, 'IsAnswered' => false],
                ['Number' => 12, 'IsAnswered' => false],
                ['Number' => 13, 'IsAnswered' => true, 'Task' => 'Old bonus'],
            ],
        ]];
        file_put_contents($modelPath, json_encode($model));
        runBonusCron($work);
        bonusCheck(file_get_contents($log) === '', 'Initial snapshot sent a closure notice');
        $model['Level']['Sectors'][0]['IsAnswered'] = true;
        $model['Level']['Sectors'][0]['Answer'] = $sectorCode;
        foreach ($model['Level']['Bonuses'] as &$bonus) $bonus['IsAnswered'] = true;
        unset($bonus);
        $model['Level']['Bonuses'][0]['Answer'] = 'bonus-test';
        $model['Level']['Bonuses'][2]['Answer'] = $longCode;
        $model['Level']['Bonuses'][2]['Task'] = '<p>'.htmlspecialchars($long, ENT_QUOTES, 'UTF-8').'</p>';
        file_put_contents($modelPath, json_encode($model));
        runBonusCron($work);
        $calls = array_map(static fn($line) => json_decode($line, true), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $messages = array_column($calls, 'params');
        bonusCheck(count($messages) > 4, 'Long bonus text was not split into Telegram messages');
        bonusCheck($messages[0]['text'] === "Сектор <b>10</b> закрыт через движок\n\nКод: en_23* [вход] &lt;&amp;\n\nКодов до АП: 1.", 'Sector notice lost its answer code or the partial-level threshold');
        $expected = "Бонус <b>10</b> закрыт через движок\n\nКод: bonus-test\n\nТочка А_1* [карта] &amp; &lt;вход&gt;\n\nДалее 🚀";
        bonusCheck($messages[1]['text'] === $expected, 'Bonus text or safe HTML formatting was lost');
        bonusCheck($messages[2]['text'] === 'Бонус <b>11</b> закрыт через движок', 'Empty bonus text added blank content');
        $joined = '';
        foreach (array_slice($messages, 3) as $message) {
            $plain = html_entity_decode(strip_tags($message['text']), ENT_QUOTES, 'UTF-8');
            bonusCheck(strlen(iconv('UTF-8', 'UTF-16LE', $plain)) / 2 <= 4096, 'Telegram message exceeds the length limit');
            $joined .= $plain;
        }
        bonusCheck($joined === "Бонус 12 закрыт через движок\n\nКод: ".$longCode."\n\n".trim($long), 'Long code or bonus text was truncated or corrupted');
        foreach ($messages as $message) {
            bonusCheck($message['chat_id'] === -1001 && $message['parse_mode'] === 'HTML', 'Incorrect Telegram target or parse mode');
        }
        bonusCheck((int)$db->query('SELECT COUNT(*) FROM codes WHERE code_status = 1')->fetchColumn() === 5, 'Closed state was not persisted');
        bonusCheck($db->query('SELECT code FROM codes WHERE code_type = 1 AND code_number = 10')->fetchColumn() === $sectorCode, 'Stored sector code differs from the notification');
        $before = file_get_contents($log);
        runBonusCron($work);
        bonusCheck(file_get_contents($log) === $before, 'A repeated cron sent duplicate bonus text');
        $model['Level']['Sectors'][1]['IsAnswered'] = true;
        file_put_contents($modelPath, json_encode($model));
        runBonusCron($work);
        $latest = array_map(static fn($line) => json_decode($line, true), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        bonusCheck(end($latest)['params']['text'] === "Сектор <b>11</b> закрыт через движок\n\nКодов до АП: 0.", 'Reached threshold did not show zero or a missing code added blank content');
        $model['Level']['LevelId'] = 102;
        $model['Level']['Number'] = 16;
        $model['Levels'] = [];
        file_put_contents($modelPath, json_encode($model));
        runBonusCron($work);
        bonusCheck((int)$db->query('SELECT last_level_id FROM games')->fetchColumn() === 102, 'New level was not detected on the next poll');
        file_put_contents($modelPath, '{invalid JSON');
        runBonusCron($work, 'Encounter cron state failed:');
        bonusCheck($db->query('SELECT last_level_id || "/" || status FROM games')->fetchColumn() === '102/1', 'Unavailable engine lost the known level or stopped an active game');
        $db->exec('INSERT INTO log (chat_id, time) VALUES (-1001, '.(time() - 90000).')');
        runBonusCron($work, 'Encounter cron state failed:');
        bonusCheck((int)$db->query('SELECT status FROM games')->fetchColumn() === 0, 'Engine failure disabled the independent idle-stop check');
        echo "Bonus notification regression checks passed: $checks\n";
    } finally {
        proc_terminate($server); proc_close($server);
        $db = null;
        foreach (glob($work.'/*') as $file) unlink($file);
        rmdir($work);
    }
}
