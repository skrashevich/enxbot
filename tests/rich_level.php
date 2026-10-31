<?php
namespace Encx {
    // No game traffic: verify the saved session and requested game explicitly.
    final class Client {
        public static $model = [];
        public static $requests = [];
        public static $cookies = '';
        public static function newClientWithOptions(...$args) {return new self();}
        public function setCodeSendTimeoutSeconds($seconds) {}
        public function setEngine($engine) {}
        public function resolveEngine() {throw new \RuntimeException('Must use explicit engine');}
        public function importCookies($cookies) {self::$cookies = $cookies;}
        public function close() {}
        public function getGameModel($id) {
            self::$requests[] = [$id, self::$cookies];
            if (self::$model === null) throw new \RuntimeException('Session expired');
            return json_encode(self::$model);
        }
    }
}
namespace {
    $root = dirname(__DIR__);
    $original = getcwd();
    $work = sys_get_temp_dir().'/enxbot-rich-test-'.bin2hex(random_bytes(6));
    mkdir($work, 0700);
    foreach (['bot.php','functions.php','encounter.php','db.php','db.sql','Html2Text.php'] as $file) copy($root.'/'.$file, $work.'/'.$file);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Could not reserve local port');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    file_put_contents($work.'/config.php', '<?php $sqlite_path=__DIR__."/state.sqlite";'
        .'define("API_URL",'.var_export('http://'.$address.'/tg/', true).');'
        .'define("ENCRYPTION_KEY","test-key");define("BOT_USERNAME","testbot");define("ADMIN_USERNAME","admin");');
    // Copy the stub so its failure flag is isolated from parallel tests.
    mkdir($work.'/tests');
    copy(__DIR__.'/stub_server.php', $work.'/tests/stub_server.php');
    putenv('ENCX_ENGINE=legacy');
    putenv('ENXBOT_TG_LOG='.$work.'/telegram.jsonl');
    $server = proc_open([PHP_BINARY,'-S',$address,$work.'/tests/stub_server.php'], [
        0=>['pipe','r'],1=>['file',$work.'/server.log','a'],2=>['file',$work.'/server.log','a'],
    ], $pipes);
    if (!is_resource($server)) throw new RuntimeException('Could not start Telegram stub');
    fclose($pipes[0]);
    $checks = 0;
    function richCheck($condition, $message) {
        global $checks;
        if (!$condition) throw new RuntimeException($message);
        $checks++;
    }
    function richModel($html) {
        return ['Level'=>['Number'=>16,'Task'=>['TaskText'=>$html]],'Levels'=>[]];
    }
    function richCalls() {
        global $work;
        return array_map(static fn($line)=>json_decode($line, true), file($work.'/telegram.jsonl', FILE_IGNORE_NEW_LINES));
    }
    function richCommand($chat=-1001, $command='/get_level_new') {
        handleUpdate(['message'=>['message_id'=>71,'message_thread_id'=>92,'chat'=>['id'=>$chat,'title'=>'Test'],
            'from'=>['id'=>8,'username'=>'player','first_name'=>'Игрок'],'text'=>$command]]);
    }
    try {
        for ($attempt=0; $attempt<30; $attempt++) {
            if (@file_get_contents('http://'.$address.'/tg/getMe') !== false) break;
            usleep(100000);
        }
        richCheck($attempt<30, 'Telegram stub did not start');
        chdir($work);
        require 'bot.php';
        $fixture = '<h1>Задание &amp; цель 🧭</h1><table border="1"><tbody><tr><th>Метка</th><th>Место</th></tr>'
            .'<tr><td><div><b>1+</b></div></td><td><img src="/files/map.jpg" alt="Карта"></td></tr></tbody></table>'
            .'<ol start="3"><li>Первый<ul><li>Вложенный</li></ul></li><li>Второй</li></ol>'
            .'<details open><summary>Подробности</summary><p>Текст</p></details>'
            .'<p><strong>До<img src="//cdn.example/map.png">После</strong></p>'
            .'<a href="../notes?a=1&amp;b=2">Ссылка</a><span class="tg-spoiler">Секрет</span>'
            .'<script>evil()</script><style>bad{}</style><form>password<input></form>'
            .'<a href="javascript:evil()" onclick="evil()">Метка</a><img src="data:secret" alt="Нет картинки">';
        $html = getLevelRichHtml('private-cookie', 'test.invalid', 42, richModel($fixture));
        richCheck(str_starts_with($html,'<h2>Уровень 16</h2>'), 'Hidden statistics produced a false total');
        foreach (['<h1>Задание &amp; цель 🧭</h1>','<table bordered>','<th>Метка</th>',
            '<ol start="3"><li>Первый<ul><li>Вложенный</li></ul></li>',
            '<details open><summary>Подробности</summary><p>Текст</p></details>',
            '<a href="https://test.invalid/gameengines/encounter/notes?a=1&amp;b=2">Ссылка</a>',
            '<tg-spoiler>Секрет</tg-spoiler>','href="https://test.invalid/files/map.jpg"',
            '</p><img src="https://cdn.example/map.png"/><p>'] as $expected) {
            richCheck(str_contains($html,$expected), 'Lost rich structure: '.$expected);
        }
        richCheck(!preg_match('/script|onclick|javascript:|data:|evil|password|tbody|<div|<span|<style|private-cookie/', $html), 'Website markup or credentials leaked');
        richCheck(str_contains($html,'<td><b>1+</b>') && !str_contains($html,'<td><p>'), 'Table cells contain block formatting');
        richCheck(str_contains($html,'Нет картинки'), 'Unsupported image lost its alternative text');
        $visible=richModel('Текст'); $visible['Levels']=array_fill(0,20,[]);
        richCheck(str_contains(getLevelRichHtml('', 'test.invalid',42,$visible),'Уровень 16 из 20'), 'Visible total lost');
        $formatted=['Level'=>['Number'=>1,'Tasks'=>[['TaskText'=>'raw','TaskTextFormatted'=>'<h3>formatted</h3>']]]];
        richCheck(str_contains(getLevelRichHtml('','test.invalid',42,$formatted),'<h3>formatted</h3>'), 'Formatted task or Tasks fallback lost');
        richCheck(mb_strlen(getLevelRichHtml('','test.invalid',42,richModel(str_repeat('я',6000))))>6000, 'Old 4096 limit still applied');
        foreach ([str_repeat('я',32769), str_repeat('<p>Блок</p>',501)] as $tooLarge) {
            try {getLevelRichHtml('','test.invalid',42,richModel($tooLarge)); richCheck(false,'Oversized task was silently accepted');}
            catch (LengthException $e) {richCheck(true,'Limit reported');}
        }
        richCheck(getLevelRichHtml('','test.invalid',42,['Level'=>null])===false, 'Missing task generated a rich message');
        $db->exec("INSERT INTO games (chat_id,shtab_id,game_id,game_domain,cookies,status) VALUES (-1001,-1002,42,'test.invalid','[]',1)");
        Encx\Client::$model=richModel($fixture);
        richCommand(-1001,'/get_level_new@testbot');
        $calls=richCalls(); $last=end($calls);
        richCheck($last['method']==='sendRichMessage', 'Wrong Telegram method');
        $params=$last['params'];
        richCheck($params['rich_message']===['html'=>$html,'skip_entity_detection'=>true], 'HTML was not nested in InputRichMessage');
        richCheck(!isset($params['parse_mode']) && !isset($params['text']), 'Legacy HTML sendMessage used');
        richCheck($params['chat_id']===-1001 && $params['message_thread_id']===92 && $params['reply_parameters']['message_id']===71, 'Reply or topic was lost');
        richCheck(Encx\Client::$requests===[[42,'[]']], 'Wrong game, missing saved session, or repeated fetch');
        richCommand(-1002);
        $calls=richCalls(); $last=end($calls);
        richCheck($last['method']==='sendRichMessage' && $last['params']['chat_id']===-1002, 'HQ command did not use bound game');
        richCheck(count(Encx\Client::$requests)===2 && end(Encx\Client::$requests)===[42,'[]'], 'HQ fetched wrong game');
        $db->exec('UPDATE games SET status=0 WHERE chat_id=-1001');
        richCommand(); $calls=richCalls(); $last=end($calls);
        richCheck(count(Encx\Client::$requests)===2 && $last['method']==='sendMessage' && $last['params']['text']==='Нет активной игры', 'Inactive game was fetched');
        $db->exec('UPDATE games SET status=1 WHERE chat_id=-1001');
        Encx\Client::$model=null;
        richCommand(); $calls=richCalls(); $last=end($calls);
        richCheck($last['method']==='sendMessage' && str_contains($last['params']['text'],'авторизацию'), 'Auth failure not explained');
        Encx\Client::$model=richModel(str_repeat('x',32769));
        richCommand(); $calls=richCalls(); $last=end($calls);
        richCheck($last['method']==='sendMessage' && str_contains($last['params']['text'],'32 768'), 'Limit not explained to player');
        Encx\Client::$model=richModel('<p>Текст</p>');
        file_put_contents($work.'/.stub_fail_mode','rich400');
        $before=count(richCalls());
        richCommand(); $tail=array_slice(richCalls(),$before);
        richCheck(array_column($tail,'method')===['sendRichMessage','sendMessage'], 'Rejected send was repeated or error missing');
        richCheck(str_contains($tail[1]['params']['text'],'Telegram не принял'), 'Send failure not explained');
        richCheck($tail[1]['params']['message_thread_id']===92 && $tail[1]['params']['reply_parameters']['message_id']===71, 'Error escaped the original topic');
        echo "Rich level regression checks passed: $checks\n";
    } finally {
        proc_terminate($server); proc_close($server);
        $db=null;
        chdir($original);
        unlink($work.'/tests/stub_server.php'); rmdir($work.'/tests');
        foreach (glob($work.'/*') as $file) unlink($file);
        @unlink($work.'/.stub_fail_mode');
        rmdir($work);
    }
}
