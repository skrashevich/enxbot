<?php
// The renderer is a local stub; these checks never contact Telegram or Encounter.
$work = sys_get_temp_dir().'/enxbot-php-check-'.bin2hex(random_bytes(6));
mkdir($work, 0700);
mkdir($work.'/screens');
$helper = $work.'/renderer';
file_put_contents($helper, <<<'SH'
#!/bin/sh
printf '%s' "$2" > request-url.txt
printf 'stub image' > "$3"
exit "${ENXBOT_TEST_EXIT:-0}"
SH
);
chmod($helper, 0700);
define('PHANTOMJS', $helper);
require dirname(__DIR__).'/functions.php';
chdir($work);
$checks = 0;
function verify($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
try {
    putenv('ENCX_USE_HTTP');
    $image = screenshot(false, -1, '', 'city.en.cx', 42, 7);
    verify(is_string($image) && is_file($image), 'Successful renderer output was lost');
    verify(file_get_contents('request-url.txt') === 'https://city.en.cx/gameengines/encounter/play/42?lang=ru', 'Remote game did not use HTTPS');
    $image = screenshot(false, -1, '', '127.0.0.1:8080', 42, 7);
    verify(file_get_contents('request-url.txt') === 'http://127.0.0.1:8080/gameengines/encounter/play/42?lang=ru', 'Local mock HTTP support was lost');
    $before = glob('screens/*');
    putenv('ENXBOT_TEST_EXIT=2');
    verify(screenshot(false, -1, '', 'city.en.cx', 42, 7) === false, 'Authentication failure was accepted');
    verify(glob('screens/*') === $before, 'Failed renderer left an image that could be sent');
    echo "PHP screenshot regression checks passed: $checks\n";
} finally {
    foreach (glob($work.'/screens/*') as $file) unlink($file);
    rmdir($work.'/screens');
    unlink($helper);
    if (is_file($work.'/request-url.txt')) unlink($work.'/request-url.txt');
    chdir(sys_get_temp_dir());
    rmdir($work);
}
