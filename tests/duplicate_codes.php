<?php
namespace Encx {
    // Capture actual submission calls while keeping every game/Telegram request local.
    final class Client {
        public static $posts = [];
        public static $rejectNext = false;
        public static $level = ['LevelId'=>101,'Number'=>1,'Sectors'=>[],'Bonuses'=>[]];
        public static function newClientWithOptions(...$args) {return new self();}
        public function setCodeSendTimeoutSeconds($seconds) {}
        public function setEngine($engine) {}
        public function resolveEngine() {throw new \RuntimeException('Must use explicit engine');}
        public function importCookies($cookies) {}
        public function close() {}
        public function getGameModel($id) {return json_encode(['Event'=>0,'Level'=>self::$level]);}
        public function sendCode($game, $level, $number, $code) {
            self::$posts[] = ['game'=>$game,'level'=>$level,'code'=>$code,'bonus'=>false];
            if ($code === 'unknown') throw new \RuntimeException('Synthetic unconfirmed response');
            if ($code === 'transition') {self::$level['LevelId']++; self::$level['Number']++;}
            $accepted = !self::$rejectNext && !in_array($code,['wrong','bonus-code'],true);
            self::$rejectNext = false;
            return json_encode(['Event'=>0,'Level'=>self::$level,
                'EngineAction'=>['LevelAction'=>['IsCorrectAnswer'=>$accepted]]]);
        }
        public function sendBonusCode($game, $level, $number, $code) {
            self::$posts[] = ['game'=>$game,'level'=>$level,'code'=>$code,'bonus'=>true];
            return json_encode(['Event'=>0,'Level'=>self::$level,
                'EngineAction'=>['BonusAction'=>['IsCorrectAnswer'=>$code === 'bonus-code']]]);
        }
    }
}
namespace {
    putenv('ENCX_ENGINE=legacy');
    if (($argv[1] ?? '') === '--repeat') {
        chdir($argv[2]);
        require 'bot.php';
        $result = sendCode('[]','test.invalid',42,'same',-1001);
        echo json_encode(['result'=>$result['result'],'posts'=>count(Encx\Client::$posts)]);
        exit;
    }
    $root = dirname(__DIR__);
    $original = getcwd();
    $work = sys_get_temp_dir().'/enxbot-duplicates-'.bin2hex(random_bytes(6));
    mkdir($work, 0700);
    foreach (['bot.php','functions.php','encounter.php','db.php','db.sql','Html2Text.php'] as $file) copy($root.'/'.$file, $work.'/'.$file);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Could not reserve a local port');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    file_put_contents($work.'/config.php', '<?php $sqlite_path=__DIR__."/state.sqlite";'
        .'define("API_URL",'.var_export('http://'.$address.'/tg/',true).');'
        .'define("ENCRYPTION_KEY","test-key");define("BOT_USERNAME","testbot");define("ADMIN_USERNAME","rootadmin");');
    putenv('ENXBOT_TG_LOG='.$work.'/telegram.jsonl');
    $server = proc_open([PHP_BINARY,'-S',$address,__DIR__.'/stub_server.php'],[
        0=>['pipe','r'],1=>['file',$work.'/server.log','a'],2=>['file',$work.'/server.log','a'],
    ],$pipes);
    if (!is_resource($server)) throw new RuntimeException('Could not start Telegram stub');
    fclose($pipes[0]);
    $checks = 0;
    function duplicateCheck($condition,$message) {
        global $checks;
        if (!$condition) throw new RuntimeException($message);
        $checks++;
    }
    function duplicateMessage($text,$chat=-1001,$user=7) {
        global $work;
        handleUpdate(['message'=>['message_id'=>1,'chat'=>['id'=>$chat,'title'=>'Test'],
            'from'=>['id'=>$user,'username'=>'rootadmin'],'text'=>$text]]);
        $calls=file($work.'/telegram.jsonl',FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return json_decode(end($calls),true)['params']['text'] ?? '';
    }
    try {
        for ($attempt=0;$attempt<30;$attempt++) {
            if (@file_get_contents('http://'.$address.'/tg/getMe') !== false) break;
            usleep(100000);
        }
        duplicateCheck($attempt<30,'Telegram stub did not start');
        chdir($work);
        require 'bot.php';
        $db->exec("INSERT INTO games(chat_id,game_id,game_domain,cookies,last_level_id,status) VALUES
            (-1001,42,'test.invalid','[]',101,1),(-1002,43,'test.invalid','[]',101,1)");
        duplicateCheck(str_starts_with(duplicateMessage(';same//first'),'✅ Код принят'),'First code not submitted');
        duplicateCheck(str_ends_with(duplicateMessage('#SAME//different comment',-1001,9),'Данный код уже введён'),'Duplicate did not get an explicit answer');
        duplicateCheck(count(Encx\Client::$posts)===2,'Duplicate was not submitted to the engine');
        duplicateCheck((int)$db->query('SELECT COUNT(*) FROM codeslog')->fetchColumn()===2,'An actual repeated attempt was not logged');
        $child=proc_open([PHP_BINARY,__FILE__,'--repeat',$work],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$work.'/child.log','a']],$childPipes);
        fclose($childPipes[0]);
        $reply=json_decode(stream_get_contents($childPipes[1]),true);
        fclose($childPipes[1]);
        duplicateCheck(proc_close($child)===0 && str_ends_with($reply['result']??'','Данный код уже введён') && $reply['posts']===1,'Accepted code was forgotten or suppressed after process restart');
        duplicateMessage(';wrong');
        duplicateCheck(!str_contains(duplicateMessage(';wrong'),'Данный код уже введён') && count(Encx\Client::$posts)===4,'Rejected code was suppressed or marked accepted');
        duplicateMessage(';unknown');
        duplicateCheck(!str_contains(duplicateMessage(';unknown'),'Данный код уже введён') && count(Encx\Client::$posts)===6,'Unconfirmed response was suppressed or marked accepted');
        duplicateCheck(!str_contains(duplicateMessage(';same',-1002),'Данный код уже введён'),'Duplicate cache leaked to another chat/game');
        duplicateCheck(count(Encx\Client::$posts)===7,'Code was not submitted in another chat');
        Encx\Client::$level['LevelId']=102;
        duplicateCheck(!str_contains(duplicateMessage(';same'),'Данный код уже введён'),'Previous level marked a new level code as repeated while cron was stale');
        duplicateCheck(count(Encx\Client::$posts)===8,'New level code was not sent');
        duplicateCheck((int)$db->query('SELECT last_level_id FROM games WHERE chat_id=-1001')->fetchColumn()===102,'Fresh engine level not saved');
        duplicateMessage(';transition');
        duplicateCheck((int)$db->query('SELECT level FROM codeslog ORDER BY id DESC LIMIT 1')->fetchColumn()===102,'Transition code was logged against the next level');
        duplicateCheck(!str_contains(duplicateMessage(';transition'),'Данный код уже введён'),'Code accepted on the previous level was marked repeated after AP');
        duplicateCheck(count(Encx\Client::$posts)===10,'Code was not submitted after AP');
        Encx\Client::$level['Sectors']=[['SectorId'=>1,'Order'=>1,'IsAnswered'=>true,'Answer'=>['Answer'=>'engine-known']]];
        duplicateCheck(str_ends_with(duplicateMessage(';ENGINE-KNOWN'),'Данный код уже введён'),'Code entered on the site was not detected');
        duplicateCheck(count(Encx\Client::$posts)===11,'Closed sector code was not posted again');
        Encx\Client::$level['Sectors']=[];
        Encx\Client::$level['Bonuses']=[['BonusId'=>1,'IsAnswered'=>true,'Answer'=>'engine-bonus']];
        duplicateCheck(str_ends_with(duplicateMessage(';engine-bonus'),'Данный код уже введён'),'Closed bonus code was not detected');
        duplicateCheck(count(Encx\Client::$posts)===12,'Closed bonus code was not posted again');
        Encx\Client::$level['Bonuses']=[['BonusId'=>2,'IsAnswered'=>false]];
        duplicateMessage(';bonus-code');
        duplicateCheck(count(Encx\Client::$posts)===14 && end(Encx\Client::$posts)['bonus'],'Bonus was not submitted through its own field');
        duplicateCheck(str_ends_with(duplicateMessage(';bonus-code'),'Данный код уже введён') && count(Encx\Client::$posts)===16,'Accepted bonus was not submitted again or marked repeated');
        Encx\Client::$level['Bonuses']=[];
        set_setting('dr_noprefix','true',-1001);
        duplicateMessage('1др3');
        duplicateCheck(str_ends_with(duplicateMessage('1dr3//comment'),'Данный код уже введён') && count(Encx\Client::$posts)===18,'DR repeat was not sent or layout/comment bypassed detection');
        $db->exec('UPDATE games SET status=2 WHERE chat_id=-1001');
        duplicateMessage(';recorded-only'); duplicateMessage(';recorded-only');
        duplicateCheck(count(Encx\Client::$posts)===18,'Record-only mode submitted a code');
        $db->exec('UPDATE games SET status=1 WHERE chat_id=-1001');
        duplicateCheck(!str_contains(duplicateMessage(';recorded-only'),'Данный код уже введён'),'An unsent recorded code was treated as accepted');
        duplicateCheck(count(Encx\Client::$posts)===19,'Recorded code not submitted after enabling sending');
        $db->exec("INSERT INTO codeslog(chat_id,level,code,`return`) VALUES(-1001,104,'historical','Код принят (3/20)')");
        duplicateCheck(str_ends_with(duplicateMessage(';historical'),'Данный код уже введён') && count(Encx\Client::$posts)===20,'Existing same-level history was ignored or repeat suppressed');
        $r=sendCode('[]','test.invalid',42,'recorded-only',-1001);
        duplicateCheck(!empty($r['duplicate']) && count(Encx\Client::$posts)===21,'Bulk-resend path skipped repeat detection or submission');
        Encx\Client::$level['Sectors']=[['SectorId'=>3,'Order'=>1,'IsAnswered'=>false,'Answer'=>'unanswered']];
        duplicateCheck(!str_contains(duplicateMessage(';unanswered'),'Данный код уже введён') && count(Encx\Client::$posts)===22,'An unanswered sector was treated as closed');
        Encx\Client::$level['Sectors']=[];
        $db->exec("INSERT INTO codeslog(chat_id,level,code,`return`) VALUES(-1001,104,'legacy-transition','Код принят')");
        duplicateCheck(!str_contains(duplicateMessage(';legacy-transition'),'Данный код уже введён') && count(Encx\Client::$posts)===23,'Ambiguous legacy transition marked the next level code repeated');
        $otherDomain=sendCode('[]','other.invalid',42,'recorded-only',-1001);
        $otherGame=sendCode('[]','test.invalid',43,'recorded-only',-1001);
        duplicateCheck(empty($otherDomain['duplicate']) && empty($otherGame['duplicate']) && count(Encx\Client::$posts)===25,'Cache leaked to another domain or game');
        Encx\Client::$rejectNext=true;
        $repeat=duplicateMessage(';recorded-only');
        duplicateCheck(str_starts_with($repeat,'❌ Код не принят') && str_ends_with($repeat,'Данный код уже введён') && count(Encx\Client::$posts)===26,'Repeat notice replaced the actual engine rejection');
        echo "Duplicate code regression checks passed: $checks\n";
    } finally {
        proc_terminate($server); proc_close($server);
        $db=null;
        chdir($original);
        foreach (glob($work.'/*') as $file) unlink($file);
        rmdir($work);
    }
}
