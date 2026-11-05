<?php

namespace App\Domain\SecurityDevices\Screens\Sites;

use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\VehicleTripReportExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Site › Logbook export (pg-sdr-12i): a day or a date range as PDF or CSV, for one Site the viewer
 * may open. Reads through SiteStream so Site custody, resident care-plan privacy ("a person") and
 * door-name rules match the screen line for line.
 */
final class SiteLogbookExport
{
    public const int MAX_DAYS = 90;

    public const int MAX_ROWS = 2000;

    public const array CSV_COLUMNS = ['When (NZ time)', 'Kind', 'What happened', 'Details', 'Who'];

    public function __construct(
        private readonly DeviceDirectory $directory,
        private readonly SiteStream $stream,
        private readonly VehicleTripReportExporter $fleet,
    ) {}

    /**
     * @return array{from_day: string, to_day: string, start: CarbonImmutable, end: CarbonImmutable, label: string, slug: string, single_day: bool}
     */
    public function parseRange(string $fromInput, ?string $toInput, CarbonImmutable $now): array
    {
        $zone = SiteStream::zone();
        $today = $now->setTimezone($zone)->startOfDay();
        $oldest = $today->subYear();

        $from = $this->parseDay($fromInput, $zone, 'from');
        $to = $toInput !== null && $toInput !== '' ? $this->parseDay($toInput, $zone, 'to') : $from;

        if ($to->lt($from)) {
            throw ValidationException::withMessages([
                'to' => ['The end date must be on or after the start date.'],
            ]);
        }

        if ($to->gt($today)) {
            throw ValidationException::withMessages([
                'to' => ['Pick today or an earlier date.'],
            ]);
        }

        if ($from->lt($oldest)) {
            throw ValidationException::withMessages([
                'from' => ['Pick a date within the last year.'],
            ]);
        }

        $days = (int) $from->diffInDays($to) + 1;
        if ($days > self::MAX_DAYS) {
            throw ValidationException::withMessages([
                'to' => ['Export up to '.self::MAX_DAYS.' days at a time.'],
            ]);
        }

        $single = $from->isSameDay($to);
        $start = $from->startOfDay()->utc();
        $end = $to->isSameDay($today) ? $now : $to->addDay()->startOfDay()->utc();

        return [
            'from_day' => $from->format('Y-m-d'),
            'to_day' => $to->format('Y-m-d'),
            'start' => $start,
            'end' => $end,
            'label' => $single ? $from->format('D j M Y') : $from->format('D j M Y').' – '.$to->format('D j M Y'),
            'slug' => $single ? $from->format('Y-m-d') : $from->format('Y-m-d').'-to-'.$to->format('Y-m-d'),
            'single_day' => $single,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lines(User $viewer, Site $site, CarbonImmutable $start, CarbonImmutable $end, string $what): array
    {
        $entries = $this->directory->entries($viewer, (int) $site->id)
            ->sortBy(fn (DeviceEntry $entry): string => $entry->sortKey())
            ->values();

        return collect($this->stream->between($viewer, $entries, [(int) $site->id => (string) $site->name], $start, $end, true, self::MAX_ROWS))
            ->filter(fn (array $line): bool => SitePagePresenter::matchesLogbookWhat($line, $what))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<list<string>>
     */
    public function csvRows(array $lines): array
    {
        $zone = SiteStream::zone();

        return array_map(function (array $line) use ($zone): array {
            $when = isset($line['at']) && is_string($line['at'])
                ? CarbonImmutable::parse($line['at'])->setTimezone($zone)->format('Y-m-d H:i')
                : '';

            return [
                $when,
                self::kindLabel($line),
                (string) ($line['title'] ?? ''),
                (string) ($line['sub'] ?? ''),
                (string) ($line['by'] ?? ''),
            ];
        }, $lines);
    }

    /**
     * @param  array{from_day: string, to_day: string, start: CarbonImmutable, end: CarbonImmutable, label: string, slug: string, single_day: bool}  $range
     * @param  list<array<string, mixed>>  $lines
     */
    public function pdfOutput(Site $site, array $range, string $what, string $reason, array $lines, User $viewer, CarbonImmutable $now): string
    {
        return Pdf::setOption(['defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false])
            ->loadHTML($this->html($site, $range, $what, $reason, $lines, $viewer, $now))
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * @param  array{from_day: string, to_day: string, start: CarbonImmutable, end: CarbonImmutable, label: string, slug: string, single_day: bool}  $range
     * @param  list<array<string, mixed>>  $lines
     */
    public function html(Site $site, array $range, string $what, string $reason, array $lines, User $viewer, CarbonImmutable $now): string
    {
        $zone = SiteStream::zone();
        $nzNow = $now->setTimezone($zone);
        $singleDay = $range['single_day'];

        $rows = array_map(function (array $line) use ($zone, $singleDay): array {
            $when = isset($line['at']) && is_string($line['at'])
                ? CarbonImmutable::parse($line['at'])->setTimezone($zone)->format($singleDay ? 'g:i a' : 'D j M, g:i a')
                : '—';

            return [
                $when,
                self::kindLabel($line),
                (string) ($line['title'] ?? ''),
                (string) ($line['sub'] ?? ''),
                (string) ($line['by'] ?? ''),
            ];
        }, $lines);

        $count = count($rows);

        return view('pdf.security-devices-site-logbook', [
            'title' => $site->name.' logbook · '.$range['label'],
            'brand' => $this->fleet->branding(),
            'siteName' => (string) $site->name,
            'rangeLabel' => $range['label'],
            'whatLabel' => SitePagePresenter::LOGBOOK_WHAT_LABELS[$what] ?? 'Everything',
            'countLabel' => $count === 1 ? '1 entry' : number_format($count).' entries',
            'exportedBy' => (string) $viewer->name,
            'reason' => $reason,
            'rows' => $rows,
            'capped' => $count >= self::MAX_ROWS,
            'footer' => 'Confidential · Exported by '.$viewer->name.' · '.$nzNow->format('D j M Y, g:i a').' · NZ time',
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function kindLabel(array $line): string
    {
        return match ($line['kind'] ?? null) {
            'alert' => 'Alert',
            'hs' => 'H&S',
            'alarm' => 'Alarm',
            'mode' => 'House mode',
            'door' => 'Door',
            'planned' => 'Planned work',
            default => ($line['device_id'] ?? null) === null ? 'Scan' : 'Device',
        };
    }

    private function parseDay(string $value, string $zone, string $field): CarbonImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            throw ValidationException::withMessages([
                $field => ['Pick a real calendar date.'],
            ]);
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value, $zone);
        } catch (\Throwable) {
            $parsed = false;
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([
                $field => ['Pick a real calendar date.'],
            ]);
        }

        return $parsed;
    }
}
