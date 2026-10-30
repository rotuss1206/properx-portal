<?php

namespace App\Support;

/**
 * URL of a file in public/portal with a content hash, so browsers pick up changes at once
 * (sign-in pages, Nova sidebar, member area).
 */
final class PortalAsset
{
    public static function url(string $path): string
    {
        $file = public_path('portal/'.$path);

        return asset('portal/'.$path).'?v='.substr((string) md5_file($file), 0, 12);
    }
}
