<?php

declare(strict_types=1);

/**
 * Prints a deterministic dump of the package's observable behaviour.
 *
 * Run once against the 8.1 repo's src/ and once against this repo's src/, then diff.
 * output means the transform changed syntax and not behaviour — which a 7.4
 * syntax check alone cannot tell you.
 *
 * The generated 7.4 build is also valid 8.x, so both run on this PHP. That is
 * what makes the comparison possible without a 7.4 runtime.
 *
 * Usage: php tools/equivalence-dump.php <path-to-src>
 */

$srcRoot = rtrim($argv[1] ?? __DIR__ . '/../src', '/\\');

// Composer loads this through its "files" autoload; the harness has to do it by
// hand, and must do it before any class is autoloaded.
if (is_file($srcRoot . '/polyfill.php')) {
    require_once $srcRoot . '/polyfill.php';
}

spl_autoload_register(function (string $class) use ($srcRoot) {
    $rel = str_replace(['VeriteIt\\TraceItQr\\', '\\'], ['', '/'], $class);
    $file = $srcRoot . '/' . $rel . '.php';
    if (is_file($file)) { require $file; }
});

use VeriteIt\TraceItQr\BadgePlan;
use VeriteIt\TraceItQr\Code;
use VeriteIt\TraceItQr\Layout;
use VeriteIt\TraceItQr\PostId;

function out(string $label, $value): void
{
    echo $label, ' = ', json_encode($value, JSON_UNESCAPED_SLASHES), "\n";
}

/* ── Layout::plan — exercises match, named args, gap-filled defaults ────── */
echo "== Layout::plan across aspect ratios ==\n";
$layout = new Layout();
$sizes = [
    [1200, 800], [800, 1200], [400, 120], [90, 1200], [1024, 1024],
    [1920, 1080], [200, 200], [64, 64], [3000, 400], [150, 900],
];
foreach ($sizes as [$w, $h]) {
    foreach ([1.33, 1.0, 0.75] as $aspect) {
        $p = $layout->plan($w, $h, $aspect);
        out("plan({$w}x{$h},{$aspect})", [
            'fits' => $p->fits, 'x' => $p->x, 'y' => $p->y,
            'qrW' => $p->qrWidth, 'qrH' => $p->qrHeight,
            'plateW' => $p->plateWidth, 'plateH' => $p->plateHeight,
            'pad' => $p->platePadding, 'radius' => $p->radius,
            'plate' => $p->plate, 'reason' => $p->reason,
            'inside' => $p->isInside($w, $h),
        ]);
    }
}

/* ── every corner — this is what the match() transform decides ──────────── */
echo "\n== corners ==\n";
foreach (['bottom-right', 'bottom-left', 'top-right', 'top-left', 'nonsense'] as $corner) {
    $p = (new Layout())->with(['corner' => $corner])->plan(1200, 800, 1.33);
    out("corner($corner)", ['x' => $p->x, 'y' => $p->y]);
}

/* ── plate on/off, scales ──────────────────────────────────────────────── */
echo "\n== layout overrides ==\n";
foreach ([['plate' => true], ['scale' => 0.5], ['scale' => 0.08], ['minPx' => 400], ['maxPx' => 100]] as $ov) {
    $p = (new Layout())->with($ov)->plan(1200, 800, 1.33);
    out('with(' . json_encode($ov) . ')', [
        'fits' => $p->fits, 'qrW' => $p->qrWidth, 'plateW' => $p->plateWidth,
        'pad' => $p->platePadding, 'reason' => $p->reason,
    ]);
}

/* ── BadgePlan::doesNotFit — the gap-filled named-argument call ─────────── */
echo "\n== BadgePlan::doesNotFit ==\n";
$d = BadgePlan::doesNotFit('too small');
out('doesNotFit', [
    'fits' => $d->fits, 'qrW' => $d->qrWidth, 'qrH' => $d->qrHeight,
    'plateW' => $d->plateWidth, 'plateH' => $d->plateHeight,
    'pad' => $d->platePadding, 'x' => $d->x, 'y' => $d->y,
    'radius' => $d->radius, 'plate' => $d->plate, 'reason' => $d->reason,
]);

/* ── PostId — union types were stripped here ───────────────────────────── */
echo "\n== PostId ==\n";
foreach (['108347979', '108-347979', '108.347979', 'news/x', 'trail-', '-lead', 'UPPER', 108347979,
          str_repeat('a', 48), str_repeat('a', 49), '', 'a', 'a_b-c9'] as $raw) {
    $label = is_int($raw) ? "int($raw)" : "'" . (strlen((string) $raw) > 20 ? substr((string) $raw, 0, 12) . '…' : $raw) . "'";
    try {
        $id = PostId::from($raw);
        out("from($label)", ['ok' => true, 'value' => $id->value(), 'valid' => PostId::isValid($raw)]);
    } catch (Throwable $e) {
        out("from($label)", ['ok' => false, 'err' => get_class($e), 'valid' => PostId::isValid($raw)]);
    }
}

/* ── Code round trip — promotion + readonly were dense here ────────────── */
echo "\n== Code ==\n";
$api = [
    'id' => 'ub1', 'postId' => '108-347979', 'shortUrl' => 'https://test.trace-it.io/ub1',
    'targetUrl' => 'https://example.lk/a', 'publishedAt' => '2026-02-14T00:00:00Z',
    'created' => true, 'qr' => ['pngUrl' => 'https://cdn/x.png', 'png' => ''],
];
$code = Code::fromApi($api);
out('fromApi', [
    'postId' => $code->postId, 'shortUrl' => $code->shortUrl,
    'targetUrl' => $code->targetUrl, 'publishedAt' => $code->publishedAt,
    'pngUrl' => $code->pngUrl, 'created' => $code->created,
]);
$json = $code->jsonSerialize();
out('jsonSerialize', $json);
$back = Code::fromApi($json);
out('round-trip', [
    'postId' => $back->postId, 'pngUrl' => $back->pngUrl,
    'created' => $back->created, 'publishedAt' => $back->publishedAt,
]);
