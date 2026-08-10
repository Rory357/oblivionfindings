<?php

namespace App\Domain\SecurityDevices\Management\Http\Middleware;

use App\Domain\SecurityDevices\Management\Services\DeviceCommandIntakeAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuditDeniedDeviceCommandRequest
{
    public function __construct(private readonly DeviceCommandIntakeAuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
            $newFlashData = $request->hasSession()
                ? (array) $request->session()->get('_flash.new', [])
                : [];
            $hasValidationErrors = in_array('errors', $newFlashData, true);
            if ($hasValidationErrors || $response->getStatusCode() >= 400) {
                try {
                    $this->audit->recordDeniedResponse(
                        $request,
                        $hasValidationErrors ? 422 : $response->getStatusCode(),
                    );
                } catch (Throwable $auditFailure) {
                    report($auditFailure);
                }
            }

            return $response;
        } catch (Throwable $exception) {
            try {
                $this->audit->recordDenied($request, $exception);
            } catch (Throwable $auditFailure) {
                report($auditFailure);
            }

            throw $exception;
        }
    }
}
