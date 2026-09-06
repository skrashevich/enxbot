<?php
// Конфигурация для смоук-теста. Копируется во временный каталог как config.php.

define('BOT_TOKEN', 'test-token');
define('PUBLIC_URL', 'http://127.0.0.1:18080');
define('API_URL', 'http://127.0.0.1:18081/tg/');
define('ENCRYPTION_KEY', 'test-encryption-key');
define('BOT_USERNAME', 'enxtestbot');
define('ADMIN_USERNAME', 'rootadmin');
define('PHANTOMJS', '/nonexistent/phantomjs');
define('ROUTER_URL', 'http://127.0.0.1:18081/route');
define('GEOCODER_URL', 'http://127.0.0.1:18081/geocode');

$sqlite_path = __DIR__.'/enxbot.sqlite';
