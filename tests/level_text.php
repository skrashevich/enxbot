<?php
require dirname(__DIR__).'/functions.php';
$model = ['Level' => ['Number' => 16, 'Task' => ['TaskText' => '<p>Текст уровня</p>']]];
$checks = 0;
foreach ([[], null, 'hidden', array_fill(0, 15, [])] as $hidden) {
    $model['Levels'] = $hidden;
    if (trim(getLevelText('', 'test.invalid', 42, $model)) !== "<b>Уровень 16</b>\nТекст уровня") {
        throw new RuntimeException('Hidden or incomplete statistics produced a false total');
    }
    $checks++;
}
unset($model['Levels']);
if (trim(getLevelText('', 'test.invalid', 42, $model)) !== "<b>Уровень 16</b>\nТекст уровня") {
    throw new RuntimeException('Missing statistics produced an unknown total');
}
$checks++;
$model['Levels'] = array_fill(0, 20, []);
if (trim(getLevelText('', 'test.invalid', 42, $model)) !== "<b>Уровень 16 из 20</b>\nТекст уровня") {
    throw new RuntimeException('Visible statistics no longer show the real total');
}
$checks++;
$model['Level'] = null;
if (getLevelText('', 'test.invalid', 42, $model) !== false) {
    throw new RuntimeException('Missing level produced a false task');
}
$checks++;
$model['Level'] = ['Number'=>17,'LevelId'=>107,'Task'=>['TaskText'=>'<p>Текст уровня</p>']];
$model['Levels'] = [];
$model['CountLevels'] = 28;
if (trim(getLevelText('', 'test.invalid', 42, $model)) !== "<b>Уровень 17 из 28</b>\nТекст уровня") {
    throw new RuntimeException('CountLevels was lost with hidden statistics');
}
$checks++;
$db = new PDO('sqlite::memory:');
$db->exec(file_get_contents(dirname(__DIR__).'/db.sql'));
$db->exec("INSERT INTO games(chat_id,game_id,game_domain,last_level_id,status) VALUES(-1001,42,'test.invalid',101,1),(-1002,42,'test.invalid',201,1);
INSERT INTO codeslog(chat_id,level) VALUES(-1001,100),(-1001,100);
INSERT INTO codes(chat_id,level) VALUES(-1001,102);
INSERT INTO timers(chat_id,level_id) VALUES(-1001,103);
INSERT INTO coords(chat_id,level) VALUES(-1001,104);
INSERT INTO locations(chat_id,level) VALUES(-1001,105);");
if (levelProgressNumber(-1001,'test.invalid',42,106)!==7 || levelProgressNumber(-1001,'test.invalid',42,106)!==7) {
    throw new RuntimeException('History was not deduplicated or repeated current level advanced progress');
}
$checks++;
if (levelHeading($model,'test.invalid',42,-1001)!=='Уровень 8 из 28') {
    throw new RuntimeException('Heading used engine number instead of visit order');
}
$checks++;
if (levelHeading($model,'test.invalid',42,-1002)!=='Уровень 2 из 28') {
    throw new RuntimeException('Game progress leaked between chats');
}
$checks++;
$rich=getLevelRichHtml('', 'test.invalid',42,$model,-1001);
if (!str_starts_with($rich,'<h2>Уровень 8 из 28</h2>')) throw new RuntimeException('Rich level heading lost visit order or total');
$checks++;
$db=null;
echo "Level text regression checks passed: $checks\n";
