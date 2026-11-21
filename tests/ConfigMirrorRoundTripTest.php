<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideEntry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideScope;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Ux\Recording\ConfigOverrideMirror;
use Splicewire\Beam\Ux\Tests\Fixtures\Config\ReleaseConfigSection;
use Splicewire\Beam\Ux\Tests\Fixtures\Config\ReleaseLaterPlainSection;
use Splicewire\Beam\Ux\Tests\Fixtures\Config\ReleaseLaterSecretSection;

/**
 * config-sections 11 (Codex re-verify HIGH; lead RT-1425; CONTRACT-11 rev 4 section 2b): the config mirror round trip.
 *
 * A version saved while `relay.token` was declared NOT secret holds `/relay = {host, token}` in plaintext. Once the key is
 * declared secret, the mirror file keeps the PUBLIC parts of `/relay` (its `value` holds only `host`, and `inherit` lists
 * `/token`, relative) and writes the secret leaf as its own marker row with no value. On import:
 * - a parent `set` with a marker below it replays as one `set` per owned leaf, so laravel-beam's parent-op drop
 *   (ConfigOverrideStore.php:209) never removes a target's split-out secret, and an existing secret override is left alone;
 * - every other row replays exactly as before;
 * - by design, a public key the target holds under that parent but the file lacks is NOT removed (child sets update).
 *
 * The secret values here are FAKE. Same database wiring as ConfigRecordingTest.
 */
class ConfigMirrorRoundTripTest extends AuthoredComponentNativeTestCase
{
    private const FIXTURES = __DIR__.'/Fixtures/Config';

