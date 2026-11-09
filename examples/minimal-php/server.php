<?php
// The device side of OTA, as small as it gets: serves the static layout publish.php writes.
//
//   php -S 0.0.0.0:8080 server.php
//
//   GET /{app}/{channel}/manifest.json             the signed manifest (404 when nothing is published)
//   GET /{app}/{channel}/bundles/{file}.soarb       a bundle, immutable
//
// Devices check the signature and the bundle hash themselves, so this server holds no key and needs no database.
// A real deployment puts the same folder behind nginx or a CDN; the full server (channels, branches, rollouts,
// reports, an admin API) is described in docs/protocol.md and docs/api.md.

declare(strict_types=1);

$root = getenv('OTA_DATA') ?: __DIR__ . '/data';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (!preg_match('#^/([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+)/(manifest\.json|bundles/[0-9]+-[0-9a-f]{16}\.soarb)$#', $path, $m)) {
    http_response_code(404);
    exit;
}
$file = "$root/{$m[1]}/{$m[2]}/{$m[3]}";
if (!is_file($file)) {
    http_response_code(404); // nothing published for this app and channel: the device keeps what it runs
    exit;
}

$isManifest = $m[3] === 'manifest.json';
$etag = '"' . hash_file('sha256', $file) . '"';
header('ETag: ' . $etag);
header('Content-Type: ' . ($isManifest ? 'application/json' : 'application/octet-stream'));
// The manifest changes on every publish or rollout change; a bundle never changes.
header('Cache-Control: ' . ($isManifest ? 'public, max-age=60' : 'public, max-age=31536000, immutable'));
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . filesize($file));
readfile($file);
