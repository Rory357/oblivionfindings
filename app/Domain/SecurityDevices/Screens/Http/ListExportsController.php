<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Reports\ListExports;
use App\Domain\SecurityDevices\Reports\MonthlyReport;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Export" on the Devices list (the device list, or only the chosen devices, and the upkeep log)
 * and on the Alerts list (device events, last 90 days). The same CSVs, scope and key as today's
 * /security-devices/reports page, which they replace (plan v10, "What happens to today's 36 pages").
 */
final class ListExportsController extends Controller
{
    public function __construct(private readonly ListExports $exports) {}

    public function devices(Request $request): StreamedResponse
    {
        $user = $this->viewer($request);
        $ids = null;
        if ($request->has('ids')) {
            $validated = $request->validate(['ids' => ['required', 'string', 'regex:/^\d+(,\d+)*$/']]);
            $ids = collect(explode(',', $validated['ids']))->map(fn (string $id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->values()->all();
            abort_if(count($ids) > ListExports::MAX_IDS, 422, 'Select no more than '.ListExports::MAX_IDS.' devices per export.');
        }

        $this->audit($user, 'devices', ['ids' => $ids === null ? null : count($ids)]);

        return $this->streamSanitizedCsv('security-devices-inventory-'.now()->format('Y-m-d').'.csv', ListExports::DEVICE_COLUMNS, $this->exports->devices($user, $ids));
    }

    public function events(Request $request): StreamedResponse
    {
        $user = $this->viewer($request);
        $filters = $request->validate([
            'domain' => ['nullable', Rule::enum(DeviceDomain::class)],
            'device_id' => ['nullable', 'integer', 'min:1'],
            'severity' => ['nullable', 'string', 'max:50'],
            'event_type' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'max:100'],
        ]);

        $this->audit($user, 'events', array_filter($filters, fn (mixed $value): bool => $value !== null));

        return $this->streamSanitizedCsv(
            'security-devices-events-'.ListExports::EVENTS_WINDOW_DAYS.'d-'.now()->format('Y-m-d').'.csv',
            ListExports::EVENT_COLUMNS,
            $this->exports->events($user, $filters),
        );
    }

    public function upkeep(Request $request): StreamedResponse
    {
        $user = $this->viewer($request);
        $this->audit($user, 'upkeep', []);

        return $this->streamSanitizedCsv('security-devices-maintenance-'.now()->format('Y-m-d').'.csv', ListExports::UPKEEP_COLUMNS, $this->exports->upkeep($user));
    }

    /** @param  array<string, mixed>  $details */
    private function audit(User $user, string $export, array $details): void
    {
        AuditLogger::logOrFail('security_devices.export.downloaded', null, ['actor_id' => (int) $user->id, 'export' => $export, ...$details]);
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && MonthlyReport::canView($user), 403);

        return $user;
    }
}
