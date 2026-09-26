<?php

namespace App\Services\Fleet;

use App\Models\AppSetting;
use App\Services\Maps\OsmReportMap;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders a vehicle trip report (VehicleTripHistoryService::exportReport) as
 * a branded PDF (dompdf with the embedded DejaVu Sans font, so macrons such
 * as "Kōwhai" print) or a native Excel workbook with embedded journey images. Text cells
 * are literal strings, never user-provided formulas. Street maps render from
 * installed public OSM data locally; no trip coordinates leave the server.
 */
final class VehicleTripReportExporter
{
    private const DEFAULT_COLOUR = '#7c3aed';

    private array $routeImageSources = [];

    public function routeImageSources(): array
    {
        return $this->routeImageSources;
    }

    /** @param  array<string,mixed>  $report */
    public function pdf(array $report, string $generatedBy): Response
    {
        // DejaVu Sans ships with dompdf and carries macrons; embed only the
        // glyphs used so the file stays small.
        $pdf = Pdf::setOption(['defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true])
            ->loadHTML($this->html($report, $generatedBy))
            ->setPaper('a4', 'portrait');

        $response = $pdf->download($this->filename($report, 'pdf'));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** @param  array<string,mixed>  $report */
    public function html(array $report, string $generatedBy): string
    {
        return view('pdf.vehicle-trip-report', $this->viewData($report, $generatedBy))->render();
    }

    /** @param  array<string,mixed>  $report */
    public function spreadsheet(array $report, string $generatedBy): Response
    {
        return response(app(VehicleTripWorkbook::class)->bytes($this->viewData($report, $generatedBy)), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($report, 'xlsx').'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * A filesystem-safe download name: vehicle, "trips" and the date range.
     *
     * @param  array<string,mixed>  $report
     */
    public function filename(array $report, string $extension): string
    {
        $vehicle = $report['vehicle'];
        $slug = Str::slug((string) ($vehicle['registration_number'] ?: $vehicle['asset_tag'] ?: $vehicle['name']));
        $slug = substr(preg_replace('/[^a-z0-9-]/', '', $slug) ?: '', 0, 40) ?: 'vehicle-'.$vehicle['id'];
        $from = (string) ($report['filters']['from'] ?? '');
        $to = (string) ($report['filters']['to'] ?? '');

        return $slug.'-trips-'.preg_replace('/[^0-9-]/', '', $from).'-to-'.preg_replace('/[^0-9-]/', '', $to).'.'.$extension;
    }

    /**
     * Strings and labels shared by both formats.
     *
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    public function viewData(array $report, string $generatedBy): array
    {
        $this->routeImageSources = [];
        $zone = VehicleTripHistoryService::zone();
        $brand = $this->branding();
        $policy = $report['policy'];
        $vehicle = $report['vehicle'];
        $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $report['filters']['from'], $zone);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) $report['filters']['to'], $zone);
        $rangeLabel = $from->format('j M Y') === $to->format('j M Y')
            ? $from->format('j M Y')
            : $from->format('j M Y').' – '.$to->format('j M Y');
        $vehicleLine = implode(' · ', array_filter([
            $vehicle['name'], $vehicle['registration_number'], $vehicle['asset_tag'], $vehicle['site'] ?? null,
        ]));
        $eventLabels = ['all' => 'All types', 'overspeed' => 'Overspeed', 'faults' => 'Vehicle faults', 'partial' => 'Partial coverage'];
        $filters = $report['filters'];
        $filtersLine = 'Filters: '.$report['driver_label'].' · '.($eventLabels[$filters['event']] ?? 'All types')
            .($filters['q'] !== '' ? ' · search “'.$filters['q'].'”' : '');
        $excluded = $report['excluded'];
        $scopeNote = 'Business trips only, starting between these dates (inclusive).'
            .($excluded['personal'] > 0 ? ' '.$excluded['personal'].' personal '.Str::plural('trip', $excluded['personal']).' left out.' : '')
            .($excluded['restricted'] > 0 ? ' '.$excluded['restricted'].' '.Str::plural('trip', $excluded['restricted']).' without tracking consent left out.' : '');
        $weights = $policy['weights'];
        $number = fn (float $value): string => rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        $trips = [];
        foreach ($report['trips'] as $trip) {
            $start = $trip['started_at'] ? CarbonImmutable::parse($trip['started_at'])->setTimezone($zone) : null;
            $end = $trip['ended_at'] ? CarbonImmutable::parse($trip['ended_at'])->setTimezone($zone) : null;
            $behaviour = $trip['behaviour'];
            $driver = $trip['driver'];
            $partial = (bool) $behaviour['partial'];
            $map = $report['include_routes'] ? app(OsmReportMap::class)->render($trip['points'], $brand['colour'], $partial) : null;
            if ($map) {
                $sourceKey = $map['kind'].':'.($map['dataset']['sha256'] ?? 'none');
                $this->routeImageSources[$sourceKey] ??= ['kind' => $map['kind'], 'dataset' => $map['dataset'], 'trips' => 0];
                $this->routeImageSources[$sourceKey]['trips']++;
            }
            $trips[] = [
                'reference' => $trip['reference'],
                'date_iso' => $start?->format('Y-m-d'),
                'date_label' => $start?->format('D j M Y') ?? 'Not recorded',
                'start_time' => $start ? self::time($start) : '',
                'end_time' => $end ? self::time($end) : 'In progress',
                'from' => $trip['from'] ?? 'Start position (no address)',
                'to' => $trip['to'] ?? ($trip['in_progress'] ? 'Trip in progress' : 'End position (no address)'),
                'distance_km' => (float) $trip['distance_km'],
                'duration_seconds' => (int) $trip['duration_s'],
                'minutes' => (int) round($trip['duration_s'] / 60),
                'max_speed_kph' => $behaviour['max_speed_kph'],
                'coverage_pct' => $behaviour['coverage_pct'],
                'partial' => $partial,
                'driving_events' => (int) $behaviour['driving_events'],
                'harsh' => $behaviour['harsh'],
                'overspeed_episodes' => (int) $behaviour['overspeed_episodes'],
                'overspeed_seconds' => $behaviour['overspeed_seconds'],
                'idle_minutes' => $behaviour['idle_minutes'],
                'score' => $behaviour['score'],
                'score_label' => self::scoreLabel($behaviour['score_state'], $behaviour['score'], (int) $policy['min_score_coverage_pct']),
                'driver_name' => $driver['name'] ?? match ($driver['state']) {
                    'hidden' => 'Not shown at your sites',
                    default => 'Not recorded',
                },
                'driver_status' => self::driverStatus($driver),
                'source_note' => implode(' · ', array_filter([
                    $trip['vendors'] !== [] ? 'Tracker: '.implode(', ', array_map('ucfirst', $trip['vendors'])) : 'No tracker positions',
                    $trip['booking_reference'] ? 'Booking '.$trip['booking_reference'] : null,
                ])),
                'events' => array_map(fn (array $event): array => [
                    'time' => self::time(CarbonImmutable::createFromTimestampUTC((int) $event['at'])->setTimezone($zone)),
                    'title' => $event['title'],
                    'detail' => $event['detail'],
                    'location' => $event['location']
                        ?? ($event['lat'] !== null ? number_format((float) $event['lat'], 5).', '.number_format((float) $event['lng'], 5) : 'Not recorded'),
                ], $trip['events']),
                'route' => $map ? ($map['image'] ?? $this->routeSketch($trip['points'], $brand['colour'], $partial)) : null,
                'map_kind' => $map['kind'] ?? 'none',
                'map_note' => $map['note'] ?? '',
            ];
        }

        $totals = $report['totals'];
        $minutes = (int) round($totals['duration_s'] / 60);

        return [
            'title' => 'Trip report · '.$vehicle['name'].' · '.$rangeLabel,
            'brand' => $brand,
            'vehicle_line' => $vehicleLine,
            'range_label' => $rangeLabel,
            'timezone' => $zone,
            'filters_line' => $filtersLine,
            'scope_note' => $scopeNote,
            'generated_label' => 'Generated '.self::time(CarbonImmutable::now($zone), true).' by '.$generatedBy,
            'totals' => [
                'trips' => (int) $totals['trips'],
                'distance_km' => round((float) $totals['distance_km'], 1),
                'duration_seconds' => (int) $totals['duration_s'],
                'minutes' => $minutes,
                'duration_label' => intdiv($minutes, 60) > 0 ? intdiv($minutes, 60).' hr '.($minutes % 60).' min' : $minutes.' min',
                'driving_events' => (int) $totals['driving_events'],
            ],
            'notes' => [
                'Distance is the tracker\'s GPS estimate, not a dashboard odometer reading. Odometer readings keep their own evidence.',
                'Coverage is the share of each trip with a recorded position at least every '
                    .$number($policy['coverage_gap_seconds'] / 60).' min. Lines between recorded positions are not verified roads.',
                'Behaviour score: 100 − '.$number($weights['braking']).' × harsh braking − '.$number($weights['acceleration'])
                    .' × harsh acceleration − '.$number($weights['other_harsh']).' × other harsh events − '.$number($weights['overspeed'])
                    .' × overspeed episodes − '.$number($weights['idle_per_minute']).' × idle minutes, from the fleet settings.'
                    .' Scores are withheld below '.$policy['min_score_coverage_pct'].'% coverage.',
                'Human review applies: dismissed events do not deduct points and disputed events withhold the score. Event counts retain the original recorded observations.',
                'Overspeed uses the fleet threshold of '.$number($policy['speed_threshold_kph']).' km/h; road speed limits are not checked.',
                'A booked driver is not proof of who drove. Drivers are shown as confirmed only after someone confirms them.',
            ],
            'trips' => $trips,
            'include_events' => (bool) $report['include_events'],
            'include_routes' => (bool) $report['include_routes'],
            'has_street_maps' => count(array_filter($trips, fn ($trip) => $trip['map_kind'] === 'local_osm_street_map')) > 0,
        ];
    }

    /** @return array{name:string,colour:string,tint:string,logo:?string} */
    private function branding(): array
    {
        $settings = AppSetting::query()
            ->whereIn('key', ['branding.name', 'branding.email_header_colour', 'theme.light', 'branding.logo_path'])
            ->pluck('value', 'key');
        $name = $settings['branding.name'] ?? null;
        $theme = $settings['theme.light'] ?? null;
        $colour = self::hex($settings['branding.email_header_colour'] ?? null)
            ?? self::hex(is_array($theme) ? ($theme['--primary'] ?? null) : null)
            ?? self::DEFAULT_COLOUR;

        return [
            'name' => is_string($name) && trim($name) !== '' ? trim($name) : (string) config('app.name'),
            'colour' => $colour,
            'tint' => self::mix($colour, 0.92),
            'logo' => $this->logo($settings['branding.logo_path'] ?? null),
        ];
    }

    /** A small PNG or JPEG logo from the public disk, embedded as data. */
    private function logo(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return null;
        }
        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($path) || $disk->size($path) > 1024 * 1024) {
                return null;
            }
            $bytes = $disk->get($path);
            $mime = match (true) {
                str_starts_with((string) $bytes, "\x89PNG") => 'image/png',
                str_starts_with((string) $bytes, "\xFF\xD8\xFF") => 'image/jpeg',
                default => null,
            };

            return $mime ? 'data:'.$mime.';base64,'.base64_encode((string) $bytes) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A plain sketch of the recorded positions joined in order, with the
     * start (A) and end (B). It is not a map and shows no road network.
     *
     * @param  list<array{lat:float,lng:float}>  $points
     */
    private function routeSketch(array $points, string $colour, bool $partial): ?string
    {
        if (count($points) < 2) {
            return null;
        }
        $project = function (float $lat, float $lng): array {
            $lat = max(-85.0, min(85.0, $lat));
            $sin = sin(deg2rad($lat));

            return [($lng + 180) / 360, 0.5 - log((1 + $sin) / (1 - $sin)) / (4 * M_PI)];
        };
        $projected = array_map(fn (array $point): array => $project((float) $point['lat'], (float) $point['lng']), $points);
        $xs = array_column($projected, 0);
        $ys = array_column($projected, 1);
        $width = 520;
        $height = 220;
        $padding = 22;
        $spanX = max(max($xs) - min($xs), 1e-9);
        $spanY = max(max($ys) - min($ys), 1e-9);
        $scale = min(($width - 2 * $padding) / $spanX, ($height - 2 * $padding) / $spanY);
        $offsetX = ($width - $spanX * $scale) / 2;
        $offsetY = ($height - $spanY * $scale) / 2;
        $coordinates = array_map(fn (array $point): array => [
            round($offsetX + ($point[0] - min($xs)) * $scale, 1),
            round($offsetY + ($point[1] - min($ys)) * $scale, 1),
        ], $projected);
        $line = implode(' ', array_map(fn (array $point): string => $point[0].','.$point[1], $coordinates));
        [$ax, $ay] = $coordinates[0];
        [$bx, $by] = $coordinates[count($coordinates) - 1];
        $dash = $partial ? ' stroke-dasharray="8,6"' : '';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'">'
            .'<rect x="0" y="0" width="'.$width.'" height="'.$height.'" fill="#f3f3f5" stroke="#dcdae3" stroke-width="1"/>'
            .'<polyline points="'.$line.'" fill="none" stroke="'.$colour.'" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"'.$dash.'/>'
            .'<circle cx="'.$ax.'" cy="'.$ay.'" r="8" fill="#15803d" stroke="#ffffff" stroke-width="2"/>'
            .'<circle cx="'.$bx.'" cy="'.$by.'" r="8" fill="#b91c1c" stroke="#ffffff" stroke-width="2"/>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private static function time(CarbonImmutable $at, bool $withDate = false): string
    {
        return ($withDate ? $at->format('j M Y').', ' : '').$at->format('g:i a');
    }

    private static function scoreLabel(string $state, ?int $score, int $minCoverage): string
    {
        return match ($state) {
            'scored' => $score.'/100',
            'coverage' => "Withheld · coverage below {$minCoverage}%",
            'no_samples' => 'Withheld · too few recorded positions',
            'in_progress' => 'Withheld · trip in progress',
            'personal' => 'Not scored · personal trip',
            'consent' => 'Not scored · tracking consent not in place',
            'disputed' => 'Withheld · driving event disputed',
            default => 'Withheld',
        };
    }

    /** @param  array<string,mixed>  $driver */
    private static function driverStatus(array $driver): string
    {
        return match ($driver['state']) {
            'confirmed' => match ($driver['attribution']) {
                'handover' => 'Confirmed · handover from the booking',
                'booking' => 'Confirmed · booked driver',
                default => 'Confirmed',
            },
            'recorded' => $driver['source'] === 'session'
                ? 'Signed in · actual driver unconfirmed'
                : 'Booked · actual driver unconfirmed',
            'hidden' => 'Recorded · name restricted to their sites',
            default => 'No driver recorded',
        };
    }

    private static function hex(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^#([0-9a-f]{3})$/i', $value, $short) === 1) {
            return '#'.strtolower($short[1][0].$short[1][0].$short[1][1].$short[1][1].$short[1][2].$short[1][2]);
        }

        return preg_match('/^#[0-9a-f]{6}$/i', $value) === 1 ? strtolower($value) : null;
    }

    /** The colour blended towards white by $amount (0–1). */
    private static function mix(string $hex, float $amount): string
    {
        $channels = array_map(fn (string $pair): int => (int) hexdec($pair), str_split(substr($hex, 1), 2));

        return '#'.implode('', array_map(
            fn (int $channel): string => str_pad(dechex((int) round($channel + (255 - $channel) * $amount)), 2, '0', STR_PAD_LEFT),
            $channels,
        ));
    }
}
