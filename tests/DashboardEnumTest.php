<?php

namespace Splicewire\Beam\Ux\Tests;

use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Splicewire\Beam\Dashboard\DashboardParticipation;
use Splicewire\Beam\Ux\Data\DashboardCardRowData;
use Splicewire\Beam\Ux\Data\DashboardWelcomeData;
use Splicewire\Beam\Ux\Enums\DashboardCardContext;
use Splicewire\Beam\Ux\Enums\DashboardWelcomeState;

final class DashboardEnumTest extends TestCase
{
    public function test_dashboard_context_and_welcome_vocabularies_are_closed_and_keep_legacy_constants(): void
    {
        self::assertSame(['summary', 'overview', 'nav', 'welcome'], array_map(
            static fn (DashboardCardContext $context): string => $context->value,
            DashboardCardContext::cases(),
        ));
        self::assertSame(['first-run', 'empty'], array_map(
            static fn (DashboardWelcomeState $state): string => $state->value,
            DashboardWelcomeState::cases(),
        ));
        self::assertSame(['Summary', 'Overview', 'Navigation', 'Welcome'], array_map(
            static fn (DashboardCardContext $context): string => $context->label(),
            DashboardCardContext::cases(),
        ));
        self::assertSame(['First run', 'Empty'], array_map(
            static fn (DashboardWelcomeState $state): string => $state->label(),
            DashboardWelcomeState::cases(),
        ));

        self::assertSame(DashboardParticipation::CONTEXT_SUMMARY, DashboardCardContext::Summary->value);
        self::assertSame(DashboardParticipation::CONTEXT_OVERVIEW, DashboardCardContext::Overview->value);
        self::assertSame(DashboardCardContext::Summary->value, DashboardCardRowData::CONTEXT_SUMMARY);
        self::assertSame(DashboardCardContext::Overview->value, DashboardCardRowData::CONTEXT_OVERVIEW);
        self::assertSame(DashboardCardContext::Nav->value, DashboardCardRowData::CONTEXT_NAV);
        self::assertSame(DashboardCardContext::Welcome->value, DashboardCardRowData::CONTEXT_WELCOME);
        self::assertSame(DashboardWelcomeState::FirstRun->value, DashboardWelcomeData::STATE_FIRST_RUN);
        self::assertSame(DashboardWelcomeState::Empty->value, DashboardWelcomeData::STATE_EMPTY);
    }

    public function test_backed_enum_properties_serialize_to_the_existing_wire_strings(): void
    {
        $row = new DashboardCardRowData(
            id: 'summary:widgets',
            context: DashboardCardContext::Summary,
            label: 'Widgets',
            icon: null,
            href: '/operator/widgets',
        );
        $welcome = new DashboardWelcomeData(
            state: DashboardWelcomeState::FirstRun,
            heading: 'Welcome',
            body: 'Start here.',
        );

        self::assertSame('summary', $row->toArray()['context']);
        self::assertSame('first-run', $welcome->toArray()['state']);
        // Captured from the unmodified 74f5938 tree with test-full --filter DashboardSerializationBaseProbeTest --dirty-ok.
        self::assertSame(
            '{"id":"summary:widgets","context":"summary","label":"Widgets","icon":null,"href":"/operator/widgets","navOrder":null,"resource":null,"summary":null,"description":null,"welcome":null}',
            $row->toJson(JSON_UNESCAPED_SLASHES),
        );
        self::assertSame(
            '{"state":"first-run","heading":"Welcome","body":"Start here.","actions":[],"hint":null}',
            $welcome->toJson(JSON_UNESCAPED_SLASHES),
        );

        $fromWire = DashboardCardRowData::from([
            'id' => 'nav:settings',
            'context' => 'nav',
            'label' => 'Settings',
            'icon' => null,
            'href' => '/operator/settings',
        ]);
        self::assertSame(DashboardCardContext::Nav, $fromWire->context);
        self::assertTrue($fromWire->isTile());

        $welcomeFromWire = DashboardWelcomeData::from([
            'state' => 'empty',
            'heading' => 'Nothing here yet',
            'body' => 'Check back later.',
        ]);
        self::assertSame(DashboardWelcomeState::Empty, $welcomeFromWire->state);
    }

    public function test_json_schemas_reference_the_named_closed_vocabularies(): void
    {
        $generator = new JsonSchemaGenerator;
        $card = $generator->generate(new ReflectionClass(DashboardCardRowData::class));

        self::assertSame(['$ref' => '#/$defs/DashboardCardContext'], $card['properties']['context']);
        self::assertSame([
            'type' => 'string',
            'title' => 'DashboardCardContext',
            'enum' => ['summary', 'overview', 'nav', 'welcome'],
            'enumNames' => ['Summary', 'Overview', 'Navigation', 'Welcome'],
        ], $card['$defs']['DashboardCardContext']);

        $welcome = $generator->generate(new ReflectionClass(DashboardWelcomeData::class));

        self::assertSame(['$ref' => '#/$defs/DashboardWelcomeState'], $welcome['properties']['state']);
        self::assertSame([
            'type' => 'string',
            'title' => 'DashboardWelcomeState',
            'enum' => ['first-run', 'empty'],
            'enumNames' => ['First run', 'Empty'],
        ], $welcome['$defs']['DashboardWelcomeState']);
    }
}
