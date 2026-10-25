<?php

namespace Splicewire\Beam\Ux\Manifest;

use Laravel\Fortify\Features;
use Schemastud\Blockdoc\Contracts\ManifestNodeSource;
use Schemastud\Blockdoc\Schema\ManifestBuilder;
use Splicewire\Beam\Ux\Manifest\Nodes\Auth\AuthConfirmPassword;
use Splicewire\Beam\Ux\Manifest\Nodes\Auth\AuthForgotPassword;
use Splicewire\Beam\Ux\Manifest\Nodes\Auth\AuthLogin;
use Splicewire\Beam\Ux\Manifest\Nodes\Auth\AuthRegister;
use Splicewire\Beam\Ux\Manifest\Nodes\Auth\AuthResetPassword;
use Splicewire\Beam\Ux\Manifest\Nodes\Auth\AuthTwoFactorChallenge;
use Splicewire\Beam\Ux\Manifest\Nodes\Auth\AuthVerifyEmail;

/**
 * The seven shipped auth-page islands (EP-07, N3447), as package-owned sealed `#[NodeType]` nodes in the content
 * profile, so the palette, the inspector and the save know the components beam's default auth trees place
 * ({@see \Splicewire\Beam\Ux\Publish\EntryInitialBody}) and `@splicewire/beam-inertia` registers.
 *
 * Each is offered where the host serves its page, gated as beam-inertia's registry gates it: login, forgot and
 * reset always; register, two-factor and email verification on their Fortify features; confirm-password always,
 * since Fortify always serves that route. A host without Fortify serves no auth pages, so it gets none. Where a
 * host's JS build gates differently, `beam:doctor`'s registry check reports the drift.
 */
final class AuthIslandNodeSource implements ManifestNodeSource
{
    public function profiles(): array
    {
        return ['content'];
    }

    public function nodeClassesFor(string $profile): array
    {
        if ($profile !== 'content' || ! class_exists(Features::class)) {
            return [];
        }

        return array_values(array_filter([
            AuthLogin::class,
            Features::enabled(Features::registration()) ? AuthRegister::class : null,
            AuthForgotPassword::class,
            AuthResetPassword::class,
            AuthConfirmPassword::class,
            Features::enabled(Features::twoFactorAuthentication()) ? AuthTwoFactorChallenge::class : null,
            Features::enabled(Features::emailVerification()) ? AuthVerifyEmail::class : null,
        ]));
    }

    public function docAdmitsFor(string $profile): ?array
    {
        return ['html'];
    }

    public function marksFor(string $profile): array
    {
        return [];
    }

    /** @return array<string, mixed> sealed islands: their own attrs schema, without the builder's default id attribute */
    public function manifest(string $profile): array
    {
        $manifest = (new ManifestBuilder($this))->build($profile);
        foreach ($this->nodeClassesFor($profile) as $index => $class) {
            $manifest['nodes'][$index]['attrsSchema'] = $class::attrsSchema();
        }

        return $manifest;
    }
}
