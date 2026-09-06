<?php
/**
 * Веб-карта точек уровня.
 *
 * Открывается по ссылке из Telegram Game (кнопка "/game codes map").
 * Доступ подписан: без корректного токена страница точки не отдаёт, иначе
 * любой, зная chat_id, видел бы точки чужой команды.
 *
 * Данные передаются в JavaScript отдельным блоком application/json, а не
 * подстановкой в строковые литералы, поэтому кавычки и теги в присланных
 * игроками заголовках точек безопасны.
 */

error_reporting(E_ALL & ~E_NOTICE);

include('config.php');
include('db.php');
include('functions.php');

$chat_id = isset($_GET['c']) ? intval($_GET['c']) : 0;
$level = isset($_GET['l']) ? intval($_GET['l']) : 0;
$token = isset($_GET['t']) ? (string)$_GET['t'] : '';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; script-src 'self' 'unsafe-inline' https://api-maps.yandex.ru; connect-src https://api-maps.yandex.ru https://*.maps.yandex.net; img-src https: data:; style-src 'unsafe-inline'");

if ($chat_id === 0 || !mapTokenValid($chat_id, $level, $token)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Доступ закрыт</title><p>Ссылка недействительна.';
    return;
}

$points = array();
$result = db_query($db, 'SELECT lat, lon, title, sender_name, type FROM locations WHERE chat_id = '.$chat_id.' AND level = '.$level.' ORDER BY id');
while ($row = db_fetch_assoc($result)) {
    $points[] = array(
        'lat' => (float)$row['lat'],
        'lon' => (float)$row['lon'],
        'title' => (string)$row['title'],
        'sender' => (string)$row['sender_name'],
        'status' => locationTypeName($row['type']),
    );
}

$json = json_encode($points, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Точки уровня <?= htmlspecialchars((string)$level, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
<style>
  html, body { margin: 0; height: 100%; font: 14px/1.4 system-ui, sans-serif; }
  #map { width: 100%; height: 100%; }
  #empty { padding: 1rem; }
</style>
</head>
<body>
<script type="application/json" id="points"><?= $json ?></script>
<?php if (!$points): ?>
<p id="empty">На этом уровне точек пока нет.</p>
<?php else: ?>
<div id="map"></div>
<script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU"></script>
<script>
(function () {
    var points = JSON.parse(document.getElementById('points').textContent);
    if (!points.length || typeof ymaps === 'undefined') {
        return;
    }

    ymaps.ready(function () {
        var map = new ymaps.Map('map', {
            center: [points[0].lat, points[0].lon],
            zoom: 14,
            controls: ['zoomControl', 'typeSelector', 'fullscreenControl']
        });

        points.forEach(function (point, index) {
            var caption = (index + 1) + '. ' + point.status;
            var body = [point.title, point.sender].filter(Boolean).join(' — ');

            map.geoObjects.add(new ymaps.Placemark(
                [point.lat, point.lon],
                { iconCaption: caption, balloonContentHeader: caption, balloonContentBody: body },
                { preset: 'islands#blueDotIconWithCaption' }
            ));
        });

        if (points.length > 1) {
            map.setBounds(map.geoObjects.getBounds(), { checkZoomRange: true, zoomMargin: 40 });
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
