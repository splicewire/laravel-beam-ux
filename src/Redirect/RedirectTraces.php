<?php

namespace Splicewire\Beam\Ux\Redirect;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Data\RedirectTraceData;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;

/**
 * Every Redirect of a site walked once, as a guest, by the renderer's own {@see RedirectResolver::follow()} — the walk
 * {@see \Splicewire\Beam\Ux\Doctor\BeamUxRedirectAudit} takes, so what the Site map flags is what the doctor names and
 * what a guest gets. One walk per Redirect (bounded by `beam.ux.redirects.max_steps`), never one per page.
 */
final class RedirectTraces
{
    /** The ends that fail a guest (D-37-g (b)); `answered`, `denied` (readers only) and `shadowed` do not. */
    public const ATTENTION = ['loop', 'too-long', 'dead-end', 'gone'];

    public function __construct(
        private RedirectResolver $resolver,
        private RedirectService $redirects,
        private EntryPathResolver $paths,
    ) {}

    /** @return list<RedirectTraceData> */
    public function all(?string $realm = null): array
    {
        $connection = (new BeamUxRedirect)->getConnectionName();
        if (! Schema::connection($connection)->hasTable((new BeamUxRedirect)->getTable())) {
            return [];
        }

        $traces = [];
        $rows = BeamUxRedirect::query()->when($realm !== null, fn ($q) => $q->where('realm', $realm))
            ->orderBy('realm')->orderBy('path')->orderBy('match')->get();
        foreach ($rows as $redirect) {
            $traces[] = $this->trace($redirect, $connection);
        }

        return $traces;
    }

    private function trace(BeamUxRedirect $redirect, ?string $connection): RedirectTraceData
    {
        $realm = (string) $redirect->realm;
        if ($redirect->match === 'exact' && $this->redirects->answers($redirect->path, $realm)) {
            $walk = ['end' => 'shadowed', 'to' => null, 'steps' => []];
        } else {
            $walk = $this->resolver->follow(Request::create($redirect->path), $redirect->path, $realm, null);
        }

        return new RedirectTraceData(
            id: (string) $redirect->getKey(),
            realm: $realm,
            path: $redirect->path,
            match: $redirect->match,
            targetKind: $redirect->target_kind,
            permanent: (bool) $redirect->permanent,
            origin: (string) $redirect->origin,
            end: $walk['end'],
            to: $walk['to'],
            steps: array_values($walk['steps']),
            hops: count($walk['steps']),
            attention: in_array($walk['end'], self::ATTENTION, true),
            pageId: $this->pageOf($redirect, $walk, $realm, $connection),
        );
    }

    /**
     * The page a walk lands on; when it stops short, the page its last Redirect names (by identity, or at a path), so a
     * page row can show the old address that fails to reach it.
     */
    private function pageOf(BeamUxRedirect $redirect, array $walk, string $realm, ?string $connection): ?string
    {
        if ($walk['end'] === 'answered') {
            $to = $walk['to'];
            if ($to === null || ! str_starts_with($to, '/')) {
                return null;
            }
            $chain = $this->paths->resolve((string) parse_url($to, PHP_URL_PATH), $realm);

            return $chain === null ? null : (string) end($chain)->getKey();
        }
        $last = $walk['steps'] === [] ? $redirect : RedirectMap::for($realm, $connection)->match((string) end($walk['steps']));
        if ($last === null) {
            return null;
        }
        if ($last->target_kind === 'page') {
            return $last->target_entry_id === null ? null : (string) $last->target_entry_id;
        }
        if ($last->target_kind === 'path' && $last->target_path !== null) {
            $chain = $this->paths->resolve((string) $last->target_path, $realm);

            return $chain === null ? null : (string) end($chain)->getKey();
        }

        return null;
    }
}
