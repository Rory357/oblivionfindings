<?php

use App\Exceptions\RecoverableTaskAuthorizationException;
use App\Http\Middleware\AddContentSecurityPolicy;
use App\Http\Middleware\AuthenticateItServiceIdentity;
use App\Http\Middleware\AuthenticateMonitoringCollector;
use App\Http\Middleware\EnforceSessionTimeout;
use App\Http\Middleware\EnforceTwoFactorPolicy;
use App\Http\Middleware\EnsureAccountStillApproved;
use App\Http\Middleware\EnsureItApiAbility;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventSearchIndexing;
use App\Http\Middleware\RecordItApiRequest;
use App\Http\Middleware\RoleScope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

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
        then: function (): void {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/monitoring-collector.php'));
        },
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth']],
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
            'it.service' => AuthenticateItServiceIdentity::class,
            'it.ability' => EnsureItApiAbility::class,
            'it.api.request' => RecordItApiRequest::class,
            'monitoring.collector' => AuthenticateMonitoringCollector::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'secret_manager_reference',
            'credential_material',
            'lease_id',
            'token',
            'access_token',
            'refresh_token',
            'api_key',
            'client_secret',
            'webhook_secret',
            'private_key',
            'passphrase',
            'read_back_witness_credential',
            'cd_witness_credential',
            'witness_credential',
            'witness_1_credential',
            'witness_2_credential',
            'waiver_approver_credential',
        ]);

        $exceptions->render(function (
            RecoverableTaskAuthorizationException $exception,
            Request $request,
        ) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 403);
            }

            return redirect($exception->returnTo)
                ->with('error', $exception->getMessage());
        });
    })->create();
