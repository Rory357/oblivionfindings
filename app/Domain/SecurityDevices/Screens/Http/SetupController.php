<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Services\DiscoveryRunner;
use App\Domain\SecurityDevices\Screens\Devices\DeviceFormOptions;
use App\Domain\SecurityDevices\Screens\Setup\SetupHistory;
use App\Domain\SecurityDevices\Screens\Setup\SetupPresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnexpectedValueException;

/** Setup (S&D rebuild drawing pg-sdr-15 and its sections). */
final class SetupController extends Controller
{
    public function __construct(
        private readonly SetupPresenter $setup,
        private readonly DeviceFormOptions $formOptions,
    ) {}

    public function show(Request $request, ?string $section = null): Response
    {
        $user = $this->viewer($request);

        return Inertia::render('security-devices/setup/index', [
            ...$this->setup->present($user, $section ?? 'overview', $request->query('tab'), ['page' => $request->query('page')]),
            // Add a network loads its Site and hub choices only when it opens.
            'deviceForm' => Inertia::optional(fn (): array => $this->formOptions->present($user)),
        ]);
    }

    /** Save the switches changed on a Setup tab, after "Review changes". */
    public function settings(Request $request, SecurityDevicesSettings $settings): JsonResponse
    {
        $user = $this->viewer($request);
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:20'],
        ]);

        try {
            $changed = $settings->update($user, $validated['changes']);
        } catch (AuthorizationException) {
            abort(403, 'You can’t change this setting.');
        }

        return response()->json([
            'changed' => $changed,
            'settings' => $settings->forViewer($user),
        ]);
    }

    /** Scan one network now (the Networks table's "Scan now"). */
    public function scan(Request $request, int $scope, SecurityDevicesAccessService $access, DiscoveryRunner $runner): JsonResponse
    {
        $user = $this->viewer($request);
        abort_unless($user->canDo('securityDevices.integrations.manage'), 403);
        $record = DiscoveryScope::query()->where('status', 'active')->findOrFail($scope);
        $access->assertCanViewSite($user, (int) $record->site_id);

        try {
            $run = DB::transaction(function () use ($runner, $record, $user) {
                $run = $runner->start($record, "manual:user:{$user->id}");
                AuditLogger::logOrFail('monitoring.discovery.scope.applied', $record, [
                    'actor_id' => (int) $user->id,
                    'site_id' => (int) $record->site_id,
                    'run_id' => (int) $run->id,
                    'run_uuid' => $run->run_uuid,
                    'run_status' => $run->status,
                    'planned_targets' => (int) $run->planned_targets,
                    'collection_mode' => $record->collector_id === null ? 'central_direct' : 'remote_collector',
                    ...($record->collector_id === null ? [] : ['collector_id' => (int) $record->collector_id]),
                ]);

                return $run;
            }, 3);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages([
                'scope' => str_starts_with($exception->getMessage(), 'The Oblivion server')
                    ? $exception->getMessage()
                    : 'This network can’t be scanned right now. Check that its hub is approved and answering, then try again.',
            ]);
        }

        return response()->json(['run_id' => (int) $run->id], 202);
    }

    /** Change history as a CSV file (newest first, up to 5,000 rows). */
    public function exportHistory(Request $request, SetupHistory $history): StreamedResponse
    {
        $user = $this->viewer($request);
        abort_unless(SetupPresenter::canSeeHistory($user), 403);
        $rows = $history->export($user);
        $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        AuditLogger::log('security_devices.setup.history_exported', null, ['actor_id' => (int) $user->id, 'rows' => count($rows)]);

        return response()->streamDownload(function () use ($rows, $zone): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['When (NZ time)', 'Who', 'What changed', 'Where']);
            foreach ($rows as $row) {
                $when = is_string($row['at']) ? CarbonImmutable::parse($row['at'])->setTimezone($zone)->format('Y-m-d H:i') : '';
                fputcsv($out, array_map(fn (string $cell): string => self::safeCell($cell), [$when, (string) $row['who'], (string) $row['what'], (string) $row['where']]));
            }
            fclose($out);
        }, 'security-devices-change-history.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A cell can't start a spreadsheet formula. */
    private static function safeCell(string $value): string
    {
        return preg_match('/\A[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && SetupPresenter::canOpen($user), 403);

        return $user;
    }
}
