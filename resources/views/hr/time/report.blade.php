<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1c1c28; font-size: 12px; margin: 28px 32px; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .sub { color: #6b7280; font-size: 11px; margin: 0 0 18px; }
        .kpis { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        .kpis td { width: 25%; border: 1px solid #e5e7eb; padding: 10px 12px; }
        .kpis .label { color: #6b7280; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
        .kpis .value { font-size: 20px; font-weight: bold; }
        h2 { font-size: 13px; margin: 18px 0 8px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { text-align: left; background: #f3f4f6; padding: 6px 10px; font-size: 10px; text-transform: uppercase; color: #6b7280; }
        table.data td { padding: 6px 10px; border-bottom: 1px solid #eef0f3; }
        .num { text-align: right; }
        .ot { color: #b45309; font-weight: bold; }
        .foot { margin-top: 24px; color: #9ca3af; font-size: 10px; }
    </style>
</head>
<body>
    <h1>Timekeeping — hours &amp; compliance</h1>
    <p class="sub">Week {{ $report['week_start'] }} – {{ $report['week_end'] }} · generated {{ $generatedAt }}</p>

    <table class="kpis">
        <tr>
            <td><div class="label">Total hours</div><div class="value">{{ $report['kpis']['total_hours'] }}h</div></td>
            <td><div class="label">Overtime (&gt;40h)</div><div class="value">{{ $report['kpis']['overtime_hours'] }}h</div></td>
            <td><div class="label">Break fails</div><div class="value">{{ $report['kpis']['break_fails'] }}</div></td>
            <td><div class="label">Mileage</div><div class="value">{{ $report['kpis']['mileage_km'] }} km</div></td>
        </tr>
    </table>

    <h2>Hours by site</h2>
    <table class="data">
        <thead><tr><th>Site</th><th class="num">Hours</th></tr></thead>
        <tbody>
        @forelse ($report['by_site'] as $row)
            <tr><td>{{ $row['name'] }}</td><td class="num">{{ $row['hours'] }}h</td></tr>
        @empty
            <tr><td colspan="2">No hours recorded this week.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Hours by staff</h2>
    <table class="data">
        <thead><tr><th>Staff</th><th class="num">Hours</th><th class="num">Overtime</th></tr></thead>
        <tbody>
        @forelse ($report['by_staff'] as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ $row['hours'] }}h</td>
                <td class="num">@if ($row['overtime'] > 0)<span class="ot">{{ $row['overtime'] }}h</span>@else — @endif</td>
            </tr>
        @empty
            <tr><td colspan="3">No hours recorded this week.</td></tr>
        @endforelse
        </tbody>
    </table>

    <p class="foot">Payroll interpretation (loadings, PAYE, ACC, KiwiSaver, Holidays Act) is owned by the HR pay run; this report is an operational hours summary.</p>
</body>
</html>
