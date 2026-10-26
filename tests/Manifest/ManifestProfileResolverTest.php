<?php

namespace Splicewire\Beam\Ux\Tests\Manifest;

use Illuminate\Validation\ValidationException;
use Schemastud\Blockdoc\Contracts\ManifestNodeSource;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Manifest\EntryResidency;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Manifest\ProfileLegality;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Tests\Manifest\Fixtures\CalloutBlock;
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

            public function isMultiTenant(): bool
            {
                return $this->central !== null;
            }

            public function isActive(): bool
            {
                return false;
            }

            public function currentTenant(): ?object
            {
                return null;
            }

            public function currentTenantId(): int|string|null
            {
                return null;
            }

            public function centralConnection(): ?string
            {
                return $this->central;
            }
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

    public function test_an_mdx_entry_resolves_the_beam_mdx_kit_only_on_a_host_that_holds_mdx_as_blocks(): void
    {
        // ADR-0233 d7 — the kit is declared on the server (splicewire/laravel-beam-mdx), so on a host whose MDX
        // pages hold blocks an MDX page's kit tags are checked against the profile like any other block. A host
        // that keeps MDX as source (the default) serves the manifest it always has.
        $mdx = $this->entry('central');
        $mdx->format = 'mdx';
        $tsx = $this->entry('central');
        $tsx->format = 'tsx';
        $kit = array_column((new \Splicewire\Beam\Mdx\Kit\MdxKitNodeSource)->manifest('content')['nodes'], 'name');
        $this->assertCount(12, $kit);

        $off = array_column($this->resolver('central_db')->resolve($mdx)['nodes'], 'name');
        foreach ($kit as $name) {
            $this->assertNotContains($name, $off, 'the default-off manifest is unchanged');
        }

        config(['beam.ux.mdx.holds_blocks' => true]);
        $mdxNames = array_column($this->resolver('central_db')->resolve($mdx)['nodes'], 'name');
        $tsxNames = array_column($this->resolver('central_db')->resolve($tsx)['nodes'], 'name');

        foreach ($kit as $name) {
            $this->assertContains($name, $mdxNames);
            $this->assertNotContains($name, $tsxNames);
        }
        // EP-42 S1: and the Code Components packages' shipped MDX pages place, on the MDX page alone.
        $shipped = array_column((new \Splicewire\Beam\Ux\Manifest\SiteNodeSource)->manifest('content')['nodes'], 'name');
        foreach ($shipped as $name) {
            $this->assertContains($name, $mdxNames);
            $this->assertNotContains($name, $tsxNames);
        }
        $this->assertSame(array_values(array_diff($mdxNames, $kit, $shipped)), $off, 'the gate adds the kit and the shipped pages\' tags, and nothing else');
        $this->assertNotContains('Content', $mdxNames, 'a fragment is an Authored Component placed by its own tag (d8)');
        $this->assertSame(count($mdxNames), count(array_unique($mdxNames)), 'the kit never redeclares a base name');
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

    public function test_packaged_demo_islands_are_sealed_with_empty_strict_props(): void
    {
        $profile = $this->resolver(null)->resolve($this->entry(null));
        $nodes = array_column($profile['nodes'], null, 'name');
        foreach (['DemoHero', 'DemoFeatureRow'] as $name) {
            $this->assertArrayHasKey($name, $nodes);
            $this->assertSame([], $nodes[$name]['admitsChildCategories']);
            $this->assertFalse($nodes[$name]['admitsText']);
            $this->assertSame(['type' => 'object', 'additionalProperties' => false], $nodes[$name]['attrsSchema']);
            $body = [['kind' => 'block', 'name' => $name, 'props' => [], 'children' => []]];
            (new ProfileLegality)->assert($body, $profile);
            $body[0]['props'] = [['name' => 'title', 'kind' => 'string', 'value' => 'not a prop']];
            try {
                (new ProfileLegality)->assert($body, $profile);
                $this->fail('sealed island accepted a prop');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('body', $e->errors());
            }
        }
    }

    public function test_a_bound_node_source_composes_host_components_onto_the_html_base(): void
    {
        // The host declares its placeable #[NodeType] components through a bound ManifestNodeSource.
        $this->app->instance(ManifestNodeSource::class, new class implements ManifestNodeSource
        {
            public function profiles(): array
            {
                return ['content'];
            }

            public function nodeClassesFor(string $profile): array
            {
                return [CalloutBlock::class];
            }

            public function docAdmitsFor(string $profile): ?array
            {
                return ['html'];
            }

            public function marksFor(string $profile): array
            {
                return [];
            }
        });

        $profile = $this->resolver('central_db')->resolve($this->entry('central'));
        $names = array_column($profile['nodes'], 'name');

        $this->assertContains('DemoHero', $names);
        $this->assertContains('Callout', $names, 'the host #[NodeType] component is composed in');
        $this->assertContains('div', $names, 'the HTML base vocabulary is still present');

        // A body placing the host component <Callout> is now legal against the composed profile.
        (new ProfileLegality)->assert(
            [['kind' => 'block', 'name' => 'Callout', 'isComponent' => true, 'props' => [], 'children' => [], 'dynamic' => false, 'id' => 'c1']],
            $profile,
        );
        $this->addToAssertionCount(1);
    }

    public function test_each_realm_serves_its_own_host_vocabulary_and_an_unbound_realm_keeps_the_app_wide_source(): void
    {
        // EP-07: each realm serves one profile. A realm the host binds gets its own components; one it does not keeps the
        // app-wide ManifestNodeSource, so a host that has not bound its realms is unchanged.
        $this->app->instance(ManifestNodeSource::class, new Fixtures\FixtureNodeSource);
        config(['beam.ux.manifest.realms' => ['operator' => Fixtures\OperatorNodeSource::class]]);
        $names = function (string $realm): array {
            $entry = $this->entry('central');
            $entry->realm = $realm;

            return array_column($this->resolver('central_db')->resolve($entry)['nodes'], 'name');
        };

        $this->assertContains('Banner', $names('operator'));
        $this->assertNotContains('Callout', $names('operator'), 'a bound realm does not also take the app-wide source');
        $this->assertContains('Callout', $names('site'));
        $this->assertNotContains('Banner', $names('site'));
        $this->assertContains('div', $names('operator'), 'every realm keeps the HTML base');

        config(['beam.ux.manifest.realms' => ['operator' => \stdClass::class]]);
        $this->expectException(\InvalidArgumentException::class);
        $names('operator');
    }

    public function test_the_shipped_auth_islands_are_sealed_nodes_gated_like_the_js_registry(): void
    {
        // N3447: beam's default auth trees place these and beam-inertia registers them; the profile now declares them.
        config(['fortify.features' => []]);
        $names = fn () => array_column($this->resolver('central_db')->resolve($this->entry('central'))['nodes'], 'name');
        $always = ['AuthLogin', 'AuthForgotPassword', 'AuthResetPassword'];
        foreach ($always as $name) {
            $this->assertContains($name, $names());
        }
        foreach (['AuthRegister', 'AuthTwoFactorChallenge', 'AuthVerifyEmail', 'AuthConfirmPassword'] as $name) {
            $this->assertNotContains($name, $names(), "{$name} is offered only where the host serves its page");
        }

        config(['fortify.features' => [\Laravel\Fortify\Features::registration(), \Laravel\Fortify\Features::twoFactorAuthentication(), \Laravel\Fortify\Features::emailVerification()]]);
        // Confirm-password follows the host's own switch: the confirm view it registers, as its JS lists the feature.
        \Laravel\Fortify\Fortify::confirmPasswordView('auth.confirm-password');
        $profile = $this->resolver('central_db')->resolve($this->entry('central'));
        foreach (['AuthRegister', 'AuthTwoFactorChallenge', 'AuthVerifyEmail', 'AuthConfirmPassword', ...$always] as $name) {
            $node = collect($profile['nodes'])->firstWhere('name', $name);
            $this->assertSame(['type' => 'object', 'additionalProperties' => false], $node['attrsSchema'] ?? null, "{$name} is sealed");
            $this->assertSame([], $node['admitsChildCategories'] ?? null);
        }
        (new ProfileLegality)->assert(
            [['kind' => 'block', 'name' => 'AuthLogin', 'isComponent' => true, 'props' => [], 'children' => [], 'dynamic' => false, 'id' => 'a1']],
            $profile,
        );
        $this->addToAssertionCount(1);
    }
}
