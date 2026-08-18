<?php

namespace Splicewire\Beam\Ux\Tests\Codegen;

use InvalidArgumentException;
use Splicewire\Beam\Ux\Codegen\EnumWriter;
use Splicewire\Beam\Ux\Tests\TestCase;

class EnumWriterTest extends TestCase
{
    public function test_writes_a_real_backed_enum_with_studly_case_names(): void
    {
        $source = EnumWriter::write('App\Data', 'Format', ['tsx', 'mdx', 'css']);

        $this->assertPhpSyntaxIsValid($source);

        $path = $this->requireInto($source);
        $cases = array_map(fn ($c) => $c->name, \App\Data\Format::cases());
        $this->assertEqualsCanonicalizing(['Tsx', 'Mdx', 'Css'], $cases);
        $this->assertSame('tsx', \App\Data\Format::Tsx->value);
        unlink($path);
    }

    public function test_derives_studly_case_names_from_colon_and_dot_separated_values(): void
    {
        $source = EnumWriter::write('App\Data', 'GateKey', ['app:operator', 'os.enter']);

        $this->assertStringContainsString("case AppOperator = 'app:operator';", $source);
        $this->assertStringContainsString("case OsEnter = 'os.enter';", $source);
        $this->assertPhpSyntaxIsValid($source);
    }

    public function test_implements_and_uses_are_real_php_not_decoration(): void
    {
        $source = EnumWriter::write(
            'App\Data',
            'Format2',
            ['a'],
            implements: [FakeMarkerInterface::class],
            uses: [FakeEnumTrait::class],
        );

        $this->assertStringContainsString('implements \\'.FakeMarkerInterface::class, $source);
        $this->assertStringContainsString('use \\'.FakeEnumTrait::class.';', $source);
        $this->assertPhpSyntaxIsValid($source);

        $path = $this->requireInto($source);
        $this->assertInstanceOf(FakeMarkerInterface::class, \App\Data\Format2::A);
        $this->assertSame('trait method', \App\Data\Format2::A->fromTrait());
        unlink($path);
    }

    public function test_refuses_to_write_an_enum_with_zero_cases(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EnumWriter::write('App\Data', 'Empty', []);
    }

    private function assertPhpSyntaxIsValid(string $source): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'enum-writer-lint').'.php';
        file_put_contents($tmp, $source);
        $output = (string) shell_exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1');
        unlink($tmp);

        $this->assertStringContainsString('No syntax errors detected', $output);
    }

    private function requireInto(string $source): string
    {
        $path = tempnam(sys_get_temp_dir(), 'enum-writer-req').'.php';
        file_put_contents($path, $source);
        require_once $path;

        return $path;
    }
}

interface FakeMarkerInterface {}

trait FakeEnumTrait
{
    public function fromTrait(): string
    {
        return 'trait method';
    }
}
