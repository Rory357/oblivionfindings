{{-- G3 board M5c: a safety review's final record (closed) or summary (open), in the Fleet reports' style. Rendered by
     HsReviewPdfService with dompdf; DejaVu Sans carries macrons. Built only from the page payload this person sees. --}}
@php
    $stepLabel = ['assigned' => 'Assigned', 'investigated' => 'Investigated', 'implemented' => 'Implemented', 'check' => 'Check it worked', 'closed' => 'Closed'];
    $agreed = collect($d['investigations'] ?? [])->firstWhere('status', 'completed');
    $story = $d['handover_summary']['narrative'] ?? $d['description'] ?? null;
    $straightAway = $d['handover_summary']['immediate_controls'] ?? null;
    $ws = $d['worksafe'] ?? [];
    $nz = 'Pacific/Auckland';
@endphp
<!DOCTYPE html>
<html lang="en-NZ">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('pdf.report-styles')
    <style>
        .status { display: inline-block; padding: .6mm 2.2mm; border-radius: 3mm; font-size: 7.5pt; font-weight: bold; background: {{ $brand['tint'] }}; color: {{ $brand['colour'] }}; }
        .track td { width: 20%; text-align: center; font-size: 8pt; padding: 1mm; }
        .tick { font-weight: bold; color: {{ $brand['colour'] }}; }
        .section { margin-top: 6mm; }
        .para { margin: 0 0 2mm; }
        .label { font-weight: bold; }
        .photos td { width: 33%; vertical-align: top; padding: 1.5mm; }
        .photos img { width: 100%; }
        .small { font-size: 7.5pt; color: #625d73; }
        .new-page { page-break-before: always; }
        .before-after p { margin: 0 0 1.2mm; padding: 1.2mm 2mm; background: #f4f3f8; }
    </style>
</head>
<body>
<div class="page-head">
    <div class="bar"></div>
    <table>
        <tr>
            <td style="width: 13mm;">
                @if ($brand['logo'])
                    <img class="logo" src="{{ $brand['logo'] }}" alt="">
                @else
                    <div class="ring"></div>
                @endif
            </td>
            <td>
                <div class="org">{{ $brand['name'] }}</div>
                <div class="crumb">Health and safety · Safety review · {{ $final ? 'final record' : 'summary — in progress, not final' }}</div>
            </td>
            <td style="text-align: right;" class="crumb">{{ $d['reference_number'] }}<br>{{ $d['site']['name'] ?? '' }}</td>
        </tr>
    </table>
</div>
<div class="page-foot">
    <table>
        <tr>
            <td>{{ $footer }}</td>
            <td style="text-align: right; width: 18mm;"><span class="page-number"></span></td>
        </tr>
    </table>
</div>

<h1>{{ $d['title'] ?? $d['reference_number'] }}</h1>
<div class="muted">
    Safety review {{ $d['reference_number'] }}@if (!empty($d['site']['name'])) · {{ $d['site']['name'] }}@endif
    @if (!empty($d['source']['label'])) · from {{ \Illuminate\Support\Str::lcfirst($d['source']['label']) }}@endif
</div>
<div style="margin-top: 2mm;"><span class="status">{{ $closedLine }}</span></div>

<table class="kpis">
    <tr>
        @foreach ($tiles as [$label, $value, $sub])
            <td><div class="kpi-label">{{ $label }}</div><div class="kpi-value">{{ $value }}</div><div class="small">{{ $sub }}</div></td>
        @endforeach
    </tr>
</table>

<h3>Steps taken</h3>
<table class="track">
    <tr>
        @foreach ($steps as $step)
            <td><span class="tick">{{ ($final || $step['when']) ? '✓' : '○' }}</span> {{ $step['label'] }}</td>
        @endforeach
    </tr>
</table>
<table class="data">
    <thead><tr><th>Step</th><th>When</th><th>Who</th><th>What was done</th></tr></thead>
    <tbody>
    @foreach ($steps as $step)
        <tr><td>{{ $step['label'] }}</td><td>{{ $step['when'] ?? '—' }}</td><td>{{ $step['who'] ?: '—' }}</td><td>{{ $step['what'] }}</td></tr>
    @endforeach
    </tbody>
</table>

<h3>About the event</h3>
<table class="facts">
    <tr>
        <td><div class="fact-label">Person</div><div class="fact-value">{{ $d['client']['name'] ?? $d['staff']['name'] ?? '—' }}</div></td>
        <td><div class="fact-label">Severity</div><div class="fact-value">{{ ucfirst($d['severity'] ?? '') }}</div></td>
        <td><div class="fact-label">WorkSafe</div><div class="fact-value">{{ !empty($ws['decision_signed']) ? ($ws['notifiable'] ? 'Notifiable' : 'Not notifiable') : 'Not decided' }}</div>
            @if (!empty($ws['decided_by']['name']))<div class="small">{{ $ws['decided_by']['name'] }}@if (!empty($ws['decided_at'])), {{ \Illuminate\Support\Carbon::parse($ws['decided_at'])->timezone($nz)->format('j M') }}@endif</div>@endif</td>
        <td><div class="fact-label">Owner</div><div class="fact-value">{{ $d['handover']['owner']['name'] ?? 'No owner' }}</div></td>
    </tr>
</table>

<div class="section">
    <h2>What happened</h2>
    @if ($story)<p class="para">{{ $story }}</p>@else<p class="para muted">Not recorded on this review.</p>@endif
    @if ($straightAway)<p class="para"><span class="label">What we did straight away:</span> {{ $straightAway }}</p>@endif
</div>

<div class="section">
    <h2>Investigation</h2>
    @if ($agreed)
        <p class="para"><span class="label">Findings</span>
            ({{ collect([$agreed['submitted_by_name'] ? 'submitted by '.$agreed['submitted_by_name'] : null, $agreed['reviewed_by_name'] ? 'reviewed by '.$agreed['reviewed_by_name'] : null, $agreed['approved_by_name'] ? 'approved by '.$agreed['approved_by_name'] : null])->filter()->implode(', ') }}@if (!empty($agreed['completed_at'])), {{ \Illuminate\Support\Carbon::parse($agreed['completed_at'])->timezone($nz)->format('D j M') }}@endif):
            {{ $agreed['findings_summary'] ?: 'No findings written.' }}</p>
    @else
        <p class="para muted">{{ !empty($d['investigation_required']) ? 'No agreed findings yet.' : 'No investigation was needed.' }}</p>
    @endif
    <p class="para"><span class="label">WorkSafe decision:</span>
        {{ !empty($ws['decision_signed']) ? ($ws['notifiable'] ? 'a notifiable event' : 'not a notifiable event') : 'not recorded yet' }}@if (!empty($ws['decision_reason'])) — {{ rtrim($ws['decision_reason'], '.') }}@endif.</p>
    @if ($filesLine)
        <p class="para"><span class="label">Files:</span> {{ $filesLine }}{{ count($photos) ? ' — the photos are on the last page' : '' }}.
            @foreach ($videos as $video) {{ $video->original_name }} ({{ intdiv((int) $video->duration_seconds, 60) }}:{{ str_pad((string) ((int) $video->duration_seconds % 60), 2, '0', STR_PAD_LEFT) }}) — open the record to play it.@endforeach
        </p>
    @endif
</div>

<div class="section">
    <h2>Actions and checks</h2>
    @if (count($actions))
        <table class="data">
            <thead><tr><th>Action</th><th>Owner</th><th>Done</th><th>Check</th></tr></thead>
            <tbody>
            @foreach ($actions as $a)
                <tr>
                    <td>{{ $a['title'] }}<div class="small">{{ $a['reference_number'] }}@if (!empty($a['replaces'])) · revised action for {{ $a['replaces']['reference_number'] }}@endif</div></td>
                    <td>{{ $a['owner']['name'] ?? $a['assigned_to_name'] ?? '—' }}</td>
                    <td>{{ !empty($a['completed_at']) ? \Illuminate\Support\Carbon::parse($a['completed_at'])->timezone($nz)->format('j M') : '—' }}</td>
                    <td>
                        @if (in_array($a['status'], ['verified', 'closed'], true))
                            {{ $a['effectiveness_confirmed'] === false ? 'Didn’t work' : 'Worked' }}@if (!empty($a['check_notes'])) — {{ $a['check_notes'] }}@endif.
                            @if (!empty($a['verified_by_name']))<span class="small">{{ $a['verified_by_name'] }}@if (!empty($a['verified_at'])), {{ \Illuminate\Support\Carbon::parse($a['verified_at'])->timezone($nz)->format('j M') }}@endif</span>@endif
                        @else
                            Not checked yet
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p class="para muted">No actions.</p>
    @endif
</div>

@if (count($corrections))
    <div class="section">
        <h2>Corrections</h2>
        @foreach ($corrections as $c)
            <div class="before-after" style="margin-bottom: 3mm;">
                <p class="para"><span class="label">{{ ucfirst($c['what']) }}</span> — {{ $c['who'] }}, {{ \Illuminate\Support\Carbon::parse($c['at'])->timezone($nz)->format('D j M') }}</p>
                @if (!empty($c['detail']['why']))<p><span class="label">Why:</span> {{ $c['detail']['why'] }}</p>@endif
                @if (!empty($c['detail']['before']))<p><span class="label">Before:</span> {{ $c['detail']['before'] }}</p>@endif
                @if (!empty($c['detail']['after']))<p><span class="label">After:</span> {{ $c['detail']['after'] }}</p>@endif
            </div>
        @endforeach
    </div>
@endif

@if ($final)
    <div class="section">
        <h2>Closing</h2>
        @if (!empty($d['closure_summary']))<p class="para"><span class="label">What we learned:</span> {{ $d['closure_summary'] }}</p>@endif
        <p class="para"><span class="label">Before it could close:</span> all {{ count($d['close_readiness']['requirements'] ?? []) }} done —
            {{ collect($d['close_readiness']['requirements'] ?? [])->pluck('label')->map(fn ($l) => rtrim(\Illuminate\Support\Str::lcfirst($l), '.'))->implode(', ') }}.</p>
    </div>
@endif

<div class="section new-page">
    <h2>Activity log</h2>
    <div class="muted">Every entry, newest first ({{ count($entries) }}). Nothing in it can be changed.</div>
    <table class="data">
        <thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead>
        <tbody>
        @foreach ($entries as $e)
            <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($e['at'])->timezone($nz)->format('D j M, g:i a') }}</td>
                <td>{{ $e['who'] ?? '—' }}</td>
                <td>{{ ucfirst($e['what']) }}@if (!empty($e['detail']['why'])) <span class="small">— {{ $e['detail']['why'] }}</span>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

