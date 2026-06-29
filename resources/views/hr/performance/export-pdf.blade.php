<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #1f2430; margin: 24px; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .meta { color: #6b7280; font-size: 10px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; text-transform: uppercase; font-size: 8px; letter-spacing: .04em;
             color: #6b7280; border-bottom: 1.5px solid #d1d5db; padding: 6px 6px; }
        td { padding: 6px 6px; border-bottom: 1px solid #eceef1; vertical-align: top; }
        tr:nth-child(even) td { background: #f8f9fb; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">Performance &amp; Development hub · generated {{ $generatedAt }}</div>
    <table>
        <thead>
            <tr>
                @foreach ($headers as $h)
                    <th>{{ \Illuminate\Support\Str::headline($h) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($headers as $h)
                        <td>{{ is_array($row[$h] ?? null) ? json_encode($row[$h]) : ($row[$h] ?? '') }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headers) }}">No records.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