    private const ROOT = 'release-later';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $db = 'file:beam11rt_'.uniqid().'?mode=memory&cache=shared';
        $shared = ['driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => false];
        $app['config']->set('database.connections.testing', $shared);
        $app['config']->set('database.connections.central', $shared);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['create_beam_versions_table', 'create_beam_config_overrides_table'] as $stub) {
            (require dirname(__DIR__)."/vendor/splicewire/laravel-beam/database/migrations/shared/{$stub}.php.stub")->up();
        }
        class_exists(ReleaseLaterSecretSection::class); // loads the fixture file, which declares both versions of the section
        config([self::ROOT => require self::FIXTURES.'/host/'.self::ROOT.'.php']);
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(self::FIXTURES.'/host'));
        $this->declare(ReleaseLaterPlainSection::class);
    }

    /** Register `release-later` under the given declaration (and forget what captured the old registry). */
    private function declare(string $section): void
    {
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(\Splicewire\Beam\Config\Overrides\ConfigOverlay::class);
        app(ConfigSectionRegistry::class)->section(ReleaseConfigSection::class, owner: 'qa/fixture');
        app(ConfigSectionRegistry::class)->section($section, owner: 'qa/later');
    }

    private function store(): ConfigOverrideStore
    {
        return app(ConfigOverrideStore::class);
    }

    /** @param  list<array<string, mixed>>  $ops */
    private function publish(array $ops): void
    {
        $this->store()->saveDraft(self::ROOT, $ops);
        $this->store()->publish(self::ROOT);
    }

    /**
     * The mirror document of a version saved under the PLAIN declaration (relay.token in plaintext), read once the key is
     * declared SECRET. Afterwards the store holds no row for the root: the target starts empty unless a test seeds it.
     *
     * @return array<string, mixed>
     */
    private function documentOfAnOldPlainVersion(): array
    {
        $this->publish([['pointer' => '/relay', 'op' => 'set', 'value' => ['host' => 'relay.file.test', 'token' => 'FAKE-OLD-RELAY-TOKEN']]]);
        $this->declare(ReleaseLaterSecretSection::class);
        $bytes = (string) app(ConfigOverrideMirror::class)->bytes(self::ROOT, 'testing');
        $this->assertStringNotContainsString('FAKE-OLD-RELAY-TOKEN', $bytes, 'no secret value in the committed file');
        ConfigOverrideScope::query()->where('section', self::ROOT)->delete();
        // A publish is applied to THIS process's config at once; start the target from the host's values again, so the
        // empty target is really empty (the old version's plaintext must not linger in config()).
        config([self::ROOT => require self::FIXTURES.'/host/'.self::ROOT.'.php']);
        $this->app->forgetInstance(\Splicewire\Beam\Config\Overrides\ConfigOverlay::class);
        $this->app->forgetInstance(ConfigOverrideStore::class);

        return json_decode($bytes, true);
    }

    /** @param  list<array<string, mixed>>  $ops */
    private function import(array $document): void
    {
        $this->store()->saveDraft(self::ROOT, ConfigOverrideMirror::toOps($document));
        $this->store()->publish(self::ROOT);
    }

    /** The section as the published layer makes it, over the host's values. @return array<string, mixed> */
    private function effective(): array
    {
        $doc = config(self::ROOT);
        $scope = $this->store()->scope(self::ROOT);
        $layer = $scope === null ? [] : $this->store()->publishedLayer($scope);
        ksort($layer);
        foreach ($layer as $pointer => $entry) {
            /** @var ConfigOverrideEntry $entry */
            $segments = \Splicewire\Beam\Config\JsonPointer::segments((string) $pointer);
            $value = $entry->valueOver($doc);
            $cursor = &$doc;
            foreach ($segments as $segment) {
                $cursor = &$cursor[$segment];
            }
            $cursor = $value;
            unset($cursor);
        }

        return $doc;
    }

    public function test_the_file_keeps_the_public_parts_of_a_parent_and_marks_its_secret_leaf(): void
    {
        $rows = collect($this->documentOfAnOldPlainVersion()['overrides'])->keyBy('pointer')->all();

        $this->assertSame(['pointer' => '/relay', 'op' => 'set', 'value' => ['host' => 'relay.file.test'], 'inherit' => ['/token']], $rows['/relay'],
            'value holds the public key only; the secret leaf is inherited (RELATIVE pointer)');
        $this->assertSame(['pointer' => '/relay/token', 'op' => 'set', 'secret' => true], $rows['/relay/token'], 'the secret leaf is its own marker row');
        foreach ($rows as $row) {
            $this->assertSame([], array_diff(array_keys($row), ['pointer', 'op', 'value', 'secret', 'inherit']), 'no row key beyond CONTRACT-11 rev 4');
        }
    }

    public function test_to_ops_replays_a_document_without_markers_exactly_as_before(): void
    {
        $document = ['root' => self::ROOT, 'overrides' => [
            ['pointer' => '/apiKey', 'op' => 'null'],
            ['pointer' => '/relay', 'op' => 'set', 'value' => ['host' => 'h'], 'inherit' => ['/region']],
        ]];

        $this->assertSame([
            ['pointer' => '/apiKey', 'op' => 'null'],
            ['pointer' => '/relay', 'op' => 'set', 'value' => ['host' => 'h'], 'inherit' => ['/region']],
        ], ConfigOverrideMirror::toOps($document));
    }

    public function test_import_into_an_empty_target_rebuilds_the_public_key_and_writes_no_secret(): void
    {
        $this->import($this->documentOfAnOldPlainVersion());

        $relay = $this->effective()['relay'];
        $this->assertSame('relay.file.test', $relay['host'], 'the public key is rebuilt');
        $this->assertNull($relay['token'], "the secret is untouched: the host's (null), never the file's");
    }

    public function test_import_into_a_target_without_the_secret_leaf_leaves_it_absent(): void
    {
        $document = $this->documentOfAnOldPlainVersion();
        $this->publish([['pointer' => '/relay/host', 'op' => 'set', 'value' => 'relay.target.test']]);

        $this->import($document);

        $relay = $this->effective()['relay'];
        $this->assertSame(['relay.file.test', null], [$relay['host'], $relay['token']]);
        $this->assertArrayNotHasKey('/relay/token', $this->store()->publishedLayer($this->store()->scope(self::ROOT)));
    }

    public function test_import_keeps_a_target_secret_split_out_of_its_own_parent_set_and_updates_the_public_key(): void
    {
        $document = $this->documentOfAnOldPlainVersion();
        $this->publish([['pointer' => '/relay', 'op' => 'set', 'value' => ['host' => 'relay.target.test', 'token' => 'FAKE-TARGET-SPLIT-SECRET']]]);
        $layer = $this->store()->publishedLayer($this->store()->scope(self::ROOT));
        $this->assertTrue($layer['/relay/token']->secret, 'precondition: the target split the secret out of its parent set');

        $this->import($document);

        $relay = $this->effective()['relay'];
        $this->assertSame('relay.file.test', $relay['host'], 'the public key updates');
        $this->assertSame('FAKE-TARGET-SPLIT-SECRET', $relay['token'], 'the existing secret override survives (no parent op dropped it)');
    }

    public function test_import_keeps_a_target_secret_set_on_its_own(): void
    {
        $document = $this->documentOfAnOldPlainVersion();
        $this->publish([['pointer' => '/relay/token', 'op' => 'set', 'value' => 'FAKE-TARGET-OWN-SECRET']]);

        $this->import($document);

        $relay = $this->effective()['relay'];
        $this->assertSame(['relay.file.test', 'FAKE-TARGET-OWN-SECRET'], [$relay['host'], $relay['token']]);
    }

    public function test_by_design_a_parent_with_a_secret_leaf_imports_as_child_sets_so_a_public_key_the_file_lacks_is_kept(): void
    {
        // The trade lead RT-1425 accepted: such a parent replays as one `set` per owned leaf (to keep the target's secret),
        // so it updates the keys the file carries and does not replace the whole object. Not a bug. `region` is seeded
        // INSIDE the target's own `/relay` parent value (not as its own `/relay/region` entry, which `document()` would
        // keep over a parent set as well): only the per-leaf replay — not a parent `set` of `/relay` — explains its survival.
        $document = $this->documentOfAnOldPlainVersion();
        // `token` is a required (non-Optional) property, so a `/relay` object set must name it; the store splits it back
        // out as the secret. `region` is the public key INSIDE the target's own parent value that the file lacks.
        $this->publish([['pointer' => '/relay', 'op' => 'set', 'value' => ['host' => 'relay.target.test', 'region' => 'eu-target', 'token' => 'FAKE-TARGET-TRADE-SECRET']]]);

        $this->import($document);

        $relay = $this->effective()['relay'];
        $this->assertSame(['relay.file.test', 'eu-target'], [$relay['host'], $relay['region']], 'host (in the file) updates; region (absent from the file, inside the target parent) is kept');
    }
}
