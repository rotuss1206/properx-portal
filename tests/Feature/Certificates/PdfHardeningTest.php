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
        $this->assertSame(storage_path('framework/cache/dompdf-fonts'), $options->getFontCache());
        $this->assertSame(['data://', 'file://'], array_keys($options->getAllowedProtocols()));
    }

    public function test_certificate_assets_live_inside_the_chroot(): void
    {
        $chroot = realpath(config('dompdf.options.chroot'));

        foreach ([
            resource_path('views/pdf/images/logo-color.png'),
            resource_path('views/pdf/images/seal.svg'),
            resource_path('views/pdf/images/link.svg'),
            resource_path('views/pdf/images/diamond.svg'),
            resource_path('views/pdf/fonts/Lora-Italic.ttf'),
            resource_path('views/pdf/fonts/OpenSans-Regular.ttf'),
            resource_path('views/pdf/fonts/Poppins-SemiBold.ttf'),
        ] as $file) {
            $this->assertFileExists($file);
            $this->assertStringStartsWith($chroot.DIRECTORY_SEPARATOR, realpath($file));
        }
    }
}
