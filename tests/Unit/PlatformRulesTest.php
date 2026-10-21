<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards for the KEENEY platform rules that live in project files rather than behaviour.
 */
class PlatformRulesTest extends TestCase
{
    private static function root(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path === '' ? '' : DIRECTORY_SEPARATOR.$path);
    }

    /** @return array<string, mixed> */
    private static function composer(): array
    {
        return json_decode(file_get_contents(self::root('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_requires_php_8_4(): void
    {
        $this->assertSame('^8.4', self::composer()['require']['php']);
    }

    public function test_composer_scripts_never_migrate_or_generate_keys(): void
    {
        $commands = collect(self::composer()['scripts'])->flatten()->implode("\n");

        $this->assertStringNotContainsString('migrate', $commands);
        $this->assertStringNotContainsString('key:generate', $commands);
    }

    public function test_debug_tools_are_dev_only(): void
    {
        $packages = array_keys(self::composer()['require']);

        foreach (['laravel/telescope', 'barryvdh/laravel-debugbar', 'fruitcake/laravel-debugbar', 'laravel/boost'] as $package) {
            $this->assertNotContains($package, $packages);
        }
    }

    public function test_proxies_are_not_trusted_wholesale(): void
    {
        $this->assertDoesNotMatchRegularExpression('/trustProxies\s*\(\s*at:\s*[\'"]\*[\'"]/', file_get_contents(self::root('bootstrap/app.php')));
    }

    public function test_up_health_route_is_kept(): void
    {
        $this->assertStringContainsString("health: '/up'", file_get_contents(self::root('bootstrap/app.php')));
    }

    public function test_line_endings_are_normalised_to_lf(): void
    {
        $this->assertStringContainsString('* text=auto eol=lf', file_get_contents(self::root('.gitattributes')));
    }

    /** @return iterable<string, array{string}> */
    public static function codeDirectories(): iterable
    {
        foreach (['app', 'bootstrap', 'database', 'routes', 'resources'] as $directory) {
            yield $directory => [$directory];
        }
    }

    #[DataProvider('codeDirectories')]
    public function test_env_is_read_only_in_config_files(string $directory): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root($directory), RecursiveDirectoryIterator::SKIP_DOTS));
        $offenders = [];

        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && preg_match('/(?<![\w>:$])env\s*\(/', file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders);
    }
}
