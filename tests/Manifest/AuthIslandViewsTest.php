<?php

namespace Splicewire\Beam\Ux\Tests\Manifest;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Laravel\Fortify\Features;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Ux\Manifest\AuthIslandNodeSource;
use Splicewire\Beam\Ux\Manifest\Nodes\Auth\AuthForgotPassword;
use Splicewire\Beam\Ux\Manifest\Nodes\Auth\AuthLogin;
use Splicewire\Beam\Ux\Manifest\Nodes\Auth\AuthResetPassword;
use Splicewire\Beam\Ux\Manifest\Nodes\Auth\AuthTwoFactorChallenge;
use Splicewire\Beam\Ux\Manifest\Nodes\Auth\AuthVerifyEmail;

final class AuthIslandViewsTest extends TestCase
{
    public function test_auth_islands_follow_the_hosts_existing_fortify_views_contract(): void
    {
        $previous = Container::getInstance();
        $app = new Container;
        $config = new Repository(['fortify' => [
            'views' => false,
            'features' => [Features::twoFactorAuthentication(), Features::emailVerification()],
        ]]);
        $app->instance('config', $config);
        Container::setInstance($app);

        try {
            $source = new AuthIslandNodeSource;
            $this->assertSame([], $source->nodeClassesFor('content'));
            $config->set('fortify.views', true);
            $expected = [AuthLogin::class, AuthForgotPassword::class, AuthResetPassword::class,
                AuthTwoFactorChallenge::class, AuthVerifyEmail::class];
            $this->assertSame($expected, $source->nodeClassesFor('content'));
            $config->set('fortify', ['features' => $config->get('fortify.features')]);
            $this->assertSame($expected, $source->nodeClassesFor('content'));
            $config->set('fortify.features', []);
            $this->assertSame([AuthLogin::class, AuthForgotPassword::class, AuthResetPassword::class], $source->nodeClassesFor('content'));
            $this->assertSame([], $source->nodeClassesFor('html'));
        } finally {
            Container::setInstance($previous);
        }
    }
}
