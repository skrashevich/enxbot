<?php
namespace Encx {
    // Only transport is replaced; confidence comes from the v0.26.0 contract.
    final class Client {
        public static $probes = 0;
        public static $unavailable = [];
        public $domain;
        public $mode = 'auto';
        public $cookies = '';
        public static function newClientWithOptions($domain, $insecure, $http, $timeout, $lang) {
            $client = new self();
            $client->domain = $domain;
            return $client;
        }
        public function setCodeSendTimeoutSeconds($seconds) {}
        public function setEngine($engine) {$this->mode = $engine;}
        public function importCookies($cookies) {$this->cookies = $cookies;}
        public function engine() {
            throw new \RuntimeException('Use resolveEngine(): engine() cannot distinguish a fallback');
        }
        public function resolveEngine() {
            self::$probes++;
            if ($this->cookies !== '') throw new \RuntimeException('Credentials reached the auto probe');
            $domain = strtolower(trim($this->domain));
            $definitive = empty(self::$unavailable[$domain]);
            $engine = $definitive && str_starts_with($domain, 'modern.') ? 'new' : 'legacy';
            return json_encode(['engine' => $engine, 'definitive' => $definitive]);
        }
        public function getGameModel($id) {return $this->mode;}
        public function close() {}
    }
}
namespace {
    require dirname(__DIR__).'/encounter.php';
    putenv('ENCX_ENGINE=auto');
    if (($argv[1] ?? '') === '--worker') {
        $db = new PDO('sqlite:'.$argv[2]);
        $client = encxClient('city.en.cx', 'stoken=test-session;');
        echo json_encode(['engine' => $client->getGameModel(42), 'probes' => Encx\Client::$probes]);
        exit;
    }
    $work = sys_get_temp_dir().'/enxbot-engine-test-'.bin2hex(random_bytes(6));
    mkdir($work, 0700);
    $file = $work.'/state.sqlite';
    $db = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec(file_get_contents(dirname(__DIR__).'/db.sql'));
    $checks = 0;
    function engineCheck($condition, $message) {
        global $checks;
        if (!$condition) throw new RuntimeException($message);
        $checks++;
    }
    try {
        $first = encxClient(' City.En.Cx ', 'stoken=test-session;');
        engineCheck(Encx\Client::$probes === 1, 'First action did not perform exactly one auto probe');
        engineCheck($first->mode === 'legacy' && $first->cookies !== '', 'Detected engine or session was not applied');
        $first->getGameModel(42);
        $first->getGameModel(42);
        engineCheck(Encx\Client::$probes === 1, 'The same action repeated a confirmed auto probe');
        $second = encxClient('city.en.cx', 'stoken=test-session;');
        $second->getGameModel(42);
        engineCheck(Encx\Client::$probes === 1, 'A new client repeated domain discovery');
        engineCheck($db->query("SELECT engine FROM engine_cache WHERE domain='city.en.cx'")->fetchColumn() === 'legacy', 'Engine was not saved in SQLite');

        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $file], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        fclose($pipes[0]);
        $worker = json_decode(stream_get_contents($pipes[1]), true);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        $status = proc_close($process);
        engineCheck($status === 0 && $errors === '', 'Second PHP process failed');
        engineCheck($worker === ['engine' => 'legacy', 'probes' => 0], 'A fresh PHP process repeated discovery');

        $modern = encxClient('modern.en.cx');
        engineCheck($modern->mode === 'new' && Encx\Client::$probes === 2, 'Another domain reused the wrong engine');
        engineCheck((int)$db->query('SELECT COUNT(*) FROM engine_cache')->fetchColumn() === 2, 'Domains were not cached separately');
        putenv('ENCX_ENGINE=NEW');
        $forced = encxClient('city.en.cx');
        engineCheck($forced->mode === 'new' && Encx\Client::$probes === 2, 'Explicit engine did not override the cache');
        putenv('ENCX_ENGINE=legacy');
        $uncached = encxClient('forced.en.cx');
        engineCheck($uncached->mode === 'legacy' && Encx\Client::$probes === 2, 'Explicit mode performed an auto probe');
        engineCheck((int)$db->query('SELECT COUNT(*) FROM engine_cache')->fetchColumn() === 2, 'Explicit override changed cached auto selections');
        putenv('ENCX_ENGINE=auto');
        engineCheck(encxClient('modern.en.cx')->mode === 'new' && Encx\Client::$probes === 2, 'Returning to auto repeated detection');

        Encx\Client::$unavailable['modern.retry.en.cx'] = true;
        $temporary = encxClient('modern.retry.en.cx', 'stoken=test-session;');
        engineCheck($temporary->mode === 'auto', 'A temporary fallback was pinned with setEngine');
        engineCheck((int)$db->query("SELECT COUNT(*) FROM engine_cache WHERE domain='modern.retry.en.cx'")->fetchColumn() === 0, 'A temporary fallback was persisted');
        unset(Encx\Client::$unavailable['modern.retry.en.cx']);
        engineCheck(encxClient('modern.retry.en.cx')->mode === 'new', 'The next action did not recover after a failed probe');
        engineCheck($db->query("SELECT engine FROM engine_cache WHERE domain='modern.retry.en.cx'")->fetchColumn() === 'new', 'Recovered engine was not persisted');

        // Entries from the earlier PR did not distinguish fallback from a verdict.
        $db->exec("INSERT INTO engine_cache (domain, engine) VALUES ('modern.stale.en.cx', 'legacy')");
        engineCheck(encxClient('modern.stale.en.cx')->mode === 'new', 'An old unconfirmed cache entry bypassed discovery');
        engineCheck((int)$db->query("SELECT confirmed FROM engine_cache WHERE domain='modern.stale.en.cx'")->fetchColumn() === 1, 'The old cache entry was not replaced by a confirmed verdict');
        echo "Engine cache regression checks passed: $checks\n";
    } finally {
        $db = null;
        foreach (glob($work.'/*') as $path) unlink($path);
        rmdir($work);
    }
}
