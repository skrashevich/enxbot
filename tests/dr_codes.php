<?php
namespace Encx {
    // Capture the transport argument; no requests ever reach a live game.
    final class Client {
        public static $codes = [];
        public static function newClientWithOptions(...$args) {return new self();}
        public function setCodeSendTimeoutSeconds($seconds) {}
        public function setEngine($engine) {}
        public function resolveEngine() {throw new \RuntimeException('Explicit legacy mode must not probe');}
        public function importCookies($cookies) {}
        public function close() {}
        public function getGameModel($id) {
            return json_encode(['Event'=>0,'GameTitle'=>'DR test','Level'=>[
                'LevelId'=>101,'Number'=>1,'Sectors'=>[],'Bonuses'=>[],
            ],'EngineAction'=>['LevelAction'=>['IsCorrectAnswer'=>true]]]);
        }
        public function sendCode($game, $level, $number, $code) {
            self::$codes[] = $code;
            return $this->getGameModel($game);
        }
    }
}
namespace {
    $root = dirname(__DIR__);
    $original = getcwd();
    $work = sys_get_temp_dir().'/enxbot-dr-test-'.bin2hex(random_bytes(6));
    mkdir($work, 0700);
    foreach (['bot.php','functions.php','encounter.php','db.php','db.sql','Html2Text.php'] as $file) copy($root.'/'.$file, $work.'/'.$file);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Could not reserve a local test port');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    file_put_contents($work.'/config.php', '<?php $sqlite_path=__DIR__."/state.sqlite";'
        .'define("API_URL",'.var_export('http://'.$address.'/tg/', true).');'
        .'define("ENCRYPTION_KEY","test-key");define("BOT_USERNAME","testbot");define("ADMIN_USERNAME","rootadmin");');
    putenv('ENCX_ENGINE=legacy');
    putenv('ENXBOT_TG_LOG='.$work.'/telegram.jsonl');
    $server = proc_open([PHP_BINARY,'-S',$address,__DIR__.'/stub_server.php'], [
        0=>['pipe','r'],1=>['file',$work.'/server.log','a'],2=>['file',$work.'/server.log','a'],
    ], $pipes);
    if (!is_resource($server)) throw new RuntimeException('Could not start Telegram stub');
    fclose($pipes[0]);
    $checks = 0;
    function drCheck($condition, $message) {
        global $checks;
        if (!$condition) throw new RuntimeException($message);
        $checks++;
    }
    function drMessage($text, $chat = -1001) {
        handleUpdate(['message'=>['message_id'=>1,'chat'=>['id'=>$chat,'title'=>'Test'],
            'from'=>['id'=>8,'username'=>'player','first_name'=>'Тестер'],'text'=>$text]]);
    }
    function drToggle($user, $name) {
        handleUpdate(['callback_query'=>['id'=>'test','from'=>['id'=>$user,'username'=>$name],
            'data'=>settingsCallbackData('dr_noprefix', -1001)]]);
    }
    try {
        for ($attempt=0; $attempt<30; $attempt++) {
            if (@file_get_contents('http://'.$address.'/tg/getMe') !== false) break;
            usleep(100000);
        }
        drCheck($attempt<30, 'Telegram stub did not start');
        chdir($work);
        require 'bot.php';
        $db->exec("INSERT INTO games (chat_id,game_id,game_domain,cookies,last_level_id,status) VALUES
            (-1001,42,'test.invalid','[]',101,1),(-1002,43,'test.invalid','[]',101,1)");
        $db->exec("INSERT INTO admins (admin_username) VALUES ('rootadmin')");
        foreach (['dr1'=>'dr1','др1'=>'dr1','ДР1'=>'dr1','dR42'=>'dr42','др1йцукен'=>'dr1qwerty','ДР1ФЫВ'=>'dr1ASD','ен1'=>'ен1','друг'=>'друг'] as $input=>$expected) {
            drCheck(normalizeDrCode($input)===$expected, 'Wrong keyboard conversion for '.$input);
        }
        foreach (['dr1','др1','ДР123','dr1abc','др1йц//Комментарий'] as $input) drCheck(isDrCodeMessage($input), 'Valid DR code not recognized');
        foreach (['dr','др','driver1','друг1','dr1 завтра','это др1',"dr1\ndr2",'en123','123'] as $input) drCheck(!isDrCodeMessage($input), 'Ordinary text was treated as a DR code');
        $buttons = array_column(array_merge(...getSettingsButtons(-1001)), null, 'callback_data');
        $callback = settingsCallbackData('dr_noprefix', -1001);
        drCheck(isset($buttons[$callback]) && str_ends_with($buttons[$callback]['text'],'🚫'), 'DR toggle is absent or enabled by default');
        drCheck(strlen($callback)<=64 && parseSettingsCallback($callback)['name']==='dr_noprefix', 'DR button signature is invalid');
        drMessage('dr1'); drMessage('др1');
        drCheck(Encx\Client::$codes===[], 'Disabled automatic mode submitted a code');
        drToggle(8, 'player');
        drCheck(get_setting('dr_noprefix',-1001)!=='true', 'An ordinary player enabled the setting');
        drToggle(7, 'rootadmin');
        drCheck(get_setting('dr_noprefix',-1001)==='true', 'Administrator could not enable the setting');
        $fresh = new PDO('sqlite:'.$work.'/state.sqlite');
        drCheck($fresh->query("SELECT value FROM settings WHERE name='dr_noprefix' AND chat_id=-1001")->fetchColumn()==='true', 'DR setting was not persisted');
        $fresh = null;
        drMessage('dr1'); drMessage('др1'); drMessage('ДР42йц//Комментарий др1');
        drCheck(Encx\Client::$codes===['dr1','dr1','dr42qw'], 'Automatic codes were not normalized before transport');
        $logged=$db->query('SELECT code,comment FROM codeslog ORDER BY id DESC LIMIT 1')->fetch();
        drCheck($logged['code']==='dr42qw' && $logged['comment']==='Комментарий др1', 'Code normalization modified the comment or log');
        $before=count(Encx\Client::$codes);
        drMessage('dr1',-1002); drMessage('dr1 завтра'); drMessage('en123');
        drCheck(count(Encx\Client::$codes)===$before, 'Setting leaked between chats or submitted ordinary text');
        set_setting('nocomment','true',-1001);
        drMessage('др2');
        drCheck(count(Encx\Client::$codes)===$before, 'Automatic DR codes bypassed required comments');
        drMessage('др2//Примечание');
        drCheck(end(Encx\Client::$codes)==='dr2' && count(Encx\Client::$codes)===$before+1, 'DR code with required comment was lost');
        $db->exec('UPDATE games SET status=2 WHERE chat_id=-1001');
        drMessage('др3//Только лог');
        $logged=$db->query('SELECT code,comment FROM codeslog ORDER BY id DESC LIMIT 1')->fetch();
        drCheck(count(Encx\Client::$codes)===$before+1 && $logged['code']==='dr3' && $logged['comment']==='Только лог', 'Record-only mode submitted a DR code or failed to normalize it');
        $db->exec('UPDATE games SET status=1 WHERE chat_id=-1001');
        set_setting('nocomment','false',-1001);
        drToggle(7,'rootadmin');
        drCheck(get_setting('dr_noprefix',-1001)==='false', 'Administrator could not disable the setting');
        drMessage('dr4'); drMessage('др4');
        drCheck(count(Encx\Client::$codes)===$before+1, 'Disabled toggle continued automatic submission');
        drMessage('&др9'); drMessage('&en123');
        drCheck(array_slice(Encx\Client::$codes,-2)===['dr9','en123'], 'Explicit prefix conversion or standard code handling changed');
        echo "DR code regression checks passed: $checks\n";
    } finally {
        proc_terminate($server); proc_close($server);
        $db=null; $fresh=null;
        chdir($original);
        foreach (glob($work.'/*') as $file) unlink($file);
        rmdir($work);
    }
}
