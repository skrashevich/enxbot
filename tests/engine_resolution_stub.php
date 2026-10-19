<?php
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/health') {
    echo 'ready';
    return;
}
file_put_contents(getenv('ENXBOT_RESOLUTION_LOG'), "probe\n", FILE_APPEND);
$mode = trim(file_get_contents(getenv('ENXBOT_RESOLUTION_MODE')));
header('Content-Type: application/json');
if ($mode === 'unavailable') {
    http_response_code(503);
    echo '{"error":"temporarily_unavailable"}';
} elseif ($mode === 'legacy') {
    http_response_code(404);
    echo '{"error":"domain_unregistered"}';
} else {
    echo json_encode(['id'=>135,'primary_domain'=>'modern.en.cx','is_site_active_by_rule'=>true]);
}
