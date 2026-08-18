<?php

namespace Splicewire\Beam\Ux\Tests\Codegen;

use Rushing\Codegen\GeneratorRegistry;
use Rushing\Codegen\Laravel\Contracts\ModelSource;
use Rushing\Codegen\Laravel\Generators\DataClassGenerator;
use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Codec\CssBodyCodec;
use Splicewire\Beam\Ux\Codec\MdxBodyCodec;
use Splicewire\Beam\Ux\Codec\TsxBodyCodec;
use Splicewire\Beam\Ux\Codegen\CodecRegistryModelSource;

/**
 * The real wiring the pattern (proven generically with a fake registry in `rushing/laravel-codegen`'s
 * own test suite) was scoped for: `CodecRegistryModelSource` reflecting the ACTUAL
 * {@see CodecRegistry}, with the REAL `TsxBodyCodec`/`MdxBodyCodec`/`CssBodyCodec` — no fakes.
 *
 * Uses two independently-constructed `CodecRegistry` instances (not the app-bound singleton) to prove
 * live reflection without mutating shared container state mid-test: same
 * `CodecRegistryModelSource` class, different registry contents in, different generated enum out.
 */
class CodecRegistryModelSourceTest extends CodegenIntegrationTestCase
{
    private string $outDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outDir = sys_get_temp_dir().'/beam-ux-codegen-test-'.uniqid();
        config(['codegen.out_dir' => $this->outDir]);

        $this->app->make(GeneratorRegistry::class)->register(new DataClassGenerator);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outDir)) {
            array_map('unlink', glob($this->outDir.'/*') ?: []);
            @rmdir($this->outDir);
        }

        parent::tearDown();
    }

    public function test_generates_a_real_enum_matching_the_apps_actual_registered_codecs(): void
    {
        // The app's own CodecRegistry singleton — exactly what BeamUxServiceProvider seeds, tsx/mdx/css.
        $this->app->instance(
            ModelSource::class,
            new CodecRegistryModelSource($this->app->make(CodecRegistry::class)),
        );

        $this->artisan('codegen:generate', ['--stack' => ['php-data']])->assertSuccessful();

        $path = $this->outDir.'/UxFormat.php';
        $this->assertFileExists($path);

        require_once $path;
        $this->assertTrue(enum_exists('App\Data\UxFormat'));

        $names = array_map(fn ($c) => $c->name, \App\Data\UxFormat::cases());
        $this->assertEqualsCanonicalizing(['Tsx', 'Mdx', 'Css'], $names);
        $this->assertSame('tsx', \App\Data\UxFormat::Tsx->value);
    }

    public function test_reflects_a_different_registrys_contents_without_any_code_change(): void
    {
        $narrow = (new CodecRegistry)->register(new TsxBodyCodec);
        $this->app->instance(ModelSource::class, new CodecRegistryModelSource($narrow));

        $this->artisan('codegen:generate', ['--stack' => ['php-data']])->assertSuccessful();
        $narrowOutput = file_get_contents($this->outDir.'/UxFormat.php');
        $this->assertStringContainsString("case Tsx = 'tsx';", $narrowOutput);
        $this->assertStringNotContainsString('case Mdx', $narrowOutput);
        $this->assertStringNotContainsString('case Css', $narrowOutput);

        $wide = (new CodecRegistry)
            ->register(new TsxBodyCodec)
            ->register(new MdxBodyCodec)
            ->register(new CssBodyCodec);
        $this->app->instance(ModelSource::class, new CodecRegistryModelSource($wide));

        $this->artisan('codegen:generate', ['--stack' => ['php-data']])->assertSuccessful();
        $wideOutput = file_get_contents($this->outDir.'/UxFormat.php');

        $this->assertStringContainsString("case Tsx = 'tsx';", $wideOutput);
        $this->assertStringContainsString("case Mdx = 'mdx';", $wideOutput);
        $this->assertStringContainsString("case Css = 'css';", $wideOutput);
        $this->assertNotSame($narrowOutput, $wideOutput);
    }
}
