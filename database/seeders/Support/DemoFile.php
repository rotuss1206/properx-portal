<?php

namespace Database\Seeders\Support;

/**
 * Tiny but valid placeholder files for demo data, built without external tools.
 */
final class DemoFile
{
    /** 1×1 transparent PNG, used when GD is unavailable. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public static function forMimeType(string $mimeType, string $title): string
    {
        return match ($mimeType) {
            'image/png', 'image/jpeg' => self::image($mimeType, $title),
            default => self::pdf($title),
        };
    }

    private static function image(string $mimeType, string $title): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return base64_decode(self::PNG);
        }

        $image = imagecreatetruecolor(600, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagestring($image, 5, 20, 80, substr($title, 0, 70), imagecolorallocate($image, 30, 58, 95));

        ob_start();
        $mimeType === 'image/png' ? imagepng($image) : imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }

    /**
     * Single-page PDF with a title and a "demo" note.
     */
    public static function pdf(string $title): string
    {
        $escape = fn (string $text) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $title = $escape(mb_convert_encoding($title, 'Windows-1252', 'UTF-8'));

        $content = "BT /F1 20 Tf 72 720 Td ({$title}) Tj ET\n"
            ."BT /F1 12 Tf 72 690 Td (ProPerx Alliance - demo placeholder document.) Tj ET\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
