{{-- PKG-02B vehicle trip report. Rendered by VehicleTripReportExporter with dompdf; DejaVu Sans carries macrons. --}}
<!DOCTYPE html>
<html lang="en-NZ">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 30mm 15mm 18mm 15mm; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { color: #211d2f; font-size: 9pt; line-height: 1.45; }
        .page-head { position: fixed; top: -24mm; left: 0; right: 0; height: 20mm; }
        .page-head .bar { height: 2.5mm; background: {{ $brand['colour'] }}; }
        .page-head table { width: 100%; border-collapse: collapse; margin-top: 3mm; }
        .page-head td { vertical-align: middle; }
        .ring { width: 9mm; height: 9mm; border: 1.6mm solid {{ $brand['colour'] }}; border-radius: 50%; }
        .logo { max-height: 11mm; max-width: 32mm; }
        .org { font-size: 11pt; font-weight: bold; letter-spacing: .06em; text-transform: uppercase; }
        .crumb { font-size: 8pt; color: #625d73; }
        .page-foot { position: fixed; bottom: -11mm; left: 0; right: 0; height: 7mm; border-top: .4pt solid #dcdae3; padding-top: 1.5mm; font-size: 7pt; color: #625d73; }
        .page-foot table { width: 100%; border-collapse: collapse; }
        .page-number:after { content: counter(page) " / " counter(pages); }
        h1 { font-size: 19pt; margin: 0 0 1mm; }
        h2 { font-size: 13pt; margin: 0 0 1mm; }
        h3 { font-size: 10pt; margin: 5mm 0 2mm; }
        .muted { color: #625d73; }
        .accent { color: {{ $brand['colour'] }}; }
        .vehicle { font-size: 12pt; font-weight: bold; margin-top: 4mm; }
        table.kpis { width: 100%; border-collapse: separate; border-spacing: 2mm 0; margin: 4mm 0; }
        table.kpis td { width: 25%; background: {{ $brand['tint'] }}; padding: 3mm 3.5mm; vertical-align: top; }
        .kpi-label { font-size: 7pt; font-weight: bold; letter-spacing: .06em; color: #625d73; text-transform: uppercase; }
        .kpi-value { font-size: 15pt; font-weight: bold; color: {{ $brand['colour'] }}; margin-top: 1mm; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 2mm; }
        table.data th { background: {{ $brand['colour'] }}; color: #ffffff; font-size: 7.5pt; text-align: left; padding: 1.6mm 2mm; }
        table.data td { border-bottom: .4pt solid #e3e1ea; padding: 1.6mm 2mm; vertical-align: top; font-size: 8pt; }
        table.data td.num, table.data th.num { text-align: right; white-space: nowrap; }
        .route-to { color: #625d73; }
        .notes { margin-top: 5mm; }
        .notes p { margin: 0 0 1.5mm; font-size: 8pt; color: #3d384d; }
        .trip { page-break-inside: avoid; margin-top: 7mm; }
        .trip.new-page { page-break-before: always; margin-top: 0; }
        table.facts { width: 100%; border-collapse: collapse; margin-top: 3mm; }
        table.facts td { width: 25%; border: .4pt solid #e3e1ea; padding: 2mm 2.5mm; vertical-align: top; }
        .fact-label { font-size: 7pt; color: #625d73; }
        .fact-value { font-size: 10pt; font-weight: bold; margin-top: .5mm; }
        .route-sketch { margin-top: 3mm; border: .4pt solid #dcdae3; }
        .route-sketch img { width: 100%; }
        .legend { font-size: 7.5pt; color: #625d73; margin-top: 1mm; }
        .dot { display: inline-block; width: 2.4mm; height: 2.4mm; border-radius: 50%; }
        .source { margin-top: 2.5mm; font-size: 7.5pt; color: #625d73; }
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
                <div class="crumb">Fleet &amp; assets · Trip report</div>
            </td>
            <td style="text-align: right;" class="crumb">{{ $vehicle_line }}</td>
        </tr>
    </table>
</div>
<div class="page-foot">
    <table>
        <tr>
            <td>{{ $generated_label }} · {{ $range_label }} · {{ $timezone }}</td>
            <td style="text-align: right;"><span class="page-number"></span></td>
        </tr>
    </table>
</div>

<h1>Vehicle journeys</h1>
<div class="muted">{{ $range_label }} · {{ $timezone }}</div>
<div class="vehicle">{{ $vehicle_line }}</div>
<div class="muted">{{ $filters_line }}</div>

<table class="kpis">
    <tr>
        <td><div class="kpi-label">Trips</div><div class="kpi-value">{{ $totals['trips'] }}</div></td>
        <td><div class="kpi-label">Distance</div><div class="kpi-value">{{ number_format($totals['distance_km'], 1) }} km</div></td>
        <td><div class="kpi-label">Recorded time</div><div class="kpi-value">{{ $totals['duration_label'] }}</div></td>
        <td><div class="kpi-label">Driving events</div><div class="kpi-value">{{ $totals['driving_events'] }}</div></td>
    </tr>
</table>

<h3>Included journeys</h3>
<table class="data">
    <thead>
    <tr>
        <th>Date</th>
        <th>Trip</th>
        <th>Route</th>
        <th>Driver</th>
        <th class="num">km</th>
        <th class="num">Min</th>
        <th class="num">Coverage</th>
        <th>Score</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($trips as $trip)
        <tr>
            <td>{{ $trip['date_label'] }}<br><span class="muted">{{ $trip['start_time'] }} – {{ $trip['end_time'] }}</span></td>
            <td><strong>{{ $trip['reference'] }}</strong></td>
            <td>{{ $trip['from'] }}<br><span class="route-to">→ {{ $trip['to'] }}</span></td>
            <td>{{ $trip['driver_name'] }}<br><span class="muted">{{ $trip['driver_status'] }}</span></td>
            <td class="num">{{ number_format($trip['distance_km'], 1) }}</td>
            <td class="num">{{ $trip['minutes'] }}</td>
            <td class="num">{{ $trip['coverage_pct'] !== null ? $trip['coverage_pct'].'%' : '—' }}</td>
            <td>{{ $trip['score_label'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="notes">
    <p><strong>{{ $scope_note }}</strong></p>
    @foreach ($notes as $note)
        <p>{{ $note }}</p>
    @endforeach
</div>

@foreach ($trips as $index => $trip)
    <div class="trip{{ $include_routes || $index === 0 ? ' new-page' : '' }}">
        <h2>{{ $trip['reference'] }} · <span class="accent">{{ $trip['date_label'] }}</span></h2>
        <div><strong>{{ $trip['from'] }}</strong> <span class="muted">→</span> <strong>{{ $trip['to'] }}</strong></div>
        <div class="muted">
            {{ $trip['start_time'] }} – {{ $trip['end_time'] }} · {{ number_format($trip['distance_km'], 1) }} km estimated · {{ $trip['minutes'] }} min
        </div>
        <table class="facts">
            <tr>
                <td><div class="fact-label">Driver</div><div class="fact-value">{{ $trip['driver_name'] }}</div><div class="muted">{{ $trip['driver_status'] }}</div></td>
                <td><div class="fact-label">Behaviour score</div><div class="fact-value">{{ $trip['score_label'] }}</div></td>
                <td><div class="fact-label">Coverage</div><div class="fact-value">{{ $trip['coverage_pct'] !== null ? $trip['coverage_pct'].'%' : 'Not recorded' }}</div>@if ($trip['partial'])<div class="muted">Partial trail</div>@endif</td>
                <td><div class="fact-label">Maximum speed</div><div class="fact-value">{{ $trip['max_speed_kph'] !== null ? number_format($trip['max_speed_kph'], 0).' km/h' : 'Not reported' }}</div></td>
            </tr>
            <tr>
                <td><div class="fact-label">Harsh braking</div><div class="fact-value">{{ $trip['harsh']['braking'] }}</div></td>
                <td><div class="fact-label">Harsh acceleration</div><div class="fact-value">{{ $trip['harsh']['acceleration'] }}</div>@if ($trip['harsh']['cornering'] + $trip['harsh']['unclassified'] > 0)<div class="muted">{{ $trip['harsh']['cornering'] + $trip['harsh']['unclassified'] }} other harsh</div>@endif</td>
                <td><div class="fact-label">Overspeed episodes</div><div class="fact-value">{{ $trip['overspeed_episodes'] }}</div>@if ($trip['overspeed_seconds'])<div class="muted">{{ $trip['overspeed_seconds'] }} sec over</div>@endif</td>
                <td><div class="fact-label">Idle time</div><div class="fact-value">{{ $trip['idle_minutes'] !== null ? rtrim(rtrim(number_format($trip['idle_minutes'], 1), '0'), '.').' min' : 'Not reported' }}</div></td>
            </tr>
        </table>

        @if ($include_routes)
            @if ($trip['route'])
                <div class="route-sketch"><img src="{{ $trip['route'] }}" alt="Recorded positions for {{ $trip['reference'] }}"></div>
                <div class="legend">
                    <span class="dot" style="background:#15803d;"></span> Start ·
                    <span class="dot" style="background:#b91c1c;"></span> End ·
                    Recorded positions joined in time order{{ $trip['partial'] ? ', dashed where coverage is partial' : '' }}. This sketch is not a map and the lines are not verified roads.
                </div>
            @else
                <div class="legend">No recorded positions to sketch for this trip.</div>
            @endif
        @endif

        @if ($include_events)
            <h3>Journey events</h3>
            @if (count($trip['events']))
                <table class="data">
                    <thead><tr><th style="width: 15mm;">Time</th><th style="width: 38mm;">Event</th><th>Details</th><th style="width: 45mm;">Location</th></tr></thead>
                    <tbody>
                    @foreach ($trip['events'] as $event)
                        <tr>
                            <td>{{ $event['time'] }}</td>
                            <td><strong>{{ $event['title'] }}</strong></td>
                            <td>{{ $event['detail'] }}</td>
                            <td>{{ $event['location'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <div class="muted">No events were recorded during this trip.</div>
            @endif
        @endif

        <div class="source">Source: {{ $trip['source_note'] }}. Original telemetry is kept unchanged.</div>
    </div>
@endforeach
</body>
</html>
