<?php

namespace Tests\Support;

use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;

trait FakesSsoProvider
{
    private function configureSsoFixture(string $domain = 'example.test'): void
    {
        config([
            'inertia.ssr.enabled' => false,
            'app.url' => 'https://application.example.test',
            'sso.staff_domain' => $domain,
            'services.microsoft' => ['client_id' => '7d41a4c7-1cbb-4d89-b1b7-8a1dd4e43c90', 'client_secret' => 'synthetic-microsoft-secret', 'tenant' => 'a29c6a0d-0621-447a-8954-0f15a09eea10'],
            'services.google' => ['client_id' => 'synthetic.apps.googleusercontent.com', 'client_secret' => 'synthetic-google-secret'],
        ]);
    }

    private function fakeSsoProvider(string $provider, array $attributes, array $raw = [], ?callable $duringCallback = null): mixed
    {
        $user = (new SocialiteUser)->map($attributes)->setRaw([
            ...$attributes,
            'email_verified' => true,
            'hd' => 'example.test',
            'mail' => $attributes['email'] ?? null,
            'userPrincipalName' => $attributes['email'] ?? null,
            ...$raw,
        ])->setToken('synthetic-access-token')->setRefreshToken('synthetic-refresh-token')->setExpiresIn(3600);
        $driver = Mockery::mock();
        $driver->shouldReceive('setConfig')->andReturnSelf();
        $driver->shouldReceive('setScopes')->andReturnSelf();
        $driver->shouldReceive('with')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturnUsing(function (): RedirectResponse {
            request()->session()->put('state', 'synthetic-state');

            return new RedirectResponse('https://provider.example.test/authorize');
        });
        $driver->shouldReceive('user')->andReturnUsing(function () use ($user, $duringCallback) {
            $duringCallback?->__invoke();

            return $user;
        });
        Socialite::shouldReceive('buildProvider')->andReturn($driver);

        return $driver;
    }

    private function beginSsoFixture(string $provider, string $audience = 'staff', bool $link = false): void
    {
        $path = $audience === 'portal' ? '/portal/auth/' : '/auth/';
        $this->get($path.$provider.'/redirect'.($link ? '?link=1' : ''))->assertRedirect('https://provider.example.test/authorize');
    }
}
