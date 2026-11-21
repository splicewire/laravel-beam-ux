<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\JsonPointer;
use Splicewire\Beam\Config\Overrides\ConfigOverrideEntry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideScope;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Ux\Recording\ConfigOverrideMirror;
use Splicewire\Beam\Ux\Tests\Fixtures\Config\ReleaseNestedPlainSection;
use Splicewire\Beam\Ux\Tests\Fixtures\Config\ReleaseNestedSecretSection;

/**
 * config-sections 11 r5: nested secrets in the config mirror (CONTRACT-11 rev 4 section 2b). A secret leaf TWO or three
 * levels below the parent an operator sets must survive a round trip, and the file must never carry a plaintext value at
 * or below a declared-secret key. Companion to {@see ConfigMirrorRoundTripTest} (one level). All secret values are FAKE.
 *
 * The HIGH both reviewers raised: serializing a parent set whose nested child is secret leaves the emptied intermediate
 * map behind; {@see JsonPointer::leaves()} counts it as a leaf, and the pre-fix per-leaf replay emitted a `set` of that
 * intermediate map — a PARENT op that made laravel-beam's saveDraft drop the target's secret split out of it.
 */
class ConfigMirrorNestedTest extends AuthoredComponentNativeTestCase
{
    private const FIXTURES = __DIR__.'/Fixtures/Config';

