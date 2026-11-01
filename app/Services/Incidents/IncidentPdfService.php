<?php

namespace App\Services\Incidents;

use App\Models\ClientIncident;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Fleet\VehicleTripReportExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * G3 board M5c note (approved by Stephan 1 Nov 2026; answer 21): "The incident page gets the same button when the
 * incident closes." A closed incident's final record as a PDF, in the Fleet reports' style like the safety review's.
 * Built only from the incident page payload this person already sees (IncidentController::buildIncidentDetail), so the
 * PDF never shows more than the app does. Only whole-record viewers may download it, and every download is logged.
 */
final class IncidentPdfService
{
    private const NZ = 'Pacific/Auckland';

    /** The "Straight away" ticks, worded as the report form asks them (decision 18). */
    private const TICKS = [
        'made_safe' => 'Checked the person and made them safe',
        'first_aid' => 'First aid given',
        'medical_rung' => 'Nurse, GP or 111 rung',
        'manager_told' => 'Manager told',
        'family_told' => 'Family/whānau told',
    ];

    public function __construct(private readonly VehicleTripReportExporter $fleet) {}

    /** @param  array<string, mixed>  $detail */
    public function download(int $incidentId, array $detail, User $viewer): Response
    {
        $html = $this->html($detail, $viewer);
        $incident = ClientIncident::query()->select(['id'])->findOrFail($incidentId);
        AuditLogger::logOrFail('incidents.pdfDownloaded', $incident, ['actor_id' => $viewer->id]);
        $pdf = Pdf::setOption(['defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false])
            ->loadHTML($html)
            ->setPaper('a4', 'portrait');
        $response = $pdf->download('final-record-'.($detail['ref'] ?? 'incident-'.$incidentId).'.pdf');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** @param  array<string, mixed>  $detail */
    public function html(array $detail, User $viewer): string
    {
        $now = Carbon::now(self::NZ);
        $role = $viewer->roles()->first()?->name;
        $at = fn (mixed $value, string $format = 'D j M Y, g:i a'): ?string => filled($value)
            ? Carbon::parse($value)->timezone(self::NZ)->format($format)
            : null;
        $client = $detail['client'] ?? null;
        $person = $client ? trim(($client['first_name'] ?? '').' '.($client['last_name'] ?? '')) : null;
        $owner = $detail['ownership']['owner']['name'] ?? null;
        $ticks = collect($detail['immediate_response'] ?? [])->map(fn (array $row): array => [
            'label' => self::TICKS[$row['key']] ?? str_replace('_', ' ', (string) $row['key']),
            'line' => ($row['done'] ?? false)
                ? 'Done'.(($row['at'] ?? null) ? ' '.$at($row['at'], 'g:i a') : '').(($row['detail'] ?? null) ? ' — '.$row['detail'] : '')
                : 'Not yet'.(($row['detail'] ?? null) ? ' — '.$row['detail'] : ''),
        ])->all();

        return view('pdf.incident-record', [
            'title' => 'Incident '.($detail['ref'] ?? ''),
            'brand' => $this->fleet->branding(),
            'd' => $detail,
            'person' => $person ?: null,
            'site' => $client['site'] ?? null,
            'occurred' => $at($detail['occurred_at'] ?? null),
            'closedLine' => 'Closed '.($at($detail['closed_at'] ?? null) ?? '').($owner ? ', owner '.$owner : ''),
            'steps' => [
                ['Reported', $at($detail['submitted_at'] ?? null, 'D j M') ?? '—', $detail['reporter']['name'] ?? '—'],
                ['Assigned', $owner ? 'Owner' : '—', $owner ?? '—'],
                ['Manager review', $at($detail['reviewed_at'] ?? null, 'D j M') ?? '—', $detail['investigator'] ?? '—'],
                ['Closed', $at($detail['closed_at'] ?? null, 'D j M') ?? '—', $owner ?? '—'],
            ],
            'ticks' => $ticks,
            'notes' => collect($detail['notes'] ?? [])->map(fn (array $note): array => [
                'note' => $note['note'] ?? '',
                'who' => $note['author_name'] ?? '—',
                'when' => $at($note['created_at'] ?? null, 'D j M, g:i a') ?? '',
            ])->all(),
            'followups' => collect($detail['followups'] ?? [])->map(fn (array $f): array => [
                'notes' => $f['notes'] ?? '',
                'who' => $f['assigned_to'] ?? '—',
                'due' => $at($f['due_at'] ?? null, 'j M') ?? '—',
                'done' => $at($f['completed_at'] ?? null, 'j M'),
            ])->all(),
            'footer' => 'Confidential — contains health information. Downloaded by '.$viewer->name
                .($role ? ', '.str_replace('_', ' ', $role) : '').' · '.$now->format('D j M Y, g:i a').' · NZ time · final record',
        ])->render();
    }
}
