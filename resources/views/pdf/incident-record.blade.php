{{-- G3 board M5c note: a closed incident's final record, in the Fleet reports' style like the safety review's. Rendered
     by IncidentPdfService with dompdf; DejaVu Sans carries macrons. Built only from the page payload this person sees. --}}
<!DOCTYPE html>
<html lang="en-NZ">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('pdf.report-styles')
    <style>
        .status { display: inline-block; padding: .6mm 2.2mm; border-radius: 3mm; font-size: 7.5pt; font-weight: bold; background: {{ $brand['tint'] }}; color: {{ $brand['colour'] }}; }
        .section { margin-top: 6mm; }
        .para { margin: 0 0 2mm; }
        .label { font-weight: bold; }
        .small { font-size: 7.5pt; color: #625d73; }
        .note { margin: 0 0 1.2mm; padding: 1.2mm 2mm; background: #f4f3f8; }
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
                <div class="crumb">Incidents · Incident report · final record</div>
            </td>
            <td style="text-align: right;" class="crumb">{{ $d['ref'] }}<br>{{ $site ?? '' }}</td>
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

<h1>Incident {{ $d['ref'] }}</h1>
<div class="muted">
    {{ ucfirst(str_replace('_', ' ', (string) ($d['type'] ?? 'incident'))) }}@if ($site) · {{ $site }}@endif @if ($occurred) · happened {{ $occurred }}@endif
</div>
<div style="margin-top: 2mm;"><span class="status">{{ $closedLine }}</span></div>

<h3>Steps taken</h3>
<table class="data">
    <thead><tr><th>Step</th><th>When</th><th>Who</th></tr></thead>
    <tbody>
    @foreach ($steps as [$label, $when, $who])
        <tr><td>{{ $label }}</td><td>{{ $when }}</td><td>{{ $who }}</td></tr>
    @endforeach
    </tbody>
</table>

<h3>About the incident</h3>
<table class="facts">
    <tr>
        <td><div class="fact-label">Person</div><div class="fact-value">{{ $person ?? 'No person linked' }}</div></td>
        <td><div class="fact-label">Severity</div><div class="fact-value">{{ ucfirst((string) ($d['severity'] ?? '—')) }}</div></td>
        <td><div class="fact-label">Reported by</div><div class="fact-value">{{ $d['reporter']['name'] ?? '—' }}</div></td>
        <td><div class="fact-label">WorkSafe</div><div class="fact-value">{{ $d['is_notifiable'] === null ? 'On the safety review' : ($d['is_notifiable'] ? 'Notifiable' : 'Not notifiable') }}</div></td>
    </tr>
</table>

<div class="section">
    <h2>What happened</h2>
    @if (!empty($d['description']))<p class="para">{{ $d['description'] }}</p>@else<p class="para muted">Not recorded.</p>@endif
    @if (!empty($d['immediate_action_taken']))<p class="para"><span class="label">What we did straight away:</span> {{ $d['immediate_action_taken'] }}</p>@endif
    @if (count($ticks))
        <table class="data">
            <thead><tr><th>Straight away</th><th>Done</th></tr></thead>
            <tbody>
            @foreach ($ticks as $tick)
                <tr><td>{{ $tick['label'] }}</td><td>{{ $tick['line'] }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
    @if (!empty($d['witnesses']))<p class="para"><span class="label">Witnesses:</span> {{ is_array($d['witnesses']) ? implode(', ', array_filter(array_map(fn ($w) => is_array($w) ? ($w['name'] ?? null) : $w, $d['witnesses']))) : $d['witnesses'] }}</p>@endif
</div>

@if (count($notes))
    <div class="section">
        <h2>Dated notes</h2>
        <div class="muted">Added after the report was sent. The reporter’s words are never changed.</div>
        @foreach ($notes as $note)
            <p class="note"><span class="label">{{ $note['who'] }}, {{ $note['when'] }}:</span> {{ $note['note'] }}</p>
        @endforeach
    </div>
@endif

<div class="section">
    <h2>Manager review</h2>
    @if (!empty($d['review_notes']))<p class="para">{{ $d['review_notes'] }}</p>@else<p class="para muted">No review notes.</p>@endif
</div>

@if (count($followups))
    <div class="section">
        <h2>Follow-ups</h2>
        <table class="data">
            <thead><tr><th>Follow-up</th><th>Who</th><th>Due</th><th>Done</th></tr></thead>
            <tbody>
            @foreach ($followups as $f)
                <tr><td>{{ $f['notes'] }}</td><td>{{ $f['who'] }}</td><td>{{ $f['due'] }}</td><td>{{ $f['done'] ?? 'Not done' }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

<div class="section">
    <h2>Closing</h2>
    @if (!empty($d['closed_outcome']))<p class="para"><span class="label">Outcome:</span> {{ ucfirst(str_replace('_', ' ', (string) $d['closed_outcome'])) }}</p>@endif
    @if (!empty($d['closed_notes']))<p class="para">{{ $d['closed_notes'] }}</p>@endif
    @if (!empty($d['reopened_at']))<p class="para small">It was reopened once before{{ !empty($d['reopened_reason']) ? ': '.$d['reopened_reason'] : '' }}.</p>@endif
    <p class="para small">A linked safety review has its own final record.</p>
</div>
</body>
</html>