@if (count($photos) || count($videos))
    <div class="section new-page">
        <h2>Photos and videos</h2>
        <div class="muted">{{ count($photos) }} {{ count($photos) === 1 ? 'photo' : 'photos' }}@if (count($videos)) and {{ count($videos) }} {{ count($videos) === 1 ? 'video' : 'videos' }}@endif, in the order they were added. Videos can’t play in a PDF: open the record in the app to play them.</div>
        <table class="photos" style="width: 100%; border-collapse: collapse; margin-top: 3mm;">
            @foreach (array_chunk($photos, 3) as $row)
                <tr>
                    @foreach ($row as $photo)
                        <td>
                            <img src="{{ $photo['src'] }}" alt="">
                            <div class="small"><b>{{ $stepLabel[$photo['step']] ?? $photo['step'] }}</b> · {{ $photo['who'] }}, {{ $photo['when'] }}</div>
                            @if ($photo['caption'])<div class="small">{{ $photo['caption'] }}</div>@endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
        @foreach ($videos as $video)
            <p class="para small">Video: {{ $video->original_name }} · {{ intdiv((int) $video->duration_seconds, 60) }}:{{ str_pad((string) ((int) $video->duration_seconds % 60), 2, '0', STR_PAD_LEFT) }} · {{ $stepLabel[$video->step] ?? $video->step }} · {{ $video->uploaded_by_name }} — play it in the app.</p>
        @endforeach
    </div>
@endif
</body>
</html>
