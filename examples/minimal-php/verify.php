<?php
// Checks manifests against a public key: `php verify.php soar-signing.pub manifest.json [...]`.
// Run it on the fixtures made by the real Soar CLI to see that this PHP code and the engine agree byte for byte.

declare(strict_types=1);
require __DIR__ . '/lib/ota.php';

$public = file_get_contents($argv[1] ?? '') ?: exit("usage: php verify.php soar-signing.pub manifest.json...\n");
$failed = 0;
foreach (array_slice($argv, 2) as $file) {
    $m = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $ok = ota_verify($m, $public);
    $failed += $ok ? 0 : 1;
    printf("%-4s %s (v%d, %d%%)\n", $ok ? 'ok' : 'BAD', $file, $m['version'], $m['rollout_percent']);
}
exit($failed === 0 ? 0 : 1);
