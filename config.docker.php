<?php

function docker_env($name, $default = null, $required = false)
{
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return $value;
    }
    if ($required) {
        throw new RuntimeException('Не задана переменная окружения '.$name);
    }
    return $default;
}

define('BOT_TOKEN', docker_env('BOT_TOKEN', null, true));
define('API_URL', docker_env('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/'));
define('ENCRYPTION_KEY', docker_env('ENCRYPTION_KEY', null, true));
define('BOT_USERNAME', docker_env('BOT_USERNAME', null, true));
define('ADMIN_USERNAME', docker_env('ADMIN_USERNAME', ''));
define('PHANTOMJS', docker_env('PHANTOMJS', '/usr/local/bin/enxbot-screenshot'));

$public_url = docker_env('PUBLIC_URL');
if ($public_url !== null) {
    define('PUBLIC_URL', rtrim($public_url, '/'));
}

$geocoder_url = docker_env('GEOCODER_URL');
if ($geocoder_url !== null) {
    define('GEOCODER_URL', $geocoder_url);
}

$location_map_gamename = docker_env('LOCATION_MAP_GAMENAME');
if ($location_map_gamename !== null) {
    define('LOCATION_MAP_GAMENAME', $location_map_gamename);
}

$sqlite_path = docker_env('ENXBOT_SQLITE_PATH', '/data/enxbot.sqlite');

unset($public_url, $geocoder_url, $location_map_gamename);
