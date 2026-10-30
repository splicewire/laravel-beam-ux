<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Spatie\LaravelPackageTools\Package;
use Splicewire\Beam\Ux\BeamUxServiceProvider;

/** The migration boundary only: each test owns a process-local SQLite database, with no host boot. */
class ChromeColumnsMigrationTest extends TestCase
{
    private Container $previousContainer;

    private $previousFacadeApplication;

    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('config', new Repository);
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $container->instance('db', $this->database->getDatabaseManager());
        $container->bind('db.schema', fn () => $this->database->getConnection()->getSchemaBuilder());
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_published_additive_stubs_upgrade_the_pre_chrome_create(): void
    {
        // Byte-exact create from 2a17331^; it cannot silently follow today's repaired create.
        (require __DIR__.'/Fixtures/Migrations/pre_chrome_create_beam_ux_entries_table.php.stub')->up();
        foreach (['layout', 'template', 'nav_group'] as $column) {
            $this->assertFalse(Schema::hasColumn('beam_ux_entries', $column), 'The legacy fixture must lack '.$column);
        }
        $this->runDeclaredEntryAdditions();
        $this->assertChromeColumns();
    }

    public function test_current_create_and_additive_stubs_are_repeatable_without_replacing_values(): void
    {
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        $this->database->getConnection()->table('beam_ux_entries')->insert([
            'id' => 'chrome-row', 'slug' => 'page', 'type' => 'page',
            'layout' => 'existing-layout', 'template' => 'existing-template', 'nav_group' => 'Existing group',
        ]);
        $this->runDeclaredEntryAdditions();
        $this->runDeclaredEntryAdditions();
        $this->assertChromeColumns();
        $row = $this->database->getConnection()->table('beam_ux_entries')->first();
        $this->assertSame('existing-layout', $row->layout);
        $this->assertSame('existing-template', $row->template);
        $this->assertSame('Existing group', $row->nav_group);
    }

    public function test_partial_host_schema_keeps_existing_chrome_and_adds_each_missing_column(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table): void {
            $table->string('id');
            $table->string('layout')->nullable();
        });
        $this->database->getConnection()->table('beam_ux_entries')->insert(['id' => 'existing', 'layout' => 'keep-layout']);
        $migration = $this->chromeMigration();
        $migration->up();
        $migration->up();
        $this->assertChromeColumns();
        $row = $this->database->getConnection()->table('beam_ux_entries')->first();
        $this->assertSame('keep-layout', $row->layout);
        $this->assertNull($row->template);
        $this->assertNull($row->nav_group);
    }

    public function test_publish_only_registration_orders_chrome_after_component_names(): void
    {
        $package = $this->package();
        $names = $package->migrationFileNames;
        $component = array_search('shared/add_component_names_to_beam_ux_entries_table', $names, true);
        $chrome = array_search('shared/add_chrome_to_beam_ux_entries_table', $names, true);
        $this->assertIsInt($component);
        $this->assertSame($component + 1, $chrome);
        $this->assertFalse($package->runsMigrations);
    }

    private function package(): Package
    {
        $package = new Package;
        $package->setBasePath(dirname(__DIR__).'/src');
        (new BeamUxServiceProvider(Container::getInstance()))->configurePackage($package);

        return $package;
    }

    private function runDeclaredEntryAdditions(): void
    {
        foreach ($this->package()->migrationFileNames as $name) {
            if (str_starts_with($name, 'shared/add_') && str_ends_with($name, '_to_beam_ux_entries_table')) {
                (require dirname(__DIR__).'/database/migrations/'.$name.'.php.stub')->up();
            }
        }
    }

    private function chromeMigration(): \Illuminate\Database\Migrations\Migration
    {
        return require dirname(__DIR__).'/database/migrations/shared/add_chrome_to_beam_ux_entries_table.php.stub';
    }

    private function assertChromeColumns(): void
    {
        foreach (['layout', 'template', 'nav_group'] as $column) {
            $this->assertTrue(Schema::hasColumn('beam_ux_entries', $column), $column);
            $definition = collect(Schema::getColumns('beam_ux_entries'))->firstWhere('name', $column);
            $this->assertTrue($definition['nullable'], $column.' must be nullable');
        }
    }
}
