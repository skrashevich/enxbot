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
echo "Level text regression checks passed: $checks\n";
