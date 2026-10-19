<?php

/**
 * Thin compatibility layer between the historic bot functions and encx-cli's
 * generated PHP bindings.  The bot stores ExportCookies() verbatim in SQLite,
 * so every short-lived PHP process can restore the Encounter session.
 */

function encxBindingsPath()
{
    if (defined('ENCX_BINDINGS_PATH')) {
        return rtrim((string)ENCX_BINDINGS_PATH, '/');
    }

    $configured = getenv('ENCX_BINDINGS_PATH');
    if (is_string($configured) && $configured !== '') {
        return rtrim($configured, '/');
    }

    return dirname(__DIR__).'/en-app-research/bindings/php';
}

function encxLoadBindings()
{
    if (!class_exists('Encx\\Client') || !class_exists('Encx\\Helpers')) {
        $autoload = encxBindingsPath().'/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException(
                'PHP-биндинги encx-cli не найдены: '.$autoload.
                '. Задайте ENCX_BINDINGS_PATH.'
            );
        }
        require_once $autoload;
    }
    if (!method_exists('Encx\\Client', 'resolveEngine') || !method_exists('Encx\\Helpers', 'levelSectorProgress')) {
        throw new RuntimeException('Требуются PHP-биндинги encx-cli v0.26.0 или новее. Пересоберите libencx и биндинги вместе.');
    }
}

function encxBoolEnv($name, $default)
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        return $default;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function encxUsesHTTP($domain)
{
    $configured = getenv('ENCX_USE_HTTP');
    if (is_string($configured) && $configured !== '') {
        return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    }

    $host = parse_url('http://'.$domain, PHP_URL_HOST);
    return in_array($host, array('127.0.0.1', 'localhost', '::1'), true);
}

/** Convert the cookie-header format used by old enxbot releases on first use. */
function encxNormalizeCookies($cookies)
{
    $cookies = trim((string)$cookies);
    if ($cookies === '') {
        return '';
    }

    json_decode($cookies, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $cookies;
    }

    $saved = array();
    foreach (explode(';', $cookies) as $part) {
        $pair = explode('=', trim($part), 2);
        if ($pair[0] === '') {
            continue;
        }
        $saved[] = array(
            'name' => $pair[0],
            'value' => isset($pair[1]) ? $pair[1] : '',
            'path' => '/',
            'domain' => '',
            'expires' => '0001-01-01T00:00:00Z',
            'secure' => false,
            'httpOnly' => false,
        );
    }

    return json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Return a Cookie header for the legacy PhantomJS screenshot helper. */
function encxCookieHeader($cookies)
{
    $decoded = json_decode(encxNormalizeCookies($cookies), true);
    if (isset($decoded['cookies']) && is_array($decoded['cookies'])) {
        $decoded = $decoded['cookies'];
    }
    if (!is_array($decoded)) {
        return '';
    }
    $parts = array();
    foreach ($decoded as $cookie) {
        if (is_array($cookie) && isset($cookie['name'])) {
            $parts[] = $cookie['name'].'='.(string)($cookie['value'] ?? '');
        }
    }
    return implode('; ', $parts).($parts ? ';' : '');
}

/** Persist only confirmed library selections; null leaves auto retryable. */
function encxResolvedEngine($domain, $client)
{
    global $db;
    static $known = array();
    $key = strtolower(trim((string)$domain));
    if (isset($known[$key])) {
        return $known[$key];
    }
    if (isset($db) && $db instanceof PDO) {
        $read = $db->prepare('SELECT engine FROM engine_cache WHERE domain = :domain AND confirmed = 1');
        $read->execute(array(':domain' => $key));
        $cached = $read->fetchColumn();
        if (in_array($cached, array('legacy', 'new'), true)) {
            return $known[$key] = $cached;
        }
    }
    $resolution = encxDecode($client->resolveEngine());
    $engine = $resolution['engine'] ?? null;
    if (!in_array($engine, array('legacy', 'new'), true)) {
        throw new RuntimeException('Не удалось определить движок Encounter');
    }
    if (($resolution['definitive'] ?? false) !== true) {
        return null;
    }
    if (isset($db) && $db instanceof PDO) {
        $write = $db->prepare('INSERT INTO engine_cache (domain, engine, confirmed) VALUES (:domain, :engine, 1)'
            .' ON CONFLICT(domain) DO UPDATE SET engine = excluded.engine, confirmed = 1 WHERE engine_cache.confirmed = 0');
        $write->execute(array(':domain' => $key, ':engine' => $engine));
        // A concurrent first action may already have saved the domain's selection.
        $read->execute(array(':domain' => $key));
        $engine = $read->fetchColumn();
    }
    return $known[$key] = $engine;
}

function encxClient($domain, $cookies = '')
{
    encxLoadBindings();

    $timeoutSeconds = 60;
    $client = Encx\Client::newClientWithOptions(
        (string)$domain,
        encxBoolEnv('ENCX_INSECURE_TLS', false),
        encxUsesHTTP((string)$domain),
        $timeoutSeconds,
        'ru'
    );
    // The mobile-backed bindings otherwise limit code POSTs to one second,
    // independently of the HTTP timeout passed to the constructor.
    $client->setCodeSendTimeoutSeconds($timeoutSeconds);
    $engine = strtolower(trim((string)getenv('ENCX_ENGINE')));
    $client->setEngine(in_array($engine, array('legacy', 'new'), true) ? $engine : 'auto');
    if (!in_array($engine, array('legacy', 'new'), true)) {
        // A failed probe is a temporary fallback, not a backend selection.
        $resolved = encxResolvedEngine($domain, $client);
        if ($resolved !== null) {
            $client->setEngine($resolved);
        }
    }

    $cookies = encxNormalizeCookies($cookies);
    if ($cookies !== '') {
        $client->importCookies($cookies);
    }

    return $client;
}

function encxDecode($json)
{
    $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        throw new RuntimeException('encx вернул JSON неожиданного типа');
    }
    return $value;
}

