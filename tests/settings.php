<?php
// Verify structured keyboards in the actual JSON transport. The local mock
// cannot check whether a particular Telegram client renders its buttons.
$root = dirname(__DIR__);
$original = getcwd();
$work = sys_get_temp_dir().'/enxbot-settings-'.bin2hex(random_bytes(6));
mkdir($work, 0700);
foreach (['bot.php','functions.php','encounter.php','db.php','db.sql','Html2Text.php'] as $file) {
    copy($root.'/'.$file, $work.'/'.$file);
}
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Could not reserve test port');
$address = stream_socket_get_name($socket, false);
fclose($socket);
file_put_contents($work.'/config.php', '<?php $sqlite_path=__DIR__."/state.sqlite";'
    .'define("API_URL",'.var_export('http://'.$address.'/tg/', true).');'
    .'define("ENCRYPTION_KEY","test-key");define("BOT_USERNAME","testbot");'
    .'define("ADMIN_USERNAME","rootadmin");define("LOCATION_MAP_GAMENAME","testmap");');
putenv('ENXBOT_TG_LOG='.$work.'/telegram.jsonl');
$server = proc_open([PHP_BINARY,'-S',$address,__DIR__.'/stub_server.php'], [
    0=>['pipe','r'],1=>['file',$work.'/server.log','a'],2=>['file',$work.'/server.log','a'],
], $pipes);
if (!is_resource($server)) throw new RuntimeException('Could not start Telegram stub');
fclose($pipes[0]);
$checks = 0;
function settingsCheck($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function settingsCalls() {
    global $work;
    return array_map(function ($line) {return json_decode($line, true, 512, JSON_THROW_ON_ERROR);},
        file($work.'/telegram.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}
function settingsLastCall() {return array_slice(settingsCalls(), -1)[0];}
function settingsMessage($text, $chat = -1001) {
    handleUpdate(['message'=>['message_id'=>1,'chat'=>['id'=>$chat,'title'=>'Test'],
        'from'=>['id'=>7,'username'=>'rootadmin'],'text'=>$text]]);
}
function settingsPress($callback, $admin = true) {
    handleUpdate(['callback_query'=>['id'=>'test','message'=>['message_id'=>4242,'chat'=>['id'=>-1001]],
        'from'=>['id'=>$admin ? 7 : 8,'username'=>$admin ? 'rootadmin' : 'player'],'data'=>$callback]]);
}
function settingsKeyboard($call) {
    settingsCheck(is_array($call['params']['reply_markup'] ?? null), 'reply_markup must be a JSON object, not a JSON string');
    $rows = $call['params']['reply_markup']['inline_keyboard'] ?? [];
    settingsCheck(count($rows) === count(settingsToggles()), 'Some settings buttons are missing');
    $buttons = [];
    foreach ($rows as $row) foreach ($row as $button) {
        $parsed = parseSettingsCallback($button['callback_data']);
        settingsCheck($parsed !== false && $parsed['chat_id'] === -1001 && strlen($button['callback_data']) <= 64,
            'Invalid button signature, chat or callback length');
        $buttons[$parsed['name']] = $button['text'];
    }
    return $buttons;
}
try {
    for ($attempt=0; $attempt<30; $attempt++) {
        if (@file_get_contents('http://'.$address.'/tg/getMe') !== false) break;
        usleep(100000);
    }
    settingsCheck($attempt < 30, 'Telegram stub did not start');
    chdir($work);
    require 'bot.php';
    $db->exec("INSERT INTO admins(admin_username) VALUES('rootadmin')");
    settingsMessage('/settings');
    $call = settingsLastCall();
    settingsCheck($call['method'] === 'sendMessage' && $call['params']['text'] === 'Настройки игры', 'Settings menu not sent');
    $buttons = settingsKeyboard($call);
    settingsCheck(str_ends_with($buttons['dr_noprefix'], '🚫'), 'Disabled setting not shown');
    settingsCheck(get_setting('last_settings_message_id', -1001) === '4242', 'Menu message ID not saved');
    $callback = settingsCallbackData('dr_noprefix', -1001);
    settingsPress($callback);
    $call = settingsLastCall();
    settingsCheck($call['method'] === 'editMessageText' && $call['params']['message_id'] === 4242, 'Menu not updated in place');
    $buttons = settingsKeyboard($call);
    settingsCheck(get_setting('dr_noprefix', -1001) === 'true' && str_ends_with($buttons['dr_noprefix'], '✅'), 'Toggle not reflected in menu');
    settingsCheck(get_setting('dr_noprefix', -1002) === false, 'Setting leaked to another chat');
    settingsPress($callback, false);
    settingsCheck(get_setting('dr_noprefix', -1001) === 'true' && settingsLastCall()['method'] === 'answerCallbackQuery', 'Non-admin changed settings');
    settingsPress($callback);
    $buttons = settingsKeyboard(settingsLastCall());
    settingsCheck(get_setting('dr_noprefix', -1001) === 'false' && str_ends_with($buttons['dr_noprefix'], '🚫'), 'Toggle could not be disabled');
    $before = count(settingsCalls());
    settingsMessage('/settings@testbot');
    $calls = array_slice(settingsCalls(), $before);
    settingsCheck($calls[0]['method'] === 'deleteMessage' && $calls[0]['params']['message_id'] === 4242, 'Old menu not replaced');
    settingsKeyboard(settingsLastCall());
    foreach (['/keyboard','/keyboard /a,/b,/c','/keyboard off'] as $command) {
        settingsMessage($command);
        $markup = settingsLastCall()['params']['reply_markup'];
        settingsCheck(is_array($markup), 'Reply keyboard is double encoded: '.$command);
        settingsCheck($command === '/keyboard off' ? ($markup['remove_keyboard'] ?? false) === true : !empty($markup['keyboard']), 'Reply keyboard missing: '.$command);
    }
    settingsMessage('/game codes map');
    $call = settingsLastCall();
    settingsCheck($call['method'] === 'sendGame' && is_array($call['params']['reply_markup'] ?? null), 'Game keyboard is double encoded');
    echo "Settings transport regression checks passed: $checks\n";
} finally {
    proc_terminate($server); proc_close($server);
    $db = null;
    chdir($original);
    foreach (glob($work.'/*') as $file) unlink($file);
    rmdir($work);
}
