<?php

declare(strict_types=1);

/**
 * Exercises the compositing path and prints what it produced.
 *
 * Run against the 8.1 repo's src/ and against this repo's src/. This is the half of
 * the package a syntax check cannot reach: it is where GD actually draws.
 *
 * No API key and no internet. A synthetic photo is served from a PHP built-in
 * server on 127.0.0.1, which is what the host allowlist is pointed at, so this
 * exercises fetch → decode → plan → draw → encode exactly as production does.
 *
 * NOTE ON WHAT IS COMPARED. GD's JPEG encoder differs between PHP builds, so the
 * output BYTES are not expected to match across versions and are not compared.
 * What must match is everything the package decides: dimensions, mime, whether a
 * badge was drawn, and where it was placed.
 *
 * Usage: php tools/gd-dump.php <path-to-src> <port>
 */

$srcRoot = rtrim($argv[1] ?? __DIR__ . '/../src', '/\\');
$port    = (int) ($argv[2] ?? 8099);

if (is_file($srcRoot . '/polyfill.php')) {
    require_once $srcRoot . '/polyfill.php';
}
spl_autoload_register(function (string $class) use ($srcRoot) {
    $rel = str_replace(['VeriteIt\\TraceItQr\\', '\\'], ['', '/'], $class);
    $file = $srcRoot . '/' . $rel . '.php';
    if (is_file($file)) { require $file; }
});

use VeriteIt\TraceItQr\Compositor;
use VeriteIt\TraceItQr\Layout;

echo 'gd: ', (extension_loaded('gd') ? 'loaded' : 'MISSING'), "\n";

/* ── synthetic assets, deterministic so both runs get identical input ───── */
$dir = sys_get_temp_dir() . '/traceit-gd-' . $port;
@mkdir($dir, 0777, true);

function photo(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $c = imagecolorallocate($im, (int) (30 + $y * 150 / max(1, $h)), 80, 160);
        imageline($im, 0, $y, $w, $y, $c);
    }
    ob_start(); imagejpeg($im, null, 90); $b = (string) ob_get_clean();
    imagedestroy($im);
    return $b;
}

/* The branded Trace-It PNG is 1024x1362 — taller than wide because of the label
 * banner. Anything assuming square distorts it, so the stand-in matches. */
function qrPng(): string
{
    $im = imagecreatetruecolor(1024, 1362);
    $white = imagecolorallocate($im, 255, 255, 255);
    $black = imagecolorallocate($im, 0, 0, 0);
    imagefill($im, 0, 0, $white);
    for ($x = 0; $x < 1024; $x += 64) {
        for ($y = 0; $y < 1024; $y += 64) {
            if ((($x / 64) + ($y / 64)) % 2 === 0) {
                imagefilledrectangle($im, $x, $y, $x + 63, $y + 63, $black);
            }
        }
    }
    ob_start(); imagepng($im); $b = (string) ob_get_clean();
    imagedestroy($im);
    return $b;
}

$cases = [[1200, 800], [800, 1200], [400, 120], [1024, 1024], [64, 64]];
foreach ($cases as [$w, $h]) {
    file_put_contents("$dir/p-{$w}x{$h}.jpg", photo($w, $h));
}
file_put_contents("$dir/p.png", qrPng());

/* ── serve them locally ────────────────────────────────────────────────── */
$null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
$srv = proc_open(
    PHP_BINARY . " -S 127.0.0.1:$port -t " . escapeshellarg($dir),
    [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
    $pipes
);
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
    if ($fp) { fclose($fp); break; }
    usleep(100000);
}

$qr = file_get_contents("$dir/p.png");

/* The allowlist matches host AND port, so a non-default port has to be named.
 * That is correct behaviour — an images host on :8080 should not be authorised
 * by allowlisting :443 — but it is easy to trip over when testing locally. */
$compositor = new Compositor(["127.0.0.1:$port"], 95);

echo "\n== compose ==\n";
foreach ($cases as [$w, $h]) {
    $url = "http://127.0.0.1:$port/p-{$w}x{$h}.jpg";
    try {
        $f = $compositor->compose($url, $qr);
        echo "compose({$w}x{$h}) = ", json_encode([
            'w' => $f->width, 'h' => $f->height, 'mime' => $f->mime,
            'badge' => $f->hasBadge, 'reason' => $f->reason,
            'ext' => $f->extension(),
        ], JSON_UNESCAPED_SLASHES), "\n";
    } catch (Throwable $e) {
        echo "compose({$w}x{$h}) = ", json_encode(['err' => get_class($e)]), "\n";
    }
}

echo "\n== corners ==\n";
foreach (['bottom-right', 'bottom-left', 'top-right', 'top-left'] as $corner) {
    $f = $compositor->compose(
        "http://127.0.0.1:$port/p-1200x800.jpg",
        $qr,
        (new Layout())->with(['corner' => $corner])
    );
    echo "corner($corner) = ", json_encode(['w' => $f->width, 'h' => $f->height, 'badge' => $f->hasBadge]), "\n";
}

echo "\n== host allowlist ==\n";
foreach (['http://169.254.169.254/latest/meta-data/', 'file:///etc/passwd', "http://127.0.0.1:$port/p.png"] as $u) {
    echo 'allowed(', parse_url($u, PHP_URL_HOST) ?: 'none', ') = ',
         json_encode($compositor->imageHostAllowed($u)), "\n";
}

if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
