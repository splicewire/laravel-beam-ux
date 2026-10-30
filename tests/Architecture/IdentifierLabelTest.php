<?php

namespace Splicewire\Beam\Ux\Tests\Architecture;

use Schemastud\DataSchemas\Attributes\Title;
use Splicewire\Beam\Schema\IdentifierLabels;
use Splicewire\Beam\Ux\Media\Data\EntryImageData;
use Splicewire\Beam\Ux\Media\Data\EntryImageLibraryData;
use Splicewire\Beam\Ux\Media\Data\EntryImageUploadData;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * app-walkthrough APP-09 at the owner: a class this package makes reachable from a particle declares its rendered label
 * (#[Title], or ProvidesEnumLabel on an enum), or is listed here. The list only shrinks. Hosts keep the same ratchet
 * (laravel-tower-starter's IdentifierLabelTest); 32 new classes reached it unlabeled because nothing here checked them
 * (launch row qitem-20261029210327-185c04be). Only this package's classes are judged; other packages keep their own.
 */
class IdentifierLabelTest extends TestCase
{
    /** The debt every host already lists with this package as owner. */
    private const RATCHET = [
        'Splicewire\Beam\Ux\Data\BeamUxEntryBodyData',
        'Splicewire\Beam\Ux\Data\BeamUxEntryBodyInputData',
        'Splicewire\Beam\Ux\Data\DashboardCardRowData',
        'Splicewire\Beam\Ux\Data\DashboardWelcomeData',
        'Splicewire\Beam\Ux\Data\EntryBodyClearInputData',
        'Splicewire\Beam\Ux\Data\EntryDraftInputData',
        'Splicewire\Beam\Ux\Data\EntryPublicationData',
        'Splicewire\Beam\Ux\Data\EntryPublishInputData',
        'Splicewire\Beam\Ux\Data\EntryVersionData',
        'Splicewire\Beam\Ux\Data\EntryVersionRestoreInputData',
        'Splicewire\Beam\Ux\Data\MirrorStatusRowData',
        'Splicewire\Beam\Ux\Data\SitemapHealthRowData',
        'Splicewire\Beam\Ux\Type\UxType',
    ];

    public function test_every_reachable_package_class_declares_its_label_or_is_listed(): void
    {
        $found = array_keys(array_filter(
            app(IdentifierLabels::class)->find(),
            fn (string $class): bool => str_starts_with($class, 'Splicewire\\Beam\\Ux\\'),
            ARRAY_FILTER_USE_KEY,
        ));

        $this->assertSame([], array_values(array_diff($found, self::RATCHET)), 'declare #[Title] (or ProvidesEnumLabel)');
        $this->assertSame([], array_values(array_diff(self::RATCHET, $found)), 'stale: the class declares its label, delete the entry');
    }

    /** The entry image library is mounted only by a host, so the walk above cannot reach it here. */
    public function test_the_host_mounted_image_library_declares_its_labels(): void
    {
        foreach ([EntryImageData::class, EntryImageLibraryData::class, EntryImageUploadData::class] as $class) {
            $this->assertNotSame([], (new \ReflectionClass($class))->getAttributes(Title::class), "{$class} declares no #[Title]");
        }
    }
}
