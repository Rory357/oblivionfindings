<!DOCTYPE html>
<html lang="en-NZ">
<head><meta charset="utf-8"><title>{{ $name }} · {{ ucfirst($kind) }} report</title>
@include('pdf.report-styles')
</head>
<body>
@php($time = fn ($value) => $value ? \Carbon\CarbonImmutable::parse($value)->setTimezone('Pacific/Auckland')->format('j M Y, g:i a T') : 'Not recorded')
<div class="page-head"><div class="bar"></div><table><tr>
<td style="width:13mm">@if ($brand['logo'])<img class="logo" src="{{ $brand['logo'] }}" alt="">@else<div class="ring"></div>@endif</td>
<td><div class="org">{{ $brand['name'] }}</div><div class="crumb">Operations · People Locations</div></td>
<td class="crumb" style="text-align:right">{{ $name }} · {{ $source['reference'] }}</td>
</tr></table></div>
<div class="page-foot"><table><tr><td>Generated {{ $time($generatedAt) }} · {{ $generatedBy }}</td><td style="text-align:right"><span class="page-number"></span></td></tr></table></div>
<h1>{{ $kind === 'outing' ? 'Passenger outing' : 'Daily location' }} report</h1>
<div class="muted">{{ \Carbon\CarbonImmutable::parse($date)->format('j F Y') }} · Pacific/Auckland</div><div class="vehicle">{{ $name }}</div>
<div class="muted">{{ $source['label'] }} · {{ $source['reference'] }} · {{ $source['purpose'] }}</div>
<table class="kpis"><tr>
<td><div class="kpi-label">Recorded positions</div><div class="kpi-value">{{ count($positions) }}</div></td>
<td><div class="kpi-label">Device samples</div><div class="kpi-value">{{ count($samples) }}</div></td>
<td><div class="kpi-label">Retention</div><div class="kpi-value">{{ $source['retentionDays'] }} days</div></td>
</tr></table>
@if ($journey)
<h2>{{ $journey['reference'] }}</h2>
<table class="facts"><tr><td><div class="fact-label">Passenger departure</div>{{ $time($journey['departedAt']) }}</td>
<td><div class="fact-label">Passenger arrival</div>{{ $time($journey['arrivedAt']) }}</td>
<td><div class="fact-label">Passengers accounted for</div>{{ $time($journey['accountedAt']) }}</td></tr></table>
<p class="muted">Transport &amp; Handover owns this passenger journey. Vehicle return, keys and handover are separate records. Only this day's authorised observations inside the passenger window are included.</p>
@endif
<h3>Recorded positions</h3>
<table class="data"><thead><tr><th>Observation time</th><th>Latitude</th><th>Longitude</th><th>Accuracy</th></tr></thead><tbody>
@forelse ($positions as $p)<tr><td>{{ $time($p['timestamp']) }}</td><td>{{ number_format($p['lat'], 6) }}</td><td>{{ number_format($p['lng'], 6) }}</td><td>{{ isset($p['accuracy']) ? $p['accuracy'].' m' : 'Unknown' }}</td></tr>
@empty<tr><td colspan="4">No authorised recorded positions in this window.</td></tr>@endforelse
</tbody></table>
<h3>Device evidence</h3>
<table class="data"><thead><tr><th>Observation time</th><th>Battery</th><th>Power</th><th>Movement</th></tr></thead><tbody>
@forelse ($samples as $s)<tr><td>{{ $time($s['at']) }}</td><td>{{ $s['battery'] === null ? 'Unknown' : $s['battery'].'%' }}</td><td>{{ str_replace('_', ' ', $s['power']) }}</td><td>{{ $s['motion'] ?: 'Unknown' }}</td></tr>
@empty<tr><td colspan="4">No recorded device samples in this window.</td></tr>@endforelse
</tbody></table>
<div class="notes"><p>Source: retained observations from the current authorised assignment. Missing intervals remain unknown. Recorded locations do not confirm attendance, safety, wellbeing or continuous movement. No route or duration is inferred.</p>
<p>Authorised window: {{ $time($window['from']) }} to {{ $time($window['to']) }}.</p>
@if ($truncated)<p><strong>Partial report:</strong> the source returned its latest bounded sample set (up to 500 per stream). Earlier observations may be omitted.</p>@endif
</div></body></html>
