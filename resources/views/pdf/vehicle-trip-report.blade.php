{{-- PKG-02B vehicle trip report. Rendered by VehicleTripReportExporter with dompdf; DejaVu Sans carries macrons. --}}
<!DOCTYPE html>
<html lang="en-NZ">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('pdf.report-styles')
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
                    Recorded positions joined in time order{{ $trip['partial'] ? ', dashed where coverage is partial' : '' }}. Lines are not verified roads.
                    <br>{{ $trip['map_note'] }}
                </div>
            @else
                <div class="legend">No recorded positions to sketch for this trip.</div>
            @endif
        @endif

        @if ($include_events)
            <h3>Journey events</h3>
            @if (count($trip['events']))
                <table class="data">
                    <thead>
                    <tr><th colspan="4">{{ $brand['name'] }} · {{ $trip['reference'] }} · {{ $vehicle_line }}</th></tr>
                    <tr><th style="width: 15mm;">Time</th><th style="width: 38mm;">Event</th><th>Details</th><th style="width: 45mm;">Location</th></tr>
                    </thead>
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
