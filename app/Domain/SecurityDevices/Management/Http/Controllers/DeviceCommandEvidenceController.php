<?php

namespace App\Domain\SecurityDevices\Management\Http\Controllers;

use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandAuditService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandEvidencePresenter;
use App\Domain\SecurityDevices\Models\Device;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DeviceCommandEvidenceController extends Controller
{
    public function __invoke(
        Request $request,
        Device $device,
        DeviceCommandRequest $command,
        DeviceCommandEvidencePresenter $presenter,
        DeviceCommandAuditService $audit,
    ): StreamedResponse {
        $presenter->assertCanView($request->user(), $device, $command);
        $audit->append($command, $request->user(), 'evidence_exported', [
            'format' => 'json',
            'schema_version' => 1,
        ]);
        $payload = $presenter->present($request->user(), $device, $command->fresh());
        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            'device-command-'.$command->command_uuid.'-evidence.json',
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
