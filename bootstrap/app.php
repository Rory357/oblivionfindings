<?php

use App\Domain\It\Services\ItTicketRequestTrace;
use App\Domain\Referrals\Http\ProtectDocumentRequestResponses;
use App\Exceptions\RecoverableTaskAuthorizationException;
use App\Http\Middleware\AddContentSecurityPolicy;
use App\Http\Middleware\AuthenticateItServiceIdentity;
use App\Http\Middleware\AuthenticateMonitoringCollector;
use App\Http\Middleware\EnforceSessionTimeout;
use App\Http\Middleware\EnforceTwoFactorPolicy;
use App\Http\Middleware\EnsureAccountStillApproved;
use App\Http\Middleware\EnsureExternalClinicalIsolation;
use App\Http\Middleware\EnsureItApiAbility;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventSearchIndexing;
use App\Http\Middleware\ProtectItDraftResponses;
use App\Http\Middleware\ProtectObliResponses;
use App\Http\Middleware\ProtectRemoteSupportResponses;
use App\Http\Middleware\ProtectVendorVaultResponses;
use App\Http\Middleware\RecordItApiRequest;
use App\Http\Middleware\RoleScope;
use App\Http\Middleware\TraceItTicketCreation;
use App\Support\Medication\ScrubNestedWitnessSecrets;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

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
            Route::middleware('api')->prefix('api')->group(base_path('routes/endpoint-agent.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/obli-api.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/chat-media.php'));
        },
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Mention ranges refer to the submitted text. Chat normalises text and
        // adjusts those ranges together after authorisation, rather than losing
        // leading whitespace in the global string-trimming middleware.
        $middleware->trimStrings(except: [fn (Request $request) => ($request->isMethod('POST') && $request->is('chat/api/conversations/*/messages')) || ($request->isMethod('PATCH') && $request->is('chat/api/messages/*')) || ($request->isMethod('PUT') && $request->is('chat/api/conversations/*/draft'))]);
        $middleware->prepend(ProtectObliResponses::class);
        $middleware->prepend(ProtectRemoteSupportResponses::class);
        // SDP is a wire protocol: retain exact CRLF and fingerprint bytes.
        $middleware->trimStrings(except: [fn (Request $request): bool => $request->is(
            'api/endpoints/agent/remote/*/media/offer', 'it/remote-support/sessions/*/media/answer',
        )]);
        $middleware->prepend(TraceItTicketCreation::class);
        $middleware->prepend(\App\Http\Middleware\ProtectIncidentRecordingCommands::class);
        $middleware->prepend(ProtectItDraftResponses::class);
        $middleware->prepend(ProtectVendorVaultResponses::class);
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            EnforceSessionTimeout::class,
            EnsureAccountStillApproved::class,
            EnsureExternalClinicalIsolation::class,
            EnforceTwoFactorPolicy::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            PreventSearchIndexing::class,
            AddContentSecurityPolicy::class,
        ]);

        $middleware->api(prepend: [
            ThrottleRequests::class.':api',
        ]);

        $middleware->api(append: [EnsureExternalClinicalIsolation::class]);
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnsureExternalClinicalIsolation::class);

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
        $exceptions->render(fn (Throwable $exception, Request $request) => ProtectDocumentRequestResponses::render($exception, $request));
        $exceptions->report(fn (Throwable $exception) => ProtectDocumentRequestResponses::report($exception));
        $exceptions->render(fn (Throwable $exception, Request $request) => ProtectRemoteSupportResponses::render($exception, $request));
        $exceptions->report(fn (Throwable $exception) => ProtectRemoteSupportResponses::report($exception));
        $exceptions->render(fn (Throwable $exception, Request $request) => ProtectObliResponses::render($exception, $request));
        $exceptions->report(fn (Throwable $exception) => ProtectObliResponses::report($exception));
        $exceptions->render(fn (Throwable $exception, Request $request) => \App\Http\Middleware\ProtectIncidentRecordingCommands::render($exception, $request));
        $exceptions->render(fn (Throwable $exception, Request $request) => ProtectItDraftResponses::render($exception, $request));
        $exceptions->report(fn (Throwable $exception) => ProtectItDraftResponses::report($exception));
        $exceptions->render(fn (Throwable $exception, Request $request) => ProtectVendorVaultResponses::render($exception, $request));
        $exceptions->report(fn (Throwable $exception) => ProtectVendorVaultResponses::report($exception));
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if (ProtectObliResponses::applies($request)) {
                ProtectObliResponses::headers($response);
            }
            if ($request->is('api/endpoints/agent/*', 'security-devices/devices/*/endpoint/*')) {
                $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
                $response->headers->set('Pragma', 'no-cache');
            }
            // dontFlash only covers top-level keys; witness PINs posted inside
            // arrays (fleet transport medications) are removed here.
            ScrubNestedWitnessSecrets::fromFlashedInput($exception, $request);

            return ItTicketRequestTrace::from($request)?->finish($response, $exception) ?? $response;
        });

        $exceptions->dontFlash([
            'value',
            'totp_secret',
            'verification_code',
            'rotation_evidence',
            'secret_manager_reference',
            'credential_material',
            'code_verifier',
            'code',
            'credential',
            'enrolment_token',
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
            'witness_pin',
            'second_witness_credential',
            'witness_1_credential',
            'witness_2_credential',
            'waiver_approver_credential',
            // PIN-1 account settings › Witness PIN
            'current_pin',
            'pin',
            'pin_confirmation',
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
