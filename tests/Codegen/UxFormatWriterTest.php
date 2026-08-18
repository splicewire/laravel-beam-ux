<?php

namespace Splicewire\Beam\Ux\Tests\Codegen;

use Splicewire\Beam\Ux\Codec\BodyCodec;
use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Codec\CssBodyCodec;
use Splicewire\Beam\Ux\Codec\MdxBodyCodec;
use Splicewire\Beam\Ux\Codec\TsxBodyCodec;
use Splicewire\Beam\Ux\Codec\UxFormatCase;
use Splicewire\Beam\Ux\Codegen\UxFormatWriter;
use Splicewire\Beam\Ux\Format\BodyStyle;
use Splicewire\Beam\Ux\Tests\TestCase;

class UxFormatWriterTest extends TestCase
{
    public function test_generates_a_real_enum_matching_the_apps_actual_registered_codecs(): void
    {
        // The app's own CodecRegistry singleton — exactly what BeamUxServiceProvider seeds: tsx/mdx/css.
        $writer = new UxFormatWriter($this->app->make(CodecRegistry::class));
        $source = $writer->source('App\Data', 'GeneratedUxFormat');

        $path = tempnam(sys_get_temp_dir(), 'ux-format-writer').'.php';
        file_put_contents($path, $source);
        require_once $path;

        $names = array_map(fn ($c) => $c->name, \App\Data\GeneratedUxFormat::cases());
        $this->assertEqualsCanonicalizing(['Tsx', 'Mdx', 'Css'], $names);
        $this->assertSame('tsx', \App\Data\GeneratedUxFormat::Tsx->value);
        $this->assertInstanceOf(UxFormatCase::class, \App\Data\GeneratedUxFormat::Tsx);

        unlink($path);
    }

    /**
     * THE actual proof the lock-in is gone (not just the generated enum's list changing — that part
     * was always fine, see UxFormatCase's own docblock). A host defines its OWN enum for a format
     * `UxFormat` has never heard of, writes a `BodyCodec` for it, and registers it — all without
     * regenerating anything, touching `UxFormat`, or this package's source at all.
     */
    public function test_a_hosts_own_bespoke_enum_registers_alongside_the_built_in_codecs(): void
    {
        $registry = (new CodecRegistry)
            ->register(new TsxBodyCodec)
            ->register(new MdxBodyCodec)
            ->register(new CssBodyCodec)
            ->register(new class implements BodyCodec
            {
                public function format(): HostDefinedFormat
                {
                    return HostDefinedFormat::Yaml;
                }

                public function extension(): string
                {
                    return 'yaml';
                }

                public function encode(string $raw, ?BodyStyle $style = null): array
                {
                    return ['source' => $raw];
                }

                public function decode(array $body): string
                {
                    return (string) ($body['source'] ?? '');
                }
            });

        // Resolves through the SAME dispatch path as any built-in format — the registry never knew
        // HostDefinedFormat existed until this test registered a codec for it.
        $this->assertTrue($registry->has('yaml'));
        $this->assertSame('yaml', $registry->for(HostDefinedFormat::Yaml)->extension());

        // And it shows up in formats() right alongside tsx/mdx/css — UxFormatWriter would generate it
        // into the NEXT regenerate with zero changes to this package.
        $this->assertEqualsCanonicalizing(['tsx', 'mdx', 'css', 'yaml'], $registry->formats());
    }
}

/** A format `Splicewire\Beam\Ux\Format\UxFormat` has never named — the whole point. */
enum HostDefinedFormat: string implements UxFormatCase
{
    case Yaml = 'yaml';
}
