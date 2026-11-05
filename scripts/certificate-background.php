<?php

/*
 * Draws resources/views/pdf/images/certificate-background(-landscape).png: what dompdf cannot draw itself
 * on the certificate, the first frame's box-shadow and the inner frame's gradient. The HTML preview uses real
 * CSS for both (resources/views/member/certificate.blade.php). Run once by hand when the frames move:
 *
 *     php scripts/certificate-background.php
 *
 * Geometry in Figma pixels of its frame, as in CertificateGeneratorService::layout() and
 * resources/views/pdf/certificate-styles.blade.php.
 */

const SCALE = 2; // px of the image per Figma px

// The first frame, its shadow: 0 12px 40px rgba(16, 40, 70, .14). CSS blur 40 = Gaussian sigma 20.
const FIRST_INSET = 15;
const SHADOW_OFFSET_Y = 12;
const SHADOW_SIGMA = 20;
const SHADOW_ALPHA = 0.14;

// The inner frame: linear-gradient(180deg, #FFF 0%, #FCFBF6 100%).
const INNER_INSET = 18.9;

/** The share of a Gaussian-blurred edge at $x for a span from $from to $to. */
function coverage(float $x, float $from, float $to): float
{
    $edge = fn (float $d) => 0.5 * (1 + erf($d / (SHADOW_SIGMA * M_SQRT2)));

    return $edge($x - $from) - $edge($x - $to);
}

function erf(float $x): float
{
    $t = 1 / (1 + 0.5 * abs($x));
    $y = 1 - $t * exp(-$x * $x - 1.26551223 + $t * (1.00002368 + $t * (0.37409196 + $t * (0.09678418 + $t * (-0.18628806 + $t * (0.27886807 + $t * (-1.13520398 + $t * (1.48851587 + $t * (-0.82215223 + $t * 0.17087277)))))))));

    return $x >= 0 ? $y : -$y;
}

function draw(float $width, float $height, string $file): void
{
    $pixelWidth = (int) round($width * SCALE);
    $pixelHeight = (int) round($height * SCALE);
    $image = imagecreatetruecolor($pixelWidth, $pixelHeight);

    for ($py = 0; $py < $pixelHeight; $py++) {
        $y = ($py + 0.5) / SCALE;
        $shadowY = coverage($y, FIRST_INSET + SHADOW_OFFSET_Y, $height - FIRST_INSET + SHADOW_OFFSET_Y);
        $gradient = ($y - INNER_INSET) / ($height - 2 * INNER_INSET);
        $inner = [(int) round(255 - 3 * $gradient), (int) round(255 - 4 * $gradient), (int) round(255 - 9 * $gradient)];

        for ($px = 0; $px < $pixelWidth; $px++) {
            $x = ($px + 0.5) / SCALE;

            $inFirst = $x >= FIRST_INSET && $x <= $width - FIRST_INSET && $y >= FIRST_INSET && $y <= $height - FIRST_INSET;
            $inInner = $x >= INNER_INSET && $x <= $width - INNER_INSET && $y >= INNER_INSET && $y <= $height - INNER_INSET;

            if ($inInner) {
                [$r, $g, $b] = $inner;
            } elseif ($inFirst) {
                [$r, $g, $b] = [255, 255, 255];
            } else {
                $alpha = SHADOW_ALPHA * coverage($x, FIRST_INSET, $width - FIRST_INSET) * $shadowY;
                [$r, $g, $b] = [(int) round(255 + (16 - 255) * $alpha), (int) round(255 + (40 - 255) * $alpha), (int) round(255 + (70 - 255) * $alpha)];
            }

            imagesetpixel($image, $px, $py, ($r << 16) | ($g << 8) | $b);
        }
    }

    imagepng($image, dirname(__DIR__).'/resources/views/pdf/images/'.$file, 9);

    echo "{$file}: {$pixelWidth}x{$pixelHeight}\n";
}

draw(636.48, 822.64, 'certificate-background.png');
draw(823.68, 635.2, 'certificate-background-landscape.png');
