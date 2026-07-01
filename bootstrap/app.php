<?php

use App\Http\Middleware\AddContentSecurityPolicy;
use App\Http\Middleware\EnforceSessionTimeout;
use App\Http\Middleware\EnforceTwoFactorPolicy;
use App\Http\Middleware\EnsureAccountStillApproved;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventSearchIndexing;
use App\Http\Middleware\RoleScope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
        __DIR__.'/../app/Domain/SecurityDevices/Console',
        __DIR__.'/../app/Domain/Governance/Console',
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api-hr.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            EnforceSessionTimeout::class,
            EnsureAccountStillApproved::class,
            EnforceTwoFactorPolicy::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            PreventSearchIndexing::class,
            AddContentSecurityPolicy::class,
        ]);

        $middleware->api(prepend: [
            ThrottleRequests::class.':api',
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'permission' => EnsurePermission::class,
            'role_scope' => RoleScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
