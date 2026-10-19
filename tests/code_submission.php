<?php
// Exercise the real PHP -> FFI -> HTTP path without contacting a live game.
require dirname(__DIR__).'/functions.php';
$work = sys_get_temp_dir().'/enxbot-code-test-'.bin2hex(random_bytes(6));
mkdir($work, 0700);
$log = $work.'/requests.jsonl';
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Could not reserve a local test port');
$address = stream_socket_get_name($socket, false);
fclose($socket);
putenv('ENCX_ENGINE=legacy');
putenv('ENCX_USE_HTTP=true');
putenv('ENXBOT_CODE_TEST_LOG='.$log);
$router = __DIR__.'/code_submission_stub.php';
$process = proc_open([PHP_BINARY, '-S', $address, $router], [
    0 => ['pipe', 'r'], 1 => ['file', $work.'/server.log', 'a'],
    2 => ['file', $work.'/server.log', 'a'],
], $pipes);
if (!is_resource($process)) throw new RuntimeException('Could not start the local test server');
fclose($pipes[0]);
$checks = 0;
function codeCheck($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function codeRequests($log) {
    return is_file($log) ? array_map(static fn($line) => json_decode($line, true), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
}
try {
    for ($attempt = 0; $attempt < 30; $attempt++) {
        if (@file_get_contents('http://'.$address.'/health') === 'ready') break;
        usleep(100000);
    }
    codeCheck($attempt < 30, 'Local test server did not become ready');
    $session = encxNormalizeCookies('stoken=test-session;');
    $started = microtime(true);
    $slow = sendCode($session, $address, 424242, 'slow-test-code');
    codeCheck(str_contains($slow['result'], 'Код принят'), 'A valid session with a response slower than one second failed');
    codeCheck(microtime(true) - $started >= 1.5, 'Slow-response fixture did not delay the response');
    $requests = codeRequests($log);
    codeCheck(count($requests) === 1, 'Code submission was duplicated');
    codeCheck($requests[0]['session_valid'] === true, 'Session cookie was not sent with the code');
    codeCheck($requests[0]['user_agent'] === 'encx-cli', 'Login and submission User-Agents differ');
    codeCheck($requests[0]['level_id'] === 101 && $requests[0]['level_number'] === 7, 'The wrong level was submitted');
    $failed = sendCode($session, $address, 424242, 'bad-response');
    codeCheck(str_contains($failed['result'], 'не подтвердил'), 'An uncertain submission produced no useful message');
    codeCheck($failed['levelid'] === 101, 'A transport failure lost the known level');
    codeCheck(count(codeRequests($log)) === 2, 'An uncertain submission was retried automatically');
    $wrong = sendCode($session, $address, 424242, 'wrong-test-code');
    codeCheck(str_contains($wrong['result'], 'Код не принят'), 'A rejected code was reported as accepted');
    $lost = sendCode($session, $address, 424242, 'session-lost');
    codeCheck($lost['levelid'] === 101 && str_contains($lost['result'], '/game auth'), 'An authentication error after submission lost the current level');
    $last = sendCode($session, $address, 424242, 'finish-test-code');
    codeCheck($last['levelid'] === -1 && $last['result'] === 'Код принят', 'The last accepted code lost its acknowledgement');
    $expired = sendCode(encxNormalizeCookies('stoken=expired;'), $address, 424242, 'must-not-send');
    codeCheck(str_contains($expired['result'], '/game auth'), 'An expired session was not explained');
    codeCheck($expired['levelid'] === 0, 'An expired session was treated as a finished game');
    codeCheck(count(codeRequests($log)) === 5, 'A code was posted without an active player session');
    $finished = sendCode(encxNormalizeCookies('stoken=finished;'), $address, 424242, 'must-not-send');
    codeCheck($finished['levelid'] === -1 && $finished['result'] === 'Игра завершена', 'A confirmed finish was not preserved');
    $single = sendCode($session, $address, 424243, 'single-sector-code');
    codeCheck($single['result'] === "Код принят (2/4)\nЗакрыт сектор: 2. А_Б * [вход] <арка>\nВсего секторов: 4. Осталось до АП: 2.", 'Accepted code did not identify the sector and remaining progress');
    $multiple = sendCode($session, $address, 424243, 'multiple-sector-code');
    codeCheck($multiple['result'] === "Код принят (3/4)\nЗакрыты секторы:\n• 1. 1+\n• 2. А_Б * [вход] <арка>\nВсего секторов: 4. Осталось до АП: 1.", 'Multiple closed sectors or stable IDs were lost after reordering');
    $unnamed = sendCode($session, $address, 424243, 'unnamed-sector-code');
    codeCheck($unnamed['result'] === "Код принят (2/4)\nЗакрыт сектор: № 4\nВсего секторов: 4. Осталось до АП: 2.", 'An unnamed sector lost its numeric fallback');
    $rejected = sendCode($session, $address, 424243, 'rejected-sector-code');
    codeCheck($rejected['result'] === "Код не принят (2/4)\nВсего секторов: 4. Осталось до АП: 2.", 'A rejected code claimed an external sector closure');
    $unchanged = sendCode($session, $address, 424243, 'unchanged-sector-code');
    codeCheck($unchanged['result'] === "Код принят (1/4)\nВсего секторов: 4. Осталось до АП: 3.", 'An already closed or unchanged sector was reported again');
    $foreign = sendCode($session, $address, 424243, 'foreign-sector-code');
    codeCheck($foreign['result'] === "Код принят (2/4)\nВсего секторов: 4. Осталось до АП: 2.", 'A different sector ID with the same order was misidentified');
    $next = sendCode($session, $address, 424244, 'next-level-code');
    codeCheck($next['UP'] === true && $next['levelid'] === 102 && $next['result'] === "Код принят\nЗакрыт сектор: 2. А_Б * [вход] <арка>\nВсего секторов: 4. Осталось до АП: 0.", 'Level transition lost the previous level progress or reported a sector from the next level');
    $lastSector = sendCode($session, $address, 424244, 'last-sector-code');
    codeCheck($lastSector['levelid'] === -1 && $lastSector['result'] === "Код принят\nЗакрыт сектор: 2. А_Б * [вход] <арка>\nВсего секторов: 4. Осталось до АП: 0.", 'Game completion lost the name or progress of the last sector');
    $ambiguous = sendCode($session, $address, 424243, 'next-level-code');
    codeCheck($ambiguous['UP'] === true && $ambiguous['result'] === "Код принят\nВсего секторов: 4. Осталось до АП: 0.", 'A transition guessed among several remaining sectors');
    $partial = sendCode($session, $address, 424245, 'single-sector-code');
    codeCheck(str_ends_with($partial['result'], 'Всего секторов: 4. Осталось до АП: 1.'), 'Progress ignored the engine threshold for a partial-sector level');
    $zero = sendCode($session, $address, 424246, 'single-sector-code');
    codeCheck(str_ends_with($zero['result'], 'Всего секторов: 4. Осталось до АП: 0.'), 'Progress showed a negative remainder after reaching the threshold');
    codeCheck(count(codeRequests($log)) === 16, 'Reporting sector names submitted additional codes');
    $implicit = sendCode($session, $address, 424247, 'implicit-level-code');
    codeCheck($implicit['UP'] === true && $implicit['levelid'] === 102
        && str_ends_with($implicit['result'], 'Всего секторов: 1. Осталось до АП: 0.'), 'Implicit level code lost library progress on transition');
    codeCheck(count(codeRequests($log)) === 17, 'Reporting implicit code progress submitted an additional code');
    echo "Code submission regression checks passed: $checks\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($work.'/*') as $file) unlink($file);
    rmdir($work);
}
