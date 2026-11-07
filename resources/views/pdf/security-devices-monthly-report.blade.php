{{-- S&D rebuild pg-sdr-18b: the monthly report for managers and the board, in the Fleet reports' style like the
     safety review's and incident PDFs. Rendered by MonthlyReportPdf with dompdf; DejaVu Sans carries macrons.
     Built only from the figures this person gets on the page; the camera viewing log only when they may see it. --}}
<!DOCTYPE html>
<html lang="en-NZ">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('pdf.report-styles')
    <style>
        .section { margin-top: 6mm; }
        .small { font-size: 7.5pt; color: #625d73; }
        .none { color: #625d73; font-style: italic; }
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
                <div class="crumb">Security &amp; devices · Monthly report</div>
            </td>
            <td style="text-align: right;" class="crumb">{{ $month['label'] }}<br>{{ $siteLabel }}</td>
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

<h1>{{ $month['short'] }} report for managers and the board</h1>
<div class="muted">{{ $siteLabel }} · {{ $month['range'] }} {{ substr($month['key'], 0, 4) }} · {{ $runs }}</div>

<table class="kpis">
    <tr>
        @foreach ($tiles as [$label, $value])
            <td><div class="kpi-label">{{ $label }}</div><div class="kpi-value">{{ $value }}</div></td>
        @endforeach
    </tr>
</table>

<div class="section">
    <h2>Time offline by Site</h2>
    <div class="muted">Routers and gateways, while an offline alert was open. Outages at the same time count once.</div>
    @if ($offline === null)
        <p class="none">Not available: no router or gateway is watched.</p>
    @else
        <table class="data">
            <thead><tr><th>Site</th><th class="num">Offline</th><th>Note</th></tr></thead>
            <tbody>
            @foreach ($offline as [$site, $time, $note])
                <tr><td>{{ $site }}</td><td class="num">{{ $time }}</td><td>{{ $note }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="section">
    <h2>Alerts by kind</h2>
    @if (count($kinds) === 0)
        <p class="none">No device alerts in {{ $month['short'] }}.</p>
    @else
        <table class="data">
            <thead><tr><th>Kind</th><th class="num">Alerts</th></tr></thead>
            <tbody>
            @foreach ($kinds as [$label, $count])
                <tr><td>{{ $label }}</td><td class="num">{{ $count }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="section">
    <h2>Time to pick up safety alerts</h2>
    <div class="muted">Panic buttons, alarms, leaks, forced doors and care plan sensors: from the alert to the first acknowledge, assign or mark done. Average per week.</div>
    @if ($safety === null)
        <p class="none">No safety alerts in the {{ \App\Domain\SecurityDevices\Reports\MonthlyReport::SAFETY_WEEKS }} weeks to the end of {{ $month['short'] }}.</p>
    @else
        <p class="small">{{ $safety['line'] }}</p>
        <table class="data">
            <thead><tr><th>Week of</th><th class="num">Average</th></tr></thead>
            <tbody>
            @foreach ($safety['weeks'] as [$week, $average])
                <tr><td>{{ $week }}</td><td class="num">{{ $average }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="section">
    <h2>Upkeep in {{ $month['short'] }}</h2>
    @if ($upkeep === null)
        <p class="none">Not available: no devices.</p>
    @else
        <table class="data">
            <thead><tr><th>What</th><th class="num">How many</th><th>From</th></tr></thead>
            <tbody>
            @foreach ($upkeep as [$label, $count, $from])
                <tr><td>{{ $label }}</td><td class="num">{{ $count }}</td><td>{{ $from }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="section">
    <h2>Camera picture time</h2>
    @if ($cameras === null || $cameras['percent'] === null)
        <p class="none">Not available: no camera is watched.</p>
    @else
        <div class="muted">{{ $cameras['cameras'] }} {{ $cameras['cameras'] === 1 ? 'camera' : 'cameras' }} · no picture means an offline alert was open · privacy hours don’t count against it</div>
        <table class="data">
            <thead><tr><th>Picture</th><th class="num">Hours</th></tr></thead>
            <tbody>
                <tr><td>With a picture ({{ $cameras['percent'] }}%)</td><td class="num">{{ number_format($cameras['with_hours'], 1) }}</td></tr>
                <tr><td>No picture</td><td class="num">{{ number_format($cameras['without_hours'], 1) }}</td></tr>
                @foreach ($cameras['worst'] as $camera)
                    <tr><td class="small">· {{ $camera['name'] }}</td><td class="num small">{{ number_format($camera['hours'], 1) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

@if ($views !== null)
    <div class="section">
        <h2>Camera viewing log</h2>
        <div class="muted">Every live view and saved clip in {{ $month['short'] }}, with the reason given. Shown only to people who may see camera pictures or recordings.</div>
        @if (count($views) === 0)
            <p class="none">Nobody watched a camera live or saved a clip in {{ $month['short'] }}.</p>
        @else
            <table class="data">
                <thead><tr><th>When</th><th>Who</th><th>Camera</th><th>What</th><th>Reason</th></tr></thead>
                <tbody>
                @foreach ($views as [$when, $who, $camera, $what, $reason])
                    <tr><td>{{ $when }}</td><td>{{ $who }}</td><td>{{ $camera }}</td><td>{{ $what }}</td><td>{{ $reason }}</td></tr>
                @endforeach
                </tbody>
            </table>
            @if ($views_more > 0)
                <p class="muted">And {{ $views_more }} more: the camera viewing CSV has every one.</p>
            @endif
        @endif
    </div>
@endif
</body>
</html>
