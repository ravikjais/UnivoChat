<?php
/**
 * Run this once via CLI or browser to generate PNG icons:
 *   php generate_icons.php
 * Requires GD extension (included in XAMPP).
 */

$sizes = [192, 512, 180]; // 180 = apple-touch-icon

foreach ($sizes as $size) {
    $img = imagecreatetruecolor($size, $size);
    imageantialias($img, true);

    // Background (rounded look via full rect – browsers handle masking)
    $bg = imagecolorallocate($img, 36, 80, 220);  // #2450DC
    imagefill($img, 0, 0, $bg);

    // White chat bubble (simplified)
    $white = imagecolorallocate($img, 255, 255, 255);
    $margin = (int)($size * 0.22);
    $bw = $size - $margin * 2;
    $bh = (int)($bw * 0.75);
    $by = (int)($size * 0.22);
    imagefilledrectangle($img, $margin, $by, $margin + $bw, $by + $bh, $white);

    // Tail of bubble
    $tailX = (int)($size * 0.44);
    $tailY = $by + $bh;
    imagefilledpolygon($img, [$tailX, $tailY, $tailX + (int)($size * 0.12), $tailY, $tailX + (int)($size * 0.06), $tailY + (int)($size * 0.12)], $white);

    // Dots
    $dot  = imagecolorallocate($img, 36, 80, 220);
    $dr   = (int)($size * 0.04);
    $dy   = (int)($size * 0.465);
    $positions = [0.32, 0.5, 0.68];
    foreach ($positions as $px) {
        $cx = (int)($size * $px);
        imagefilledellipse($img, $cx, $dy, $dr * 2, $dr * 2, $dot);
    }

    $fname = __DIR__ . "/icon-{$size}.png";
    imagepng($img, $fname);
    echo "Created: $fname\n";
}
echo "Done.\n";