function encxGameModel($cookies, $domain, $gameid)
{
    $client = encxClient($domain, $cookies);
    try {
        return encxDecode($client->getGameModel((int)$gameid));
    } finally {
        $client->close();
    }
}

/**
 * Заявляет участие в игре (аналог кнопки "Вход в игру" / makefee на сайте).
 * Без этого шага движок не отдаёт страницу уровня даже принятому участнику.
 * Возвращает обновлённые куки сессии либо бросает исключение.
 */
function encxEnterGame($cookies, $domain, $gameid)
{
    $client = encxClient($domain, $cookies);
    try {
        $client->enterGame((int)$gameid);
        return $client->exportCookies();
    } finally {
        $client->close();
    }
}

function encxLevel($model)
{
    return isset($model['Level']) && is_array($model['Level']) ? $model['Level'] : null;
}

/** Adapt a snapshot to the library's progress helper; never recalculate it here. */
function encxSectorProgress($level, $completed = false)
{
    encxLoadBindings();
    if ($completed && is_array($level)) {
        $level['IsPassed'] = true;
    }
    return encxDecode(Encx\Helpers::levelSectorProgress(json_encode($level, JSON_THROW_ON_ERROR)));
}

function encxTaskHTML($level)
{
    if (!$level) {
        return '';
    }
    $task = isset($level['Task']) && is_array($level['Task']) ? $level['Task'] : null;
    if ($task === null && isset($level['Tasks'][0]) && is_array($level['Tasks'][0])) {
        $task = $level['Tasks'][0];
    }
    if ($task === null) {
        return '';
    }
    return (string)($task['TaskTextFormatted'] ?? $task['TaskText'] ?? '');
}

function encxAnswerText($answer)
{
    if (is_array($answer)) {
        return (string)($answer['Answer'] ?? '');
    }
    return is_scalar($answer) ? (string)$answer : '';
}
