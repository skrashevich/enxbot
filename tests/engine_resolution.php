<?php
// Actual PHP -> FFI -> HTTP discovery, with only the remote API substituted.
require dirname(__DIR__).'/encounter.php';
encxLoadBindings();
$work = sys_get_temp_dir().'/enxbot-resolution-'.bin2hex(random_bytes(6));
mkdir($work, 0700);
putenv('ENXBOT_RESOLUTION_MODE='.$work.'/mode');
putenv('ENXBOT_RESOLUTION_LOG='.$work.'/requests');
file_put_contents($work.'/mode', 'unavailable');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Could not reserve a local port');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$server = proc_open([PHP_BINARY,'-S',$address,__DIR__.'/engine_resolution_stub.php'], [
    0=>['pipe','r'],1=>['file',$work.'/server.log','a'],2=>['file',$work.'/server.log','a'],
], $pipes);
if (!is_resource($server)) throw new RuntimeException('Could not start the API stub');
fclose($pipes[0]);
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec(file_get_contents(dirname(__DIR__).'/db.sql'));
$checks = 0;
$client = null;
function resolutionCheck($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
try {
    for ($attempt=0; $attempt<30; $attempt++) {
        if (@file_get_contents('http://'.$address.'/health') === 'ready') break;
        usleep(100000);
    }
    resolutionCheck($attempt<30, 'API stub did not start');
    $client = Encx\Client::newClientWithAPIOptions('modern.en.cx', false, false, 3, 'ru', 'http://'.$address);
    $client->setEngine('auto');
    resolutionCheck(encxResolvedEngine('modern.en.cx', $client) === null, 'A real failed probe became a confirmed verdict');
    resolutionCheck((int)$db->query('SELECT COUNT(*) FROM engine_cache')->fetchColumn() === 0, 'A real temporary fallback was persisted');
    file_put_contents($work.'/mode', 'new');
    resolutionCheck(encxResolvedEngine('modern.en.cx', $client) === 'new', 'Discovery did not recover after the API became available');
    resolutionCheck($db->query("SELECT engine FROM engine_cache WHERE domain='modern.en.cx' AND confirmed=1")->fetchColumn() === 'new', 'Recovered verdict was not confirmed in SQLite');
    $requests = count(file($work.'/requests'));
    resolutionCheck(encxResolvedEngine('modern.en.cx', $client) === 'new' && count(file($work.'/requests')) === $requests, 'Confirmed cache performed another probe');
    echo "Engine resolution FFI checks passed: $checks\n";
} finally {
    if ($client !== null) $client->close();
    proc_terminate($server);
    proc_close($server);
    foreach (glob($work.'/*') as $file) unlink($file);
    rmdir($work);
}
