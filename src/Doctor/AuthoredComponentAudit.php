<?php

namespace Splicewire\Beam\Ux\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Components\AuthoredComponents;
use Splicewire\Beam\Ux\Components\ComponentScope;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Trusted operator diagnosis; omitted declarations never become a permission grant or a realm outage. */
final class AuthoredComponentAudit implements DoctorAudit
{
    public function run(): array
    {
        $model = new BeamUxEntry;
        if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            return [Finding::inconclusive('Authored Components', 'The entry table is unavailable.')];
        }
        $components = app(AuthoredComponents::class);
        $scope = app(ComponentScope::class);
        $scope->issues = [];
        foreach ($model->newQuery()->where('type', 'component')->get()->groupBy('realm') as $entries) {
            // Keep the actual host/realm Code vocabulary while diagnosing every stored Authored row.
            $base = app(ManifestProfileResolver::class)->resolve($entries->first());
            $base['nodes'] = array_values(array_filter($base['nodes'], fn ($node) => ! ($node['x-authored-component'] ?? false)));
            $components->extendProfile($entries->first(), $base, inspectAll: true);
        }
        $out = [];
        foreach ($scope->issues as $id => $reason) {
            $out[] = Finding::warn('Authored Components', 'Entry '.$id.': '.$reason.'. Repair its declaration; it is omitted from the profile.');
        }

        return $out ?: [Finding::pass('Authored Components', 'No invalid Authored component declarations were found on this connection.')];
    }
}
