<?php
if (PHP_SAPI === 'cli-server') {
    require __DIR__.(str_starts_with($_SERVER['REQUEST_URI'],'/tg/')?'/stub_server.php':'/code_submission_stub.php');
    return;
}
// Real FFI bindings must retain CountLevels from the original response, once.
require dirname(__DIR__).'/functions.php';
$work=sys_get_temp_dir().'/enxbot-count-test-'.bin2hex(random_bytes(6));
mkdir($work,0700);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if(!$socket)throw new RuntimeException('Could not reserve test port');
$address=stream_socket_get_name($socket,false);fclose($socket);
putenv('ENCX_ENGINE=legacy');putenv('ENCX_USE_HTTP=true');
putenv('ENXBOT_COUNT_TEST_LOG='.$work.'/requests');
putenv('ENXBOT_COUNT_TEST_LEVEL='.$work.'/current-level');
putenv('ENXBOT_TG_LOG='.$work.'/telegram.jsonl');
putenv('ENXBOT_PID_FILE='.$work.'/daemon.pid');
file_put_contents($work.'/current-level','101');
$server=proc_open([PHP_BINARY,'-S',$address,__FILE__],[
    0=>['pipe','r'],1=>['file',$work.'/server.log','a'],2=>['file',$work.'/server.log','a'],
],$pipes);
if(!is_resource($server))throw new RuntimeException('Could not start test engine');
fclose($pipes[0]);
$checks=0;
$daemon=null;
function countCheck($condition,$message){global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function waitCountNotice($work,$expected,$prefix=false){
    for($i=0;$i<100;$i++){
        $lines=is_file($work.'/telegram.jsonl')?file($work.'/telegram.jsonl',FILE_IGNORE_NEW_LINES):[];
        foreach($lines as $line){$call=json_decode($line,true);$text=$call['params']['text']??'';if($prefix?str_starts_with($text,$expected):$text===$expected)return $call['params'];}
        usleep(100000);
    }
    throw new RuntimeException('AP notice not received: '.$expected);
}
try {
    for($attempt=0;$attempt<30;$attempt++) {if(@file_get_contents('http://'.$address.'/health')==='ready')break;usleep(100000);}
    countCheck($attempt<30,'Local engine did not start');
    $cookies=encxNormalizeCookies('stoken=test-session;');
    $model=encxGameModel($cookies,$address,424242,true);
    countCheck(($model['CountLevels']??null)===28,'CountLevels was discarded by the typed binding');
    countCheck(empty($model['Levels']) && levelHeading($model,$address,424242)==='Уровень 7 из 28','Closed statistics hid total levels');
    countCheck(file($work.'/requests',FILE_IGNORE_NEW_LINES)===['GET'],'Count metadata performed an extra request or POST');
    $db=new PDO('sqlite:'.$work.'/state.sqlite');$db->exec('PRAGMA journal_mode=WAL;PRAGMA busy_timeout=5000');
    $db->exec(file_get_contents(dirname(__DIR__).'/db.sql'));
    $db->exec("INSERT INTO games(chat_id,game_id,game_domain,last_level_id,status) VALUES(-1001,424242,'".$address."',100,1);
        INSERT INTO codeslog(chat_id,level) VALUES(-1001,100),(-1001,200),(-1001,300),(-1001,400);");
    $notice=levelAnnouncement($cookies,$address,424242,-1001);
    countCheck($notice['heading']==='Уровень 5 из 28','AP used engine numbering instead of order of visits');
    countCheck(str_starts_with($notice['text'],'<b>Уровень 5 из 28</b>'),'AP task text lost the heading');
    countCheck($notice['hints']['levelid']===101,'AP timers used a different model');
    countCheck(file($work.'/requests',FILE_IGNORE_NEW_LINES)===['GET','GET'],'AP fetched multiple models for title, task and hints');
    $root=dirname(__DIR__);
    foreach(['daemon.php','functions.php','encounter.php','db.php','db.sql','Html2Text.php'] as $file)copy($root.'/'.$file,$work.'/'.$file);
    file_put_contents($work.'/config.php','<?php $sqlite_path=__DIR__."/state.sqlite"; define("API_URL",'.var_export('http://'.$address.'/tg/',true).');define("BOT_USERNAME","testbot");define("PHANTOMJS","/nonexistent/renderer");');
    $db->exec("UPDATE games SET cookies='stoken=test-session;';INSERT INTO timers(chat_id,game_id,level_id,time,type) VALUES(-1001,424242,100,".(time()-5).",2)");
    $daemon=proc_open([PHP_BINARY,'-d','display_errors=stderr','-r','chdir($argv[1]);require "daemon.php";',$work],[
        0=>['pipe','r'],1=>['file',$work.'/daemon.log','a'],2=>['file',$work.'/daemon.log','a'],
    ],$daemonPipes);
    if(!is_resource($daemon))throw new RuntimeException('Could not start daemon');
    fclose($daemonPipes[0]);
    $timerNotice=waitCountNotice($work,"<b>АП</b>\n<b>Уровень 5 из 28</b>");
    countCheck($timerNotice['parse_mode']==='HTML','Timer AP lost HTML title');
    waitCountNotice($work,"<b>Уровень 5 из 28</b>\nFixture task",true);
    for($i=0;$i<50;$i++){if((int)$db->query('SELECT COUNT(*) FROM timers')->fetchColumn()===0)break;usleep(100000);}
    countCheck($i<50,'Timer AP was not consumed');
    file_put_contents($work.'/current-level','102');
    $db->exec('UPDATE games SET last_level_id=102');
    $engineNotice=waitCountNotice($work,"<b>АП</b> (по движку)\n<b>Уровень 6 из 28</b>");
    countCheck($engineNotice['parse_mode']==='HTML','Engine AP lost HTML title');
    usleep(1200000);
    $calls=array_map(static fn($line)=>json_decode($line,true),file($work.'/telegram.jsonl',FILE_IGNORE_NEW_LINES));
    $notices=array_filter($calls,static fn($call)=>str_starts_with($call['params']['text']??'','<b>АП</b>'));
    countCheck(count($notices)===2,'Repeated daemon poll duplicated AP notice');
    countCheck(!preg_match('/Fatal|Warning|Deprecated|Notice/',file_get_contents($work.'/daemon.log')),'Daemon emitted PHP diagnostics');
    echo "Level count FFI checks passed: $checks\n";
} finally {
    if(is_resource($daemon)){proc_terminate($daemon);proc_close($daemon);}
    proc_terminate($server);proc_close($server);$db=null;
    foreach(glob($work.'/*') as $file)unlink($file);rmdir($work);
}
