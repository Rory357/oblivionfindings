<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 30px 28px 40px; }
        body { font: 10px 'DejaVu Sans', sans-serif; color: #242136; }
        h1 { font-size: 22px; margin-bottom: 4px; }
        h2 { font-size: 13px; margin-top: 20px; }
        .brand { border-bottom: 3px solid #543cff; padding-bottom: 10px; }
        p { line-height: 1.5; }
        table { border-collapse: collapse; width: 100%; margin: 12px 0 20px; }
        th { text-align: left; background: #efedff; }
        th, td { padding: 8px 6px; border-bottom: 1px solid #dcd9e8; vertical-align: top; }
        tr { page-break-inside: avoid; }
        .overview-summary { page-break-after: always; }
        .movements th, .movements td { padding: 4px 6px; }
        small { color: #646174; }
        footer { position: fixed; bottom: -24px; font-size: 8px; color: #646174; }
    </style>
</head>
<body>
@php
    $returnLabels = ['not_due' => 'Not due', 'due' => 'Vehicle due back', 'overdue' => 'Overdue return',
        'keys' => 'Confirm key storage', 'items' => 'Receive missing items', 'handover' => 'Incoming worker to acknowledge',
        'exceptions' => 'Review handover dispute', 'complete' => 'Return work complete'];
    $dateLabel = fn ($value) => $value ? \Carbon\Carbon::parse($value)->timezone('Pacific/Auckland')->format('j M, g:i a') : 'Not recorded';
@endphp
<div class="brand">
    <strong>{{ $brand }}</strong>
    <h1>Transport activity</h1>
    <p>{{ $filters['selection'] }} · {{ $filters['site_name'] }}<br>
        {{ \Carbon\Carbon::parse($filters['from'])->format('j M Y') }} to {{ \Carbon\Carbon::parse($filters['to'])->format('j M Y') }} · Pacific/Auckland · {{ count($records) }} permitted requests<br>
        Generated {{ $generatedAt }} by {{ $generatedBy }}
        @if($filters['search']) · Search: {{ $filters['search'] }} @endif
    </p>
</div>
@if($filters['view'] === 'overview')
<div class="overview-summary">
    <h2>Requests by stage</h2>
    <table>
        <thead><tr><th>Needs assessment</th><th>Ready to plan</th><th>Booking decision</th><th>Preparing</th><th>Travelling / returned</th><th>Completed</th><th>Cancelled</th></tr></thead>
        <tbody><tr>
            @foreach([['assessment', 'information'], ['allocation', 'plan_review', 'allocated'], ['decision'], ['ready', 'depart'], ['travelling', 'returned'], ['completed'], ['cancelled']] as $group)
                <td>{{ collect($records)->whereIn('stage', $group)->count() }}</td>
            @endforeach
        </tr></tbody>
    </table>
    <h2>Planned movements by local hour</h2>
    <p>Scheduled departures and expected returns for the selected requests. These are planned movements, not actual travel.</p>
    @php
        $movements = [];
        foreach ($records as $record) {
            if ($record['stage'] === 'cancelled' || in_array($record['booking']['status'] ?? '', ['cancelled', 'rejected'], true)) continue;
            foreach (['departures' => $record['booking']['start'] ?? $record['start'], 'returns' => $record['booking']['end'] ?? $record['end']] as $kind => $time) {
                if (!$time) continue;
                $hour = (int) \Carbon\Carbon::parse($time)->timezone('Pacific/Auckland')->format('G');
                $movements[$hour] ??= ['departures' => 0, 'returns' => 0];
                $movements[$hour][$kind]++;
            }
        }
        ksort($movements);
    @endphp
    <table class="movements"><thead><tr><th>Local hour</th><th>Scheduled departures</th><th>Expected returns</th></tr></thead><tbody>
        @forelse($movements as $hour => $counts)
            <tr><td>{{ sprintf('%02d:00', $hour) }}</td><td>{{ $counts['departures'] }}</td><td>{{ $counts['returns'] }}</td></tr>
        @empty <tr><td colspan="3">No planned movements in this selection.</td></tr> @endforelse
    </tbody></table>
</div>
@endif
<h2>Transport records and next actions</h2>
<table>
    <thead><tr><th>Passenger / reference</th><th>Route</th><th>Transport window</th><th>Vehicle / driver</th><th>Journey / return</th><th>Next action / owner</th></tr></thead>
    <tbody>
    @forelse($records as $row)
        <tr>
            <td>{{ $row['person'] }}<br><small>{{ $row['reference'] }} · {{ $row['site']['name'] }}</small></td>
            <td>{{ $row['pickup'] }} → {{ $row['destination'] }}</td>
            <td>{{ $dateLabel($row['booking']['start'] ?? $row['start']) }}<br>{{ $dateLabel($row['booking']['end'] ?? $row['end']) }}</td>
            <td>{{ $row['booking']['vehicle']['name'] ?? 'Unallocated' }}<br>{{ $row['booking']['driver']['name'] ?? '' }}<br><small>{{ $row['booking']['reference'] ?? '' }}</small></td>
            <td>{{ $row['stage_label'] }}<br><small>{{ $returnLabels[$row['return_stage']] ?? 'Review return' }}</small></td>
            <td>{{ $filters['view'] === 'returns' ? ($returnLabels[$row['return_stage']] ?? 'Review return') : $row['next_action'] }}<br><small>{{ $filters['view'] === 'returns' ? $row['return_next_owner'] : $row['next_owner'] }}</small></td>
        </tr>
    @empty <tr><td colspan="6">No transport records match this selection.</td></tr> @endforelse
    </tbody>
</table>
@foreach($records as $row)
    @if(isset($row['history']))
        <h2>{{ $row['reference'] }} · Journey and return details</h2>
        <table><thead><tr><th>Passenger journey</th><th>Vehicle return</th><th>Keys and required items</th></tr></thead><tbody><tr>
            <td>Departed: {{ $dateLabel($row['journey']['departed_at'] ?? null) }}<br>
                Arrived: {{ $dateLabel($row['journey']['arrived_at'] ?? null) }}<br>
                Passengers accounted for: {{ $dateLabel($row['journey']['accounted_at'] ?? null) }}<br>
                {{ $row['stage_label'] }}</td>
            <td>Returned: {{ $dateLabel($row['booking']['returned_at'] ?? null) }}<br>
                Odometer out / in: {{ $row['booking']['odometer_out'] ?? 'Not recorded' }} / {{ $row['booking']['odometer_in'] ?? 'Not recorded' }}<br>
                Condition: {{ $row['booking']['condition'] ?? 'Not recorded' }}</td>
            <td>Planned collection: {{ collect($row['rooms'] ?? [])->firstWhere('id', $row['key_pickup_room_id'])['name'] ?? 'Not arranged' }}<br>
                Planned return: {{ collect($row['rooms'] ?? [])->firstWhere('id', $row['key_return_room_id'])['name'] ?? 'Not arranged' }}<br>
                {{ $row['key_delivery_arrangement'] ?? '' }}<br>
                Recorded storage: {{ ($row['keys']['room_id'] ?? null) ? $row['keys']['location'] : 'Not confirmed' }}<br>
                Items outstanding: {{ implode(', ', $row['missing_items']) ?: 'No required item outstanding' }}</td>
        </tr></tbody></table>
        <h2>{{ $row['reference'] }} · Recorded observations</h2>
        @forelse($row['history'] as $event)
            <p><strong>{{ ucwords(str_replace('_', ' ', $event['action'])) }}</strong> · {{ $event['actor'] }} · {{ $dateLabel($event['at']) }}<br>{{ $event['message'] }} {{ implode(', ', $event['items']) }}</p>
        @empty <p>No transport observations have been recorded.</p> @endforelse
    @endif
@endforeach
<footer>Permitted source records at export time. Passenger completion, vehicle return and item custody are separate records.</footer>
</body>
</html>
