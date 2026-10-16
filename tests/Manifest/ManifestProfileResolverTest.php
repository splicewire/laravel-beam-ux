<?php

namespace Splicewire\Beam\Ux\Tests\Manifest;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Manifest\EntryResidency;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Manifest\ProfileLegality;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * ADR-0228 d7 (EP-07a, part b, first increment) — the resolver ships the HTML base vocabulary and sets
 * `x-admits-code` from the entry's residency (ux-0227 d1). Composing the host's `#[NodeType]` components
 * is the next increment. End-to-end: a central entry's profile accepts HTML; a tenant entry's refuses code.
 */
class ManifestProfileResolverTest extends TestCase
{
    private function resolver(?string $central): ManifestProfileResolver
    {
        $tenancy = new class($central) implements TenancyContext
        {
            public function __construct(private ?string $central) {}

            public function isMultiTenant(): bool { return $this->central !== null; }

            public function isActive(): bool { return false; }

            public function currentTenant(): ?object { return null; }

            public function currentTenantId(): int|string|null { return null; }

            public function centralConnection(): ?string { return $this->central; }
        };

        return new ManifestProfileResolver(new EntryResidency($tenancy));
    }

    private function entry(?string $connection): BeamUxEntry
    {
        $entry = new BeamUxEntry;
        $entry->setConnection($connection);

        return $entry;
    }

    public function test_a_central_entry_resolves_a_code_admitting_html_profile(): void
    {
        $profile = $this->resolver('central_db')->resolve($this->entry('central'));

        $this->assertTrue($profile['x-admits-code']);
        $this->assertSame(['html'], $profile['doc']['admitsChildCategories']);
        $names = array_column($profile['nodes'], 'name');
        foreach (['div', 'section', 'p', 'img'] as $tag) {
            $this->assertContains($tag, $names);
        }
    }

    public function test_a_tenant_entry_resolves_a_code_refusing_profile(): void
    {
        $profile = $this->resolver('central_db')->resolve($this->entry('tenant_acme'));

        $this->assertFalse($profile['x-admits-code']);
    }

    public function test_end_to_end_central_accepts_html_and_tenant_refuses_code(): void
    {
        $central = $this->resolver('central_db')->resolve($this->entry('central'));
        $body = [['kind' => 'block', 'name' => 'div', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'hi']], 'dynamic' => false, 'id' => 'd1']];
        (new ProfileLegality)->assert($body, $central);
        $this->addToAssertionCount(1); // the central profile accepted a legal HTML body

        $tenant = $this->resolver('central_db')->resolve($this->entry('tenant_acme'));
        $this->expectException(ValidationException::class);
        (new ProfileLegality)->assert([['kind' => 'opaque', 'reason' => 'unsupported', 'source' => '<script>steal()</script>']], $tenant);
    }
}
