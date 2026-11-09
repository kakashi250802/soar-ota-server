<?php
// Publishes a bundle the way `soar publish` does: writes <out>/<app>/<channel>/manifest.json (signed) and
// <out>/<app>/<channel>/bundles/<version>-<sha16>.soarb. The server below serves that folder as it is.
//
//   php publish.php BUNDLE.soarb --key soar-signing.key --app dev.soar.demo --channel production \
//       --version 4 --min-host 29 [--rollout 100] [--mode next_launch|on_resume|immediate] [--kernels a,b] [--out data]

declare(strict_types=1);
require __DIR__ . '/lib/ota.php';

// `--name value` pairs in any order, and one bundle path.
$opts = [];
$bundlePath = null;
for ($i = 1; $i < $argc; $i++) {
    if (str_starts_with($argv[$i], '--')) {
        $opts[substr($argv[$i], 2)] = $argv[++$i] ?? '';
    } else {
        $bundlePath = $argv[$i];
    }
}
foreach (['key', 'app', 'channel', 'version', 'min-host'] as $k) {
    if (!isset($opts[$k]) || $bundlePath === null) {
        fwrite(STDERR, "usage: php publish.php BUNDLE.soarb --key FILE --app ID --channel NAME --version N --min-host N\n");
        exit(2);
    }
}
$bytes = file_get_contents($bundlePath);
$manifest = [
    'app_id' => $opts['app'],
    'channel' => $opts['channel'],
    'version' => (int) $opts['version'],
    // The device refuses a manifest whose min_host_ver is above its own host version. Use at least the bundle
    // format version the Soar CLI reports for the bundle (29 for the demo bundles).
    'min_host_ver' => (int) $opts['min-host'],
    'kernels' => array_values(array_filter(explode(',', $opts['kernels'] ?? ''))),
    'bundle_sha256' => hash('sha256', $bytes),
    'bundle_size' => strlen($bytes),
    'rollout_percent' => (int) ($opts['rollout'] ?? 100),
    'apply_mode' => $opts['mode'] ?? 'next_launch',
];
$manifest = ota_sign($manifest, file_get_contents($opts['key']));

$dir = ($opts['out'] ?? __DIR__ . '/data') . "/{$manifest['app_id']}/{$manifest['channel']}";
@mkdir("$dir/bundles", 0775, true);
// Bundles are never overwritten (the name carries the hash); the manifest is replaced last, so a device never
// sees a manifest whose bundle is not there yet.
file_put_contents("$dir/bundles/" . ota_bundle_file($manifest), $bytes);
file_put_contents("$dir/manifest.json.tmp", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
rename("$dir/manifest.json.tmp", "$dir/manifest.json");
echo "published {$manifest['app_id']} {$manifest['channel']} v{$manifest['version']} ({$manifest['rollout_percent']}%)\n";
