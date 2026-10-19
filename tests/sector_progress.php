<?php
// Exercise the actual encx-cli helper through FFI, including the bot's projection.
require dirname(__DIR__).'/functions.php';
$checks = 0;
function progressCheck($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$implicit = ['RequiredSectorsCount'=>1, 'Sectors'=>[]];
progressCheck(encxSectorProgress($implicit) === ['total'=>1, 'done'=>0, 'required'=>1, 'remaining'=>1], 'Implicit level code lost its progress');
progressCheck(encxSectorProgress($implicit, true)['remaining'] === 0, 'A completed previous level retained remaining answers');
progressCheck(!isset($implicit['IsPassed']), 'Reporting completion modified the input snapshot');
progressCheck(encxSectorProgress(null)['remaining'] === null, 'An unknown level was reported as completed');
progressCheck(encxSectorProgress(['Bonuses'=>[['Number'=>1]]])['remaining'] === null, 'A bonus-only level invented sector progress');
$level = ['RequiredSectorsCount'=>3, 'PassedSectorsCount'=>2, 'SectorsLeftToClose'=>1,
    'Sectors'=>[['Order'=>1,'IsAnswered'=>true],['Order'=>2],['Order'=>3],['Order'=>4]]];
progressCheck(encxSectorProgress($level) === ['total'=>4,'done'=>2,'required'=>3,'remaining'=>1], 'The bot ignored the engine aggregate counters');
$sectors = getSectors('', 'test.invalid', 42, ['Level'=>$level]);
progressCheck(str_contains($sectors['text'], 'Закрыто 2'), 'Sector summary recalculated the library counter from flags');
progressCheck(str_ends_with($sectors['text'], 'Осталось до АП: 1'), 'Sector summary ignored the library passing threshold');
progressCheck(synthSectorForLevel($implicit)['sectors'][1]['found'] === false, 'The implicit open code disappeared from lists');
$implicit['PassedSectorsCount'] = 1;
progressCheck(synthSectorForLevel($implicit)['sectors'][1]['found'] === true, 'The implicit completed code was not closed in lists');
echo "Sector progress integration checks passed: $checks\n";
