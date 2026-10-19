<?php
require dirname(__DIR__).'/functions.php';
$model = ['Level' => ['Sectors' => [
    ['Order' => 1, 'Name' => '1. 1+', 'IsAnswered' => false],
    ['Order' => 2, 'Name' => '2. 1+', 'IsAnswered' => false],
    ['Order' => 3, 'Name' => '3. 2+', 'IsAnswered' => true, 'Answer' => 'test-code'],
    ['Order' => 4, 'Name' => '', 'IsAnswered' => false],
    ['Order' => 5, 'IsAnswered' => false],
    ['Order' => 6, 'Name' => '6. А_Б * [карта] `код` \\ & <вход>', 'IsAnswered' => false],
]]];
$checks = 0;
function labelCheck($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$sectors = getSectors('', 'test.invalid', 42, $model)['sectors'];
labelCheck(array_keys($sectors) === [1, 2, 3, 4, 5, 6], 'Display labels changed the sector IDs');
$open = formatCodeList($sectors, 'open', 'Незакрытые метки:');
labelCheck(str_contains($open, "\n<i>1. 1+</i>\n<i>2. 1+</i>"), 'Open list lost the full engine names or duplicated the sector numbers');
labelCheck(!str_contains($open, '3. 2+'), 'Open list contains an answered sector');
labelCheck(str_contains($open, "\n<i>4</i>\n<i>5</i>"), 'Unnamed sectors lost their numeric fallback');
$special = '<i>6. А_Б * [карта] `код` \\ &amp; &lt;вход&gt;</i>';
labelCheck(str_contains($open, $special), 'Special characters or HTML entities were lost or broke formatting');
$closed = formatCodeList($sectors, 'closed', 'Закрытые метки:');
labelCheck($closed === "Закрытые метки:\n<b>3. 2+:\ttest-code</b>", 'Closed list lost its full label or code');
$all = formatCodeList($sectors, 'all', 'Все метки:');
labelCheck(str_contains($all, '<i>1. 1+</i>') && str_contains($all, "<b>3. 2+:\ttest-code</b>"), 'All-sector list dropped names');
$sectors[2]['find'] = true;
labelCheck(str_contains(formatCodeList($sectors, 'open', 'T'), '<i>2. 1+</i> 📍'), 'Found marker hid the sector name');
$bonus = [1 => ['name' => 'Бонус_А', 'found' => false]];
labelCheck(formatCodeList($bonus, 'open', 'Бонусы:') === "Бонусы:\n<i>1 Бонус_А</i>", 'Bonus numbering or name was changed');
$plain = [1 => ['found' => true, 'code' => 'en1'], 2 => ['found' => false]];
labelCheck(formatCodeList($plain, 'all', 'T') === "T\n<b>1:\ten1</b>\n<i>2</i>", 'Numeric-only labels changed');
$escapedCode = [1 => ['label' => '1. 1+', 'found' => true, 'code' => 'test_*[x]<&']];
labelCheck(formatCodeList($escapedCode, 'closed', 'T') === "T\n<b>1. 1+:\ttest_*[x]&lt;&amp;</b>", 'Answer text broke formatting');
labelCheck(formatCodeList([], 'open', 'T') === "T\n(пусто)", 'Empty list fallback changed');
echo "Sector label regression checks passed: $checks\n";
