{{-- S&D rebuild pg-sdr-12i: a Site's logbook exported for a day or date range, in the Fleet reports' style.
     Rendered by SiteLogbookExport with dompdf; DejaVu Sans carries macrons. --}}
<!DOCTYPE html>
<html lang="en-NZ">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('pdf.report-styles')
    <style>
        .section { margin-top: 5mm; }
        .meta-box { margin-top: 3.5mm; padding: 2.5mm 3.5mm; border: 0.3pt solid #d9d6e3; border-radius: 2mm; background: #faf9fd; font-size: 8.5pt; }
        .meta-box b { color: #1b1726; }
        .none { color: #625d73; font-style: italic; margin-top: 4mm; }
        .nw { white-space: nowrap; }
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
                <div class="crumb">Security &amp; devices · Site logbook</div>
            </td>
            <td style="text-align: right;" class="crumb">{{ $siteName }}<br>{{ $rangeLabel }}</td>
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

<h1>{{ $siteName }} logbook</h1>
<div class="muted">{{ $rangeLabel }} · Showing: {{ $whatLabel }} · {{ $countLabel }} · Newest first</div>

<div class="meta-box">
    <b>Exported by:</b> {{ $exportedBy }} &nbsp;·&nbsp; <b>Reason:</b> {{ $reason }}
</div>

<div class="section">
    @if (count($rows) === 0)
        <p class="none">Nothing matching “{{ $whatLabel }}” was recorded at {{ $siteName }} during {{ $rangeLabel }}.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th style="width: 28mm;">When</th>
                    <th style="width: 22mm;">Kind</th>
                    <th>What happened</th>
                    <th>Details</th>
                    <th style="width: 30mm;">Who</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($rows as [$when, $kind, $whatHappened, $details, $who])
                <tr>
                    <td class="nw">{{ $when }}</td>
                    <td>{{ $kind }}</td>
                    <td><b>{{ $whatHappened }}</b></td>
                    <td>{{ $details !== '' ? $details : '—' }}</td>
                    <td>{{ $who !== '' ? $who : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if ($capped)
            <p class="muted" style="margin-top: 3mm;">Showing the newest {{ number_format(count($rows)) }} entries. Narrow the date range or What filter to export earlier entries.</p>
        @endif
    @endif
</div>
</body>
</html>
