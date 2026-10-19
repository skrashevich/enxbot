<?php
// Local-only fixture; every answer and cookie in this test is synthetic.
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/health') {
    echo 'ready';
    return;
}
$cookie = $_SERVER['HTTP_COOKIE'] ?? '';
$answer = $_POST['LevelAction_Answer'] ?? $_POST['BonusAction_Answer'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents(getenv('ENXBOT_CODE_TEST_LOG'), json_encode([
        'session_valid' => str_contains($cookie, 'stoken=test-session'),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'level_id' => (int)($_POST['LevelId'] ?? 0),
        'level_number' => (int)($_POST['LevelNumber'] ?? 0),
        'answer' => $answer,
    ])."\n", FILE_APPEND);
}
header('Content-Type: application/json');
if (!str_contains($cookie, 'stoken=test-session') && !str_contains($cookie, 'stoken=finished')) {
    echo json_encode(['Event' => 4, 'Level' => null]);
    return;
}
if (str_contains($cookie, 'stoken=finished')) {
    echo json_encode(['Event' => 17, 'Level' => null]);
    return;
}
$gameId = (int)basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($gameId === 424247) {
    $level = ['LevelId'=>101,'Number'=>7,'RequiredSectorsCount'=>1,'SectorsLeftToClose'=>1,'Sectors'=>[],'Bonuses'=>[]];
    if ($answer === 'implicit-level-code') $level = ['LevelId'=>102,'Number'=>8,'Sectors'=>[],'Bonuses'=>[]];
    echo json_encode(['Event'=>0,'GameId'=>$gameId,'Level'=>$level,
        'EngineAction'=>['LevelAction'=>['IsCorrectAnswer'=>$answer === 'implicit-level-code']]]);
    return;
}
if (in_array($gameId, [424243, 424244, 424245, 424246], true)) {
    $sectors = [
        ['SectorId'=>201,'Order'=>1,'Name'=>'1. 1+','IsAnswered'=>false],
        ['SectorId'=>202,'Order'=>2,'Name'=>'2. А_Б * [вход] <арка>','IsAnswered'=>false],
        ['SectorId'=>203,'Order'=>3,'Name'=>'3. 1+','IsAnswered'=>true],
        ['SectorId'=>204,'Order'=>4,'Name'=>'','IsAnswered'=>false],
    ];
    if ($gameId === 424244) {
        foreach ($sectors as &$sector) $sector['IsAnswered'] = $sector['SectorId'] !== 202;
        unset($sector);
    }
    $level = ['LevelId'=>101,'Number'=>7,'Sectors'=>$sectors,'Bonuses'=>[]];
    if ($gameId === 424245) $level['RequiredSectorsCount'] = 3;
    if ($gameId === 424246) $level['RequiredSectorsCount'] = 1;
    if (in_array($answer, ['single-sector-code','multiple-sector-code','rejected-sector-code'], true)) $level['Sectors'][1]['IsAnswered'] = true;
    if ($answer === 'multiple-sector-code') {
        $level['Sectors'][0]['IsAnswered'] = true;
        $level['Sectors'] = array_reverse($level['Sectors']);
    }
    if ($answer === 'unnamed-sector-code') $level['Sectors'][3]['IsAnswered'] = true;
    if ($answer === 'foreign-sector-code') {
        $level['Sectors'][0] = ['SectorId'=>999,'Order'=>1,'Name'=>'Unrelated sector','IsAnswered'=>true];
    }
    if ($answer === 'next-level-code') $level = ['LevelId'=>102,'Number'=>8,'Sectors'=>[['SectorId'=>999,'Order'=>2,'Name'=>'Next level sector','IsAnswered'=>true]],'Bonuses'=>[]];
    echo json_encode([
        'Event'=>$answer === 'last-sector-code' ? 17 : 0,
        'GameId'=>$gameId,'GameTitle'=>'Synthetic sector result',
        'Level'=>$answer === 'last-sector-code' ? null : $level,
        'EngineAction'=>['LevelAction'=>['IsCorrectAnswer'=>$answer !== 'rejected-sector-code']],
    ]);
    return;
}
if ($answer === 'slow-test-code') usleep(1500000);
if ($answer === 'bad-response') {
    echo '{invalid JSON';
    return;
}
if ($answer === 'session-lost') {
    echo json_encode(['Event' => 4, 'Level' => null]);
    return;
}
if ($answer === 'finish-test-code') {
    echo json_encode(['Event' => 17, 'Level' => null,
        'EngineAction' => ['LevelAction' => ['IsCorrectAnswer' => true]]]);
    return;
}
echo json_encode([
    'Event' => 0, 'GameId' => 424242, 'GameTitle' => 'Local code submission test',
    'Level' => ['LevelId' => 101, 'Number' => 7, 'Sectors' => [], 'Bonuses' => []],
    'EngineAction' => ['LevelAction' => ['IsCorrectAnswer' => $answer !== 'wrong-test-code']],
]);
