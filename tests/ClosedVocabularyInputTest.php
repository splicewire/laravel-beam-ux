<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Splicewire\Beam\Ux\Components\ComponentKind;
use Splicewire\Beam\Ux\Data\BeamUxEntryInputData;
use Splicewire\Beam\Ux\Data\RedirectInputData;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Redirect\RedirectMatch;
use Splicewire\Beam\Ux\Redirect\RedirectTargetKind;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * attention-surfaces 35: the five string inputs that validated against literal lists now validate against an enum,
 * the whole enum or a subset named once on it. Each site still accepts exactly what the literal list accepted,
 * refuses one excluded enum case (where the site takes a subset) and a value outside the enum, and the schema-form
 * still offers the same options.
 */
class ClosedVocabularyInputTest extends TestCase
{
    /** @return array<string, array{class-string, string, list<string>, list<string>, list<mixed>}> */
    public static function sites(): array
    {
        // [input, field, every previously accepted value, refused values (excluded case first, then outside the enum), schema options]
        return [
            'entry type' => [BeamUxEntryInputData::class, 'type', ['page', 'component', 'theme'], ['layout', 'template', 'widget'], ['page', 'component', 'theme']],
            'entry fileLanguage' => [BeamUxEntryInputData::class, 'fileLanguage', ['tsx', 'mdx'], ['css', 'json'], ['tsx', 'mdx', null]],
            'entry componentKind' => [BeamUxEntryInputData::class, 'componentKind', ['authored', 'source'], ['native'], ['authored', 'source', null]],
            'redirect targetKind' => [RedirectInputData::class, 'targetKind', ['page', 'record', 'path', 'address'], ['url'], ['page', 'record', 'path', 'address']],
            'redirect match' => [RedirectInputData::class, 'match', ['exact', 'prefix'], ['regex'], ['exact', 'prefix']],
        ];
    }

    /**
     * @param  class-string  $input
     * @param  list<string>  $accepted
     */
    #[DataProvider('sites')]
    public function test_every_previously_accepted_value_is_still_accepted(string $input, string $field, array $accepted, array $refused, array $options): void
    {
        foreach ($accepted as $value) {
            $this->assertTrue($this->passes($input, $field, $value), "{$field} must still accept [{$value}].");
        }
    }

    /**
     * @param  class-string  $input
     * @param  list<string>  $refused
     */
    #[DataProvider('sites')]
    public function test_an_excluded_case_and_a_value_outside_the_enum_are_refused(string $input, string $field, array $accepted, array $refused, array $options): void
    {
        foreach ($refused as $value) {
            $this->assertFalse($this->passes($input, $field, $value), "{$field} must refuse [{$value}].");
        }
    }

    /**
     * @param  class-string  $input
     * @param  list<mixed>  $options
     */
    #[DataProvider('sites')]
    public function test_the_schema_form_still_offers_the_same_options(string $input, string $field, array $accepted, array $refused, array $options): void
    {
        $schema = (new JsonSchemaGenerator)->generate(new \ReflectionClass($input));

        $this->assertSame($options, $schema['properties'][$field]['enum']);
    }

    public function test_each_set_is_declared_on_its_enum(): void
    {
        $this->assertSame([UxType::Page, UxType::Component, UxType::Theme], UxType::CREATABLE);
        $this->assertSame([UxFormat::Tsx, UxFormat::Mdx], UxFormat::FILE_LANGUAGES);
        // componentKind's attribute lists ComponentKind's cases (an attribute cannot call cases()); this pins it whole.
        $this->assertSame(array_column(ComponentKind::cases(), 'value'), ['authored', 'source']);
        $this->assertSame(['page', 'record', 'path', 'address'], array_column(RedirectTargetKind::cases(), 'value'));
        $this->assertSame(['exact', 'prefix'], array_column(RedirectMatch::cases(), 'value'));
    }

    /** @param  class-string  $input */
    private function passes(string $input, string $field, string $value): bool
    {
        $payload = [$field => $value];
        $rules = $input::getValidationRules($payload);

        return Validator::make($payload, [$field => $rules[$field]])->passes();
    }
}
