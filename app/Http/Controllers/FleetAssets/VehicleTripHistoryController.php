<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Fleet\VehicleTripHistoryService;
use App\Services\Fleet\VehicleTripReportExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * PKG-02B vehicle trip history: the filtered trip list, one trip's recorded
 * journey and behaviour, driver confirmation and PDF / Excel exports.
 * Every endpoint resolves the vehicle through VehicleTripHistoryService, so
 * a vehicle or trip outside the viewer's scope answers 404.
 */
class VehicleTripHistoryController extends Controller
{
    public function __construct(
        private readonly VehicleTripHistoryService $trips,
        private readonly VehicleTripReportExporter $exporter,
    ) {}

    public function index(Request $request, int $asset): JsonResponse
    {
        $user = $this->actor($request);
        $vehicle = $this->trips->vehicle($user, $asset);
        $data = $request->validate($this->filterRules($request) + [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.VehicleTripHistoryService::MAX_PAGE_SIZE],
            'summary_only' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->trips->list(
            $user,
            $vehicle,
            $this->trips->filters($data),
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? VehicleTripHistoryService::DEFAULT_PAGE_SIZE),
            $request->boolean('summary_only'),
        ));
    }

    public function show(Request $request, int $asset, int $trip): JsonResponse
    {
        $user = $this->actor($request);

        return response()->json($this->trips->detail($user, $this->trips->vehicle($user, $asset), $trip));
    }

    public function confirmDriver(Request $request, int $asset, int $trip): JsonResponse
    {
        $key = (string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: '');

        return response()->json($this->trips->confirmDriver(
            $this->actor($request),
            $asset,
            $trip,
            $request->only(['driver_user_id', 'reason', 'verified', 'expected_version']),
            $key,
        ));
    }

    public function export(Request $request, int $asset, string $format): Response
    {
        $user = $this->actor($request);
        $vehicle = $this->trips->vehicle($user, $asset);
        $data = $request->validate([
            ...$this->filterRules($request),
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'events' => ['nullable', 'boolean'],
            'maps' => ['nullable', 'boolean'],
        ], [
            'from.required' => 'Choose the first day to export.',
            'to.required' => 'Choose the last day to export.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ]);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $data['from']);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $data['to']);
        if ($from->diffInDays($to) > VehicleTripHistoryService::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['to' => 'Export at most one year of trips at a time.']);
        }

        $pdf = $format === 'pdf';
        $filters = $this->trips->filters($data);
        $report = $this->trips->exportReport(
            $user,
            $vehicle,
            $filters,
            $pdf ? VehicleTripHistoryService::PDF_TRIP_LIMIT : VehicleTripHistoryService::SPREADSHEET_TRIP_LIMIT,
            $request->boolean('events', true),
            $pdf && $request->boolean('maps', true),
        );
        AuditLogger::log('fleet.trip_history.exported', $vehicle, [
            'asset_id' => $vehicle->id,
            'format' => $pdf ? 'pdf' : 'spreadsheet',
            'from' => $filters['from'],
            'to' => $filters['to'],
            'driver' => $filters['driver'],
            'event' => $filters['event'],
            'searched' => $filters['q'] !== '',
            'trips' => $report['totals']['trips'],
            'excluded_personal' => $report['excluded']['personal'],
            'excluded_without_consent' => $report['excluded']['restricted'],
        ]);

        return $pdf
            ? $this->exporter->pdf($report, (string) $user->name)
            : $this->exporter->spreadsheet($report, (string) $user->name);
    }

    /** @return array<string, list<string>> */
    private function filterRules(Request $request): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            // Only compare with a start date that was actually given.
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'driver' => ['nullable', 'string', 'max:20'],
            'event' => ['nullable', 'in:'.implode(',', VehicleTripHistoryService::EVENTS)],
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
