<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * The ProPerX mark as the browser icon on every kind of page: member area, sign-in (Nova), agency
 * enrollment, the public certificate check; and the files it points at.
 */
class FaviconTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private function assertHasIcons(string $html, string $where): void
    {
        foreach (['favicon.ico', 'img/favicon-32.png', 'img/logo-mark.svg', 'img/apple-touch-icon.png', 'site.webmanifest'] as $file) {
            $this->assertStringContainsString($file, $html, "{$file} on {$where}");
        }
    }

    public function test_every_kind_of_page_names_the_icons(): void
    {
        $this->seedRoles();
        config(['portal.member_area.enabled' => true, 'portal.registration.enabled' => true]);

        $this->assertHasIcons($this->get('/nova/login')->assertOk()->getContent(), 'the sign-in page');
        $this->assertHasIcons($this->get('/join/agency')->assertOk()->getContent(), 'the agency enrollment');
        $this->assertHasIcons($this->get('/verify-certificate')->assertOk()->getContent(), 'the certificate check');
        $this->assertHasIcons($this->actingAs($this->member())->get(route('member.dashboard'))->assertOk()->getContent(), 'the member area');
    }

    public function test_the_icon_files_are_the_mark_in_the_right_sizes(): void
    {
        $sizes = ['portal/img/favicon-32.png' => 32, 'portal/img/apple-touch-icon.png' => 180, 'portal/img/icon-192.png' => 192, 'portal/img/icon-512.png' => 512];

        foreach ($sizes as $file => $size) {
            [$width, $height] = getimagesize(public_path($file));
            $this->assertSame([$size, $size], [$width, $height], $file);
        }

        // favicon.ico: 16, 32 and 48 px images.
        $ico = file_get_contents(public_path('favicon.ico'));
        $header = unpack('vreserved/vtype/vcount', substr($ico, 0, 6));
        $this->assertSame([0, 1, 3], array_values($header));
        $this->assertSame([16, 32, 48], array_map(fn (int $i) => ord($ico[6 + 16 * $i]), [0, 1, 2]));

        // The mark only: the arcs and the star of the official logo, without the lettering.
        $svg = file_get_contents(public_path('portal/img/logo-mark.svg'));
        $this->assertSame(7, substr_count($svg, '<path'));
        $this->assertStringContainsString('#C0C0C0', $svg);
        $this->assertStringNotContainsString('#163C6D', $svg);

        $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true);
        $this->assertSame(['192x192', '512x512'], array_column($manifest['icons'], 'sizes'));
    }
}
