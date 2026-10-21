<?php

namespace Tests\Feature\Certificates;

use Barryvdh\DomPDF\Facade\Pdf;
use Tests\TestCase;

/**
 * Dompdf is locked down: no remote fetches, no PHP/JavaScript, files only from the chroot.
 */
class PdfHardeningTest extends TestCase
{
    public function test_dompdf_options_are_hardened(): void
    {
        $options = Pdf::loadHTML('<p>x</p>')->getOptions();

        $this->assertFalse($options->getIsRemoteEnabled());
        $this->assertFalse($options->getIsPhpEnabled());
        $this->assertFalse($options->getIsJavascriptEnabled());
        $this->assertSame([realpath(resource_path('views/pdf'))], array_map('realpath', $options->getChroot()));
        $this->assertSame(storage_path('fonts'), $options->getFontCache());
        $this->assertSame(['data://', 'file://'], array_keys($options->getAllowedProtocols()));
    }

    public function test_certificate_assets_live_inside_the_chroot(): void
    {
        $chroot = realpath(config('dompdf.options.chroot'));

        foreach ([
            config('portal.certificates.signature_image'),
            resource_path('views/pdf/fonts/DejaVuSans.ttf'),
            resource_path('views/pdf/fonts/DejaVuSans-Bold.ttf'),
        ] as $file) {
            $this->assertFileExists($file);
            $this->assertStringStartsWith($chroot.DIRECTORY_SEPARATOR, realpath($file));
        }
    }
}
