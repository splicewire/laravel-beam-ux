<?php

namespace Splicewire\Beam\Ux\Tests\Intake;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Ux\Intake\IntakeForms;
use Splicewire\Beam\Ux\JsonDoc\CanvasBodyGuard;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Tests\TestCase;

/** EP-07d: a packaged form Code Component whose submissions go to the submissions ledger through the public intake door. */
class IntakeFormTest extends TestCase
{
    private const CONTACT = ['type' => 'object', 'properties' => ['email' => ['type' => 'string', 'format' => 'email']], 'required' => ['email']];

    protected function setUp(): void
    {
        parent::setUp();
        // The registry resolves `contact` and `private`; only `contact` is on the public allow-list.
        $this->app->instance(SchemaTargetResolver::class, new class(self::CONTACT) implements SchemaTargetResolver
        {
            public function __construct(private array $schema) {}

            public function targetFor(string $recordType, ?int $version = null): array
            {
                return in_array($recordType, ['contact', 'private'], true) ? $this->schema : [];
            }
        });
        $this->app->forgetInstance(IntakeForms::class);
    }

    private function openDoor(): void
    {
        config(['beam.core.intake.enabled' => true, 'beam.core.intake.public_schemas' => ['contact'], 'beam.core.intake.slugs' => ['newsletter' => 'contact']]);
    }

    /** @return list<array<string, mixed>> */
    private function form(string $key, string $id = '01a1f000-0000-7000-8000-000000000001'): array
    {
        return [['kind' => 'block', 'name' => 'IntakeForm', 'isComponent' => true, 'dynamic' => false, 'id' => $id,
            'props' => [['name' => 'captureKey', 'kind' => 'string', 'value' => $key]], 'children' => []]];
    }

    private function profileNames(BeamUxEntry $entry): array
    {
        return array_column(app(ManifestProfileResolver::class)->resolve($entry)['nodes'], 'name');
    }

    public function test_the_form_is_offered_only_where_the_public_intake_door_is_on(): void
    {
        $entry = new BeamUxEntry(['type' => 'page']);
        $this->assertNotContains('IntakeForm', $this->profileNames($entry));

        $this->openDoor();
        $node = collect(app(ManifestProfileResolver::class)->resolve($entry)['nodes'])->firstWhere('name', 'IntakeForm');

        $this->assertSame(['captureKey'], $node['attrsSchema']['required'] ?? null, 'sealed, with its declared attrs');
        $this->assertFalse($node['attrsSchema']['additionalProperties']);
        $this->assertSame([], $node['admitsChildCategories']);
    }

    public function test_a_form_saves_only_when_it_posts_to_a_public_intake_schema(): void
    {
        $this->openDoor();
        $guard = app(CanvasBodyGuard::class);
        $entry = new BeamUxEntry(['type' => 'page', 'format' => 'tsx']);

        $guard->assertAdmitted($entry, $this->form('contact'));
        $guard->assertAdmitted($entry, $this->form('newsletter'));
        foreach (['private' => 'not on the allow-list', 'missing' => 'unknown to the registry'] as $key => $why) {
            try {
                $guard->assertAdmitted($entry, $this->form($key));
                $this->fail("A form posting to [{$key}] ({$why}) was saved");
            } catch (ValidationException $failure) {
                $this->assertStringContainsString("posts to [{$key}]", implode(' ', $failure->errors()['body']));
            }
        }
    }

    public function test_the_door_off_refuses_a_form_and_its_schema_is_resolved_only_for_public_keys(): void
    {
        $forms = app(IntakeForms::class);
        $this->assertNull($forms->schemaFor('contact'), 'the door is off');

        $this->openDoor();

        $this->assertSame(self::CONTACT, $forms->schemaFor('contact'));
        $this->assertSame(self::CONTACT, $forms->schemaFor('newsletter'), 'through the slug map');
        $this->assertNull($forms->schemaFor('private'));
        $this->assertSame(['contact' => self::CONTACT], $forms->schemasFor([...$this->form('contact'), ...$this->form('private', '01a1f000-0000-7000-8000-000000000002')]));
    }

    public function test_publish_rechecks_every_form_so_a_restore_or_a_shrunk_allow_list_never_goes_live(): void
    {
        // The save checks changed forms only; Publish checks them all.
        $this->openDoor();
        $guard = app(CanvasBodyGuard::class);
        $entry = new BeamUxEntry(['type' => 'page', 'format' => 'tsx']);
        $body = $this->form('contact');
        $guard->assertPublishable($entry, $body, $body);

        config(['beam.core.intake.public_schemas' => []]);
        $this->expectException(ValidationException::class);
        $guard->assertPublishable($entry, $body, $body);
    }

    public function test_a_host_route_gets_the_page_props_the_packaged_renderer_gives(): void
    {
        // A host that renders an entry page from its own route (a starter's `/`) must hand its forms the same props as
        // the packaged renderer, or each one reads `This form is not available.` (starter receipt, 2026-10-25).
        $this->openDoor();
        config(['beam.core.intake.honeypot.field' => 'nickname']);
        $published = [...$this->form('contact'), ...$this->form('private', '01a1f000-0000-7000-8000-000000000002')];
        $this->app->instance(\Splicewire\Beam\Ux\Bindings\PublishedBindingDocument::class, new class($published) extends \Splicewire\Beam\Ux\Bindings\PublishedBindingDocument
        {
            public function __construct(private array $body) {}

            public function read(BeamUxEntry $entry): array
            {
                return $entry->slug === 'home' ? $this->body : [];
            }
        });

        $this->assertSame(['intakeForms' => ['contact' => self::CONTACT], 'intakeHoneypotField' => 'nickname'],
            app(IntakeForms::class)->pagePropsFor(new BeamUxEntry(['slug' => 'home', 'type' => 'page', 'format' => 'tsx'])));
        $this->assertSame([], app(IntakeForms::class)->pagePropsFor(new BeamUxEntry(['slug' => 'about', 'type' => 'page', 'format' => 'tsx'])),
            'a page without a form carries nothing');
    }

    public function test_the_editor_envelope_carries_the_schema_each_form_renders(): void
    {
        $this->openDoor();
        $entry = new BeamUxEntry(['slug' => 'contact-us', 'type' => 'page', 'format' => 'tsx']);

        $envelope = app(\Splicewire\Beam\Ux\Particle\EntryBodyEnvelope::class)->of($entry, [...$this->form('contact'), ...$this->form('private', '01a1f000-0000-7000-8000-000000000002')]);

        $this->assertSame(['contact' => self::CONTACT], $envelope->intakeForms);
    }

    public function test_a_hosted_page_places_the_form_too_since_a_sealed_component_is_not_code(): void
    {
        $this->openDoor();
        $this->app->instance(TenancyContext::class, new class implements TenancyContext
        {
            public function isMultiTenant(): bool
            {
                return true;
            }

            public function isActive(): bool
            {
                return true;
            }

            public function currentTenant(): ?object
            {
                return null;
            }

            public function currentTenantId(): int|string|null
            {
                return 'tenant-a';
            }

            public function centralConnection(): ?string
            {
                return 'central_db';
            }
        });
        $entry = new BeamUxEntry(['type' => 'page', 'format' => 'tsx']);
        $profile = app(ManifestProfileResolver::class)->resolve($entry);

        $this->assertFalse($profile['x-admits-code']);
        $this->assertContains('IntakeForm', array_column($profile['nodes'], 'name'));
        app(CanvasBodyGuard::class)->assertAdmitted($entry, $this->form('contact'));
        $this->addToAssertionCount(1);
    }
}