    private const ROOT = 'release-nested';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $db = 'file:beam11nested_'.uniqid().'?mode=memory&cache=shared';
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
        class_exists(ReleaseNestedSecretSection::class); // loads the fixture file, which declares both versions
        config([self::ROOT => require self::FIXTURES.'/host/'.self::ROOT.'.php']);
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(self::FIXTURES.'/host'));
        $this->declare(ReleaseNestedPlainSection::class);
    }

    /** Register `release-nested` under the given declaration (and forget what captured the old registry). */
    private function declare(string $section): void
    {
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(\Splicewire\Beam\Config\Overrides\ConfigOverlay::class);
        app(ConfigSectionRegistry::class)->section($section, owner: 'qa/nested');
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

    /** @param  array<string, mixed>  $document */
    private function import(array $document): void
    {
        $section = app(ConfigSectionRegistry::class)->get(self::ROOT)?->section;
        $this->store()->saveDraft(self::ROOT, ConfigOverrideMirror::toOps($document, $section));
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
            $segments = JsonPointer::segments((string) $pointer);
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

    /**
     * The mirror document of a version saved under the PLAIN declaration (the nested tokens in plaintext), read once the
     * leaves are declared SECRET. Afterwards the store holds no row: the target starts empty unless a test seeds it.
     *
     * @return array<string, mixed>
     */
    private function documentOfAnOldPlainVersion(): array
    {
        // `auth.user` is host-owned (ceiling Host): an operator write to /relay must carry its host value unchanged, so
        // the store inherits it rather than refusing. It lands in the file's inherit, never a set.
        $this->publish([['pointer' => '/relay', 'op' => 'set', 'value' => ['auth' => ['token' => 'FAKE-OLD-NESTED-TOKEN', 'user' => 'host.user.fixed'], 'region' => 'eu-file']]]);
        $this->declare(ReleaseNestedSecretSection::class);
        $bytes = (string) app(ConfigOverrideMirror::class)->bytes(self::ROOT, 'testing');
        $this->assertStringNotContainsString('FAKE-OLD-NESTED-TOKEN', $bytes, 'no secret value in the committed file');
        ConfigOverrideScope::query()->where('section', self::ROOT)->delete();
        config([self::ROOT => require self::FIXTURES.'/host/'.self::ROOT.'.php']);
        $this->app->forgetInstance(\Splicewire\Beam\Config\Overrides\ConfigOverlay::class);
        $this->app->forgetInstance(ConfigOverrideStore::class);

        return json_decode($bytes, true);
    }

    // ---- pure toOps: the replay contract (no store) --------------------------------------------------------------

    public function test_to_ops_skips_the_emptied_residue_of_a_nested_split_so_no_parent_op_is_emitted(): void
    {
        // A parent set whose nested child was taken out: `auth` is the emptied map `[]`, a leaf to JsonPointer. The
        // only op is the public sibling; the intermediate map is never set (it would drop the target's nested secret).
        $document = ['root' => self::ROOT, 'overrides' => [
            ['pointer' => '/relay', 'op' => 'set', 'value' => ['auth' => [], 'region' => 'eu-file'], 'inherit' => ['/auth/token']],
            ['pointer' => '/relay/auth/token', 'op' => 'set', 'secret' => true],
        ]];

        $this->assertSame(
            [['pointer' => '/relay/region', 'op' => 'set', 'value' => 'eu-file']],
            ConfigOverrideMirror::toOps($document),
            'only the public leaf is replayed; the emptied /relay/auth residue is not a parent op',
        );
    }

    public function test_to_ops_skips_a_host_ceiling_leaf_named_in_inherit(): void
    {
        // The host-owned `/auth/user` sits in the parent value and in `inherit`; it must not be replayed as an op.
        $document = ['root' => self::ROOT, 'overrides' => [
            ['pointer' => '/relay', 'op' => 'set', 'value' => ['auth' => ['user' => 'host.user.fixed'], 'region' => 'eu-file'], 'inherit' => ['/auth/token', '/auth/user']],
            ['pointer' => '/relay/auth/token', 'op' => 'set', 'secret' => true],
        ]];

        $this->assertSame(
            [['pointer' => '/relay/region', 'op' => 'set', 'value' => 'eu-file']],
            ConfigOverrideMirror::toOps($document),
            'the host-ceiling sibling named in inherit is left to the target, never set',
        );
    }

    public function test_to_ops_replays_a_public_leaf_three_levels_under_a_nested_secret(): void
    {
        $document = ['root' => self::ROOT, 'overrides' => [
            ['pointer' => '/deep', 'op' => 'set', 'value' => ['outer' => ['inner' => ['pub' => 'shown']]], 'inherit' => ['/outer/inner/token']],
            ['pointer' => '/deep/outer/inner/token', 'op' => 'set', 'secret' => true],
        ]];

        $this->assertSame(
            [['pointer' => '/deep/outer/inner/pub', 'op' => 'set', 'value' => 'shown']],
            ConfigOverrideMirror::toOps($document),
            'the public three-level leaf replays; no ancestor of the marker is set',
        );
    }

    public function test_to_ops_does_not_replay_a_value_leaf_at_or_below_a_marker(): void
    {
        // A crafted or old file with a plaintext value next to a marker: the value at or below the marker is dropped.
        $document = ['root' => self::ROOT, 'overrides' => [
            ['pointer' => '/relay', 'op' => 'set', 'value' => ['auth' => ['token' => 'CRAFTED-PLAINTEXT'], 'region' => 'eu-file'], 'inherit' => ['/auth/token']],
            ['pointer' => '/relay/auth/token', 'op' => 'set', 'secret' => true],
        ]];

        $ops = ConfigOverrideMirror::toOps($document);
        $this->assertSame([['pointer' => '/relay/region', 'op' => 'set', 'value' => 'eu-file']], $ops);
        $this->assertStringNotContainsString('CRAFTED-PLAINTEXT', json_encode($ops), 'no plaintext at or below the marker is replayed');
    }

    public function test_to_ops_leaves_a_marker_below_a_null_parent(): void
    {
        $document = ['root' => self::ROOT, 'overrides' => [
            ['pointer' => '/relay', 'op' => 'null'],
            ['pointer' => '/relay/auth/token', 'op' => 'set', 'secret' => true],
        ]];

        $this->assertSame([['pointer' => '/relay', 'op' => 'null']], ConfigOverrideMirror::toOps($document), 'the null parent replays; the marker is left to the target');
    }

    public function test_to_ops_leaves_an_old_files_now_secret_plaintext_untouched_under_the_current_declaration(): void
    {
        // An OLD file: a plain (non-marker) row whose pointer the CURRENT declaration makes secret. With the section,
        // toOps treats it exactly like a marker and emits no op, so the stale plaintext is never written in.
        $document = ['root' => self::ROOT, 'overrides' => [
            ['pointer' => '/relay/auth/token', 'op' => 'set', 'value' => 'STALE-PLAINTEXT-SECRET'],
            ['pointer' => '/relay/region', 'op' => 'set', 'value' => 'eu-file'],
        ]];

        $ops = ConfigOverrideMirror::toOps($document, ReleaseNestedSecretSection::class);
        $this->assertSame([['pointer' => '/relay/region', 'op' => 'set', 'value' => 'eu-file']], $ops);
        $this->assertStringNotContainsString('STALE-PLAINTEXT-SECRET', json_encode($ops));
        // Without the declaration (an unregistered root) the stored row alone decides: the plain row replays as before.
        $this->assertContains(['pointer' => '/relay/auth/token', 'op' => 'set', 'value' => 'STALE-PLAINTEXT-SECRET'], ConfigOverrideMirror::toOps($document));
    }

    public function test_to_ops_yields_no_op_for_a_document_whose_only_child_is_secret(): void
    {
        // The only-child-secret parent: `value` is the empty object, its child is a marker. No op at all.
        $document = ['root' => self::ROOT, 'overrides' => [
            ['pointer' => '/vault', 'op' => 'set', 'value' => [], 'inherit' => ['/key']],
            ['pointer' => '/vault/key', 'op' => 'set', 'secret' => true],
        ]];

        $this->assertSame([], ConfigOverrideMirror::toOps($document), 'a document that reconstructs no op imports as a no-op');
    }

    // ---- store + serializer: the file the Release writes ----------------------------------------------------------

    public function test_the_file_marks_a_nested_secret_and_keeps_its_public_siblings(): void
    {
        $rows = collect($this->documentOfAnOldPlainVersion()['overrides'])->keyBy('pointer')->all();

        $this->assertArrayHasKey('/relay', $rows);
        $this->assertSame('set', $rows['/relay']['op']);
        $this->assertSame('eu-file', $rows['/relay']['value']['region'], 'the public sibling survives');
        $this->assertContains('/auth/token', $rows['/relay']['inherit'], 'the nested secret leaf is inherited (RELATIVE)');
        $this->assertSame(['pointer' => '/relay/auth/token', 'op' => 'set', 'secret' => true], $rows['/relay/auth/token'], 'the nested secret is its own marker row');
        foreach ($rows as $row) {
            $this->assertSame([], array_diff(array_keys($row), ['pointer', 'op', 'value', 'secret', 'inherit']), 'no row key beyond CONTRACT-11 rev 4');
        }
    }

    public function test_a_secret_object_with_an_attributed_child_emits_only_markers(): void
    {
        // `smtp` is a SECRET object; `smtp.password` carries its own `#[ConfigKey]` (NOT secret). The ancestor rule — not
        // the nearest attribute — decides, so the committed file never carries the password in plaintext (r5 MEDIUM).
        $this->declare(ReleaseNestedSecretSection::class);
        $this->publish([['pointer' => '/smtp/password', 'op' => 'set', 'value' => 'FAKE-SMTP-PASSWORD']]);

        $bytes = (string) app(ConfigOverrideMirror::class)->bytes(self::ROOT, 'testing');
        $this->assertStringNotContainsString('FAKE-SMTP-PASSWORD', $bytes, 'no plaintext password in the file');
        $rows = collect(json_decode($bytes, true)['overrides']);
        $this->assertTrue($rows->every(fn (array $row): bool => ($row['secret'] ?? false) === true), 'every emitted row at or below the secret object is a marker');
        $this->assertTrue($rows->contains(fn (array $row): bool => $row['pointer'] === '/smtp/password' && ($row['secret'] ?? false) === true), 'the attributed child is a marker, not a plain row');
    }

    public function test_an_only_child_secret_parent_mirrors_as_an_empty_object(): void
    {
        $this->declare(ReleaseNestedSecretSection::class);
        $this->publish([['pointer' => '/vault', 'op' => 'set', 'value' => ['key' => 'FAKE-VAULT-KEY']]]);

        $bytes = (string) app(ConfigOverrideMirror::class)->bytes(self::ROOT, 'testing');
        $this->assertStringNotContainsString('FAKE-VAULT-KEY', $bytes, 'no plaintext secret in the file');
        $this->assertStringContainsString('"value": {}', $bytes, 'the emptied map is the object {}, not the array []');
        $rows = collect(json_decode($bytes, true)['overrides'])->keyBy('pointer')->all();
        $this->assertSame([], $rows['/vault']['value'], 'json_decode reads {} as an empty array; the bytes carry {}');
        $this->assertSame(['pointer' => '/vault/key', 'op' => 'set', 'secret' => true], $rows['/vault/key']);
    }

    public function test_importing_an_only_child_secret_file_is_a_no_op_not_an_error(): void
    {
        // The only-child-secret file yields no op; the command treats that as a no-op (SUCCESS), not the store's
        // "a draft needs at least one op". Written to a THROWAWAY file, never a live checkout.
        $this->declare(ReleaseNestedSecretSection::class);
        $this->publish([['pointer' => '/vault', 'op' => 'set', 'value' => ['key' => 'FAKE-VAULT-KEY-NOOP']]]);
        $bytes = (string) app(ConfigOverrideMirror::class)->bytes(self::ROOT, 'testing');
        ConfigOverrideScope::query()->where('section', self::ROOT)->delete();

        $dir = sys_get_temp_dir().'/cs11-nested-'.uniqid();
        mkdir($dir);
        $path = $dir.'/release-nested.json';
        file_put_contents($path, $bytes);
        try {
            $this->artisan('splicewire:beam:config:import', ['root' => self::ROOT, 'file' => $path])
                ->expectsOutputToContain('Nothing to import')
                ->assertSuccessful();
            $this->assertNull($this->store()->scope(self::ROOT), 'no draft was written');
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function test_every_fixture_document_obeys_the_section_2b_grammar(): void
    {
        $documents = [];
        // 1) a nested parent with a secret leaf, a public sibling and a host-ceiling sibling.
        $documents['nested old-plain'] = $this->documentOfAnOldPlainVersion();
        // 2) a secret object with an attributed child.
        $this->declare(ReleaseNestedSecretSection::class);
        $this->publish([['pointer' => '/smtp/password', 'op' => 'set', 'value' => 'FAKE-SMTP-PASSWORD-2']]);
        $documents['secret object'] = json_decode((string) app(ConfigOverrideMirror::class)->bytes(self::ROOT, 'testing'), true);
        // 3) the only-child-secret parent, plus a null-op parent, two secrets under one parent and an escaped pointer.
        ConfigOverrideScope::query()->where('section', self::ROOT)->delete();
        $this->publish([['pointer' => '/vault', 'op' => 'set', 'value' => ['key' => 'FAKE-VAULT-KEY-2']]]);
        $documents['only-child'] = json_decode((string) app(ConfigOverrideMirror::class)->bytes(self::ROOT, 'testing'), true);

        foreach ($documents as $label => $document) {
            $this->assertGrammar2b($document, $label);
        }
    }

    // ---- round trips: the nested secret survives import -----------------------------------------------------------

    public function test_import_into_an_empty_target_rebuilds_the_public_key_and_writes_no_nested_secret(): void
    {
        $this->import($this->documentOfAnOldPlainVersion());

        $relay = $this->effective()['relay'];
        $this->assertSame('eu-file', $relay['region'], 'the public key is rebuilt');
        $this->assertNull($relay['auth']['token'], "the nested secret is untouched: the host's (null), never the file's");
    }

    public function test_import_keeps_a_target_secret_split_out_of_its_own_nested_parent(): void
    {
        $document = $this->documentOfAnOldPlainVersion();
        $this->publish([['pointer' => '/relay/auth', 'op' => 'set', 'value' => ['token' => 'FAKE-TARGET-NESTED-SPLIT', 'user' => 'host.user.fixed']]]);
        $layer = $this->store()->publishedLayer($this->store()->scope(self::ROOT));
        $this->assertTrue($layer['/relay/auth/token']->secret, 'precondition: the target split the nested secret out of its own parent set');

        $this->import($document);

        $relay = $this->effective()['relay'];
        $this->assertSame('eu-file', $relay['region'], 'the public key updates');
        $this->assertSame('FAKE-TARGET-NESTED-SPLIT', $relay['auth']['token'], 'the nested secret survives: no parent op dropped it');
    }

    public function test_import_keeps_a_target_nested_secret_set_on_its_own(): void
    {
        $document = $this->documentOfAnOldPlainVersion();
        $this->publish([['pointer' => '/relay/auth/token', 'op' => 'set', 'value' => 'FAKE-TARGET-NESTED-OWN']]);

        $this->import($document);

        $this->assertSame('FAKE-TARGET-NESTED-OWN', $this->effective()['relay']['auth']['token'], 'the target secret set on its own survives');
    }

    public function test_import_leaves_a_host_ceiling_sibling_under_the_nested_parent_alone(): void
    {
        $document = $this->documentOfAnOldPlainVersion();

        $this->import($document);

        $this->assertSame('host.user.fixed', $this->effective()['relay']['auth']['user'], 'the host-owned sibling keeps the host value; the import is not refused');
    }

    public function test_import_of_an_old_plaintext_nested_key_leaves_a_target_secret_untouched(): void
    {
        $document = ['root' => self::ROOT, 'overrides' => [
            ['pointer' => '/relay/auth/token', 'op' => 'set', 'value' => 'STALE-FILE-TOKEN'],
            ['pointer' => '/relay/region', 'op' => 'set', 'value' => 'eu-file'],
        ]];
        $this->declare(ReleaseNestedSecretSection::class);
        $this->publish([['pointer' => '/relay/auth/token', 'op' => 'set', 'value' => 'FAKE-TARGET-UNTOUCHED']]);

        $this->import($document);

        $relay = $this->effective()['relay'];
        $this->assertSame('eu-file', $relay['region'], 'the public key updates');
        $this->assertSame('FAKE-TARGET-UNTOUCHED', $relay['auth']['token'], 'the old plaintext never overwrites the target secret');
    }

    /**
     * Assert CONTRACT-11 rev 4 section 2b mechanically — the PHP mirror of the JS refusal table.
     *
     * @param  array<string, mixed>  $document
     */
    private function assertGrammar2b(array $document, string $label): void
    {
        $this->assertSame(['$schema', 'root', 'version', 'overrides'], array_keys($document), "$label: the four root keys, in order");
        $this->assertIsString($document['$schema'], "$label: \$schema is a string");
        $this->assertIsString($document['root'], "$label: root is a string");
        $this->assertTrue($document['version'] === null || is_int($document['version']), "$label: version is an int or null");

        $pointers = [];
        $markers = [];
        $inherited = [];
        foreach ($document['overrides'] as $row) {
            $this->assertSame([], array_diff(array_keys($row), ['pointer', 'op', 'value', 'secret', 'inherit']), "$label: no row key beyond the grammar");
            $pointer = $row['pointer'];
            $this->assertIsString($pointer, "$label: pointer is a string");
            $this->assertStringStartsWith('/', $pointer, "$label: pointer has a leading slash");
            $this->assertNotContains($pointer, $pointers, "$label: no duplicate pointer $pointer");
            $pointers[] = $pointer;
            $this->assertContains($row['op'], ['set', 'null'], "$label: op is set or null");

            if (($row['secret'] ?? false) === true) {
                $this->assertArrayNotHasKey('value', $row, "$label: a marker carries no value");
                $this->assertArrayNotHasKey('inherit', $row, "$label: a marker carries no inherit");
                $markers[] = $pointer;

                continue;
            }
            if ($row['op'] === 'set') {
                $this->assertArrayHasKey('value', $row, "$label: a non-secret set carries a value");
            } else {
                $this->assertArrayNotHasKey('value', $row, "$label: a null row carries no value");
                $this->assertArrayNotHasKey('inherit', $row, "$label: a null row carries no inherit");
            }
            if (array_key_exists('inherit', $row)) {
                $this->assertSame('set', $row['op'], "$label: inherit only on a set");
                $this->assertIsArray($row['inherit'], "$label: inherit is an array");
                $this->assertSame(array_values($row['inherit']), $row['inherit'], "$label: inherit is a list");
                $this->assertSame(array_unique($row['inherit']), $row['inherit'], "$label: inherit has no duplicates");
                foreach ($row['inherit'] as $item) {
                    $this->assertIsString($item, "$label: inherit item is a string");
                    $this->assertStringStartsWith('/', $item, "$label: inherit item has a leading slash");
                    $this->assertNotSame('/', $item, "$label: inherit item is below the root");
                    $inherited[] = $pointer.$item;
                }
            }
        }

        // No non-marker row, and no value leaf, at or below a marker; and none that is an ancestor of an inherit target.
        foreach ($document['overrides'] as $row) {
            if (($row['secret'] ?? false) === true || ($row['op'] ?? null) !== 'set' || ! array_key_exists('value', $row) || ! is_array($row['value'])) {
                continue;
            }
            foreach (JsonPointer::leaves($row['value']) as $leaf) {
                $pointer = $row['pointer'].$leaf;
                foreach ($markers as $marker) {
                    $this->assertFalse($pointer === $marker || str_starts_with($pointer, $marker.'/'), "$label: no value leaf $pointer at or below the marker $marker");
                }
                foreach ($inherited as $target) {
                    $this->assertFalse($pointer === $target, "$label: no value leaf at an inherit target $target");
                }
            }
        }
        foreach ($markers as $marker) {
            foreach ($pointers as $pointer) {
                if ($pointer !== $marker) {
                    $this->assertFalse(str_starts_with($pointer, $marker.'/'), "$label: no row $pointer below the marker $marker");
                }
            }
        }
    }
}
