<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class AuditExportController extends Controller
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function exportIncident(Request $request, ClientIncident $incident)
    {
        $user = $request->user();
        $this->authorizeEvidenceExport($request);
        $this->siteAccess->assertCanAccessClientIncident(
            $user,
            $incident,
            ['sites.viewAll', 'healthSafety.viewAllSites'],
        );
        $this->siteAccess->assertCanAccessClientId(
            $user,
            $incident->client_id ? (int) $incident->client_id : null,
            ['sites.viewAll'],
        );

        $incident->load(['client', 'reporter:id,name', 'attachments.uploader:id,name', 'followups.assignedTo:id,name', 'followups.creator:id,name']);

        $client = $incident->client;
        abort_unless($client, 404);

        $audit = AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->orderByDesc('created_at')
            ->limit(2000)
            ->get();

        $filename = 'audit_incident_'.$incident->id.'_'.now()->format('Ymd_His').'.zip';

        return $this->buildZipAndDownload($filename, function (ZipArchive $zip, string $tmpDir) use ($incident, $client, $audit) {
            $manifest = [
                'generated_at' => now()->toIso8601String(),
                'type' => 'incident',
                'incident_id' => $incident->id,
                'client_id' => $client->id,
                'client_name' => trim($client->first_name.' '.$client->last_name),
            ];

            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
            $zip->addFromString('incident.json', json_encode($incident->toArray(), JSON_PRETTY_PRINT));
            $zip->addFromString('followups.json', json_encode($incident->followups->toArray(), JSON_PRETTY_PRINT));
            $zip->addFromString('audit_logs.json', json_encode($audit->toArray(), JSON_PRETTY_PRINT));
            $zip->addFromString('attachments.json', json_encode($incident->attachments->toArray(), JSON_PRETTY_PRINT));

            foreach ($incident->attachments as $a) {
                $disk = $a->disk ?: 'public';
                if (! $a->path) {
                    continue;
                }
                try {
                    // Prefer local path when available.
                    $fullPath = Storage::disk($disk)->path($a->path);
                    if (is_file($fullPath)) {
                        $name = 'attachments/'.$a->id.'_'.preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $a->original_name);
                        $zip->addFile($fullPath, $name);
                    }
                } catch (\Throwable $e) {
                    // ignore missing/remote disks
                }
            }
        });
    }

    public function exportClient(Request $request, Client $client)
    {
        $user = $request->user();
        $this->authorizeEvidenceExport($request);
        $this->siteAccess->assertCanAccessClientId(
            $user,
            (int) $client->id,
            ['sites.viewAll'],
        );

        $client->load(['supportWorkers:id,name,email']);

        $incidents = $this->siteAccess
            ->applyClientIncidentScope(
                ClientIncident::query()->where('client_id', $client->id),
                $user,
                ['sites.viewAll', 'healthSafety.viewAllSites'],
            )
            ->with(['attachments', 'followups'])
            ->orderByDesc('created_at')
            ->limit(2000)
            ->get();

        $audit = AuditLog::query()
            ->where('client_id', $client->id)
            ->orderByDesc('created_at')
            ->limit(5000)
            ->get();

        $filename = 'audit_client_'.$client->id.'_'.now()->format('Ymd_His').'.zip';

        return $this->buildZipAndDownload($filename, function (ZipArchive $zip, string $tmpDir) use ($client, $incidents, $audit) {
            $manifest = [
                'generated_at' => now()->toIso8601String(),
                'type' => 'client',
                'client_id' => $client->id,
                'client_name' => trim($client->first_name.' '.$client->last_name),
            ];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
            $zip->addFromString('client.json', json_encode($client->toArray(), JSON_PRETTY_PRINT));
            $zip->addFromString('incidents.json', json_encode($incidents->toArray(), JSON_PRETTY_PRINT));
            $zip->addFromString('audit_logs.json', json_encode($audit->toArray(), JSON_PRETTY_PRINT));

            foreach ($incidents as $incident) {
                foreach ($incident->attachments as $a) {
                    $disk = $a->disk ?: 'public';
                    if (! $a->path) {
                        continue;
                    }
                    try {
                        $fullPath = Storage::disk($disk)->path($a->path);
                        if (is_file($fullPath)) {
                            $name = 'attachments/incident_'.$incident->id.'/'.$a->id.'_'.preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $a->original_name);
                            $zip->addFile($fullPath, $name);
                        }
                    } catch (\Throwable $e) {
                        // ignore
                    }
                }
            }
        });
    }

    protected function buildZipAndDownload(string $filename, callable $builder)
    {
        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        $zipPath = $tmpDir.DIRECTORY_SEPARATOR.$filename;
        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $builder($zip, $tmpDir);
        $zip->close();

        return response()->download($zipPath, $filename)->deleteFileAfterSend(true);
    }

    private function authorizeEvidenceExport(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user
                && $user->canDo('audit.viewAny')
                && $user->canDo('clients.viewAny')
                && $user->canDo('incidents.viewAny')
                && $user->canDo('incidents.export'),
            403,
        );
    }
}
