<!DOCTYPE html>
<html lang="en-NZ">
<head>
    <meta charset="UTF-8">
    <title>{{ $room->name }} — {{ $site->name }} — Door Card</title>
    <style>
        @page { size: A5 portrait; margin: 12mm; }
        :root {
            --primary: #6366f1;
            --text: #111827;
            --muted: #6b7280;
            --warn: #b45309;
            --critical: #b91c1c;
            --border: #e5e7eb;
            --soft: #f3f4f6;
        }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--text);
            margin: 0;
            padding: 20px;
            font-size: 13px;
            line-height: 1.45;
        }
        .hero {
            background: linear-gradient(135deg, var(--primary), #4f46e5);
            color: #fff;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        .hero h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
        }
        .hero .site {
            margin-top: 4px;
            font-size: 12px;
            opacity: 0.85;
        }
        .hero .badge {
            background: rgba(255, 255, 255, 0.18);
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 600;
        }
        .section {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 12px;
        }
        .section h2 {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            margin: 0 0 8px;
        }
        .row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 4px 0;
            border-bottom: 1px solid var(--soft);
        }
        .row:last-child { border-bottom: 0; }
        .row .label { color: var(--muted); }
        .row .value { font-weight: 500; text-align: right; }
        .flag {
            display: inline-block;
            background: #fff7ed;
            color: var(--warn);
            border: 1px solid #fed7aa;
            border-radius: 999px;
            padding: 2px 10px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 4px;
            margin-top: 4px;
        }
        .flag.critical {
            background: #fef2f2;
            color: var(--critical);
            border-color: #fecaca;
        }
        .occupant {
            display: flex;
            gap: 16px;
            align-items: center;
        }
        .photo {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: #ede9fe url("{{ $client?->profile_photo_url }}") center / cover no-repeat;
            border: 2px solid var(--primary);
            flex-shrink: 0;
        }
        .occupant .name {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }
        .occupant .sub {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }
        .empty {
            text-align: center;
            padding: 24px 0;
            color: var(--muted);
            font-style: italic;
        }
        .footer {
            margin-top: 16px;
            font-size: 10px;
            color: var(--muted);
            text-align: center;
            border-top: 1px dashed var(--border);
            padding-top: 8px;
        }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
        .print-button {
            position: fixed;
            top: 16px;
            right: 16px;
            background: var(--primary);
            color: #fff;
            border: 0;
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">Print door card</button>

    <div class="hero">
        <div>
            <h1>{{ $room->name }}</h1>
            <div class="site">{{ $site->name }} · {{ $site->region ?? 'NZ' }}</div>
        </div>
        @if (!$room->is_assignable)
            <span class="badge">Communal space</span>
        @elseif ($client)
            <span class="badge">Occupied</span>
        @else
            <span class="badge">Available</span>
        @endif
    </div>

    @if ($client)
        <div class="section">
            <h2>Occupant</h2>
            <div class="occupant">
                <div class="photo"></div>
                <div>
                    <p class="name">
                        {{ trim(($client->preferred_name ?: $client->first_name) . ' ' . $client->last_name) }}
                    </p>
                    <div class="sub">
                        @if ($client->preferred_name && $client->preferred_name !== $client->first_name)
                            {{ trim($client->first_name . ' ' . $client->last_name) }} ·
                        @endif
                        {{ ucfirst($client->status ?? '—') }}
                        @if ($client->date_of_birth)
                            · DOB {{ \Carbon\Carbon::parse($client->date_of_birth)->format('d/m/Y') }}
                            ({{ \Carbon\Carbon::parse($client->date_of_birth)->age }} yrs)
                        @endif
                    </div>
                    @if ($client->nhi_number)
                        <div class="sub">NHI {{ $client->nhi_number }}</div>
                    @endif
                </div>
            </div>

            <div style="margin-top: 8px;">
                @if ($client->safeguarding_flag)
                    <span class="flag critical">⚠ Safeguarding</span>
                @endif
                @if ($client->risk_level === 'high')
                    <span class="flag critical">High risk</span>
                @elseif ($client->risk_level === 'medium')
                    <span class="flag">Medium risk</span>
                @endif
            </div>
        </div>

        <div class="section">
            <h2>Care team</h2>
            <div class="row">
                <span class="label">Key worker</span>
                <span class="value">{{ $client->keyWorker?->name ?? '—' }}</span>
            </div>
            @if ($room->assigned_from)
                <div class="row">
                    <span class="label">In room since</span>
                    <span class="value">{{ \Carbon\Carbon::parse($room->assigned_from)->format('d/m/Y') }}</span>
                </div>
            @endif
            @if ($room->assigned_until)
                <div class="row">
                    <span class="label">Until</span>
                    <span class="value">{{ \Carbon\Carbon::parse($room->assigned_until)->format('d/m/Y') }}</span>
                </div>
            @endif
        </div>

        @if ($client->nextOfKins && $client->nextOfKins->isNotEmpty())
            <div class="section">
                <h2>Next of kin</h2>
                @foreach ($client->nextOfKins as $nok)
                    <div class="row">
                        <span class="label">{{ $nok->name ?? '—' }}{{ $nok->relationship ? ' · ' . $nok->relationship : '' }}</span>
                        <span class="value">{{ $nok->phone ?? '—' }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($client->emergencyContacts && $client->emergencyContacts->isNotEmpty())
            <div class="section">
                <h2>Emergency contacts</h2>
                @foreach ($client->emergencyContacts as $ec)
                    <div class="row">
                        <span class="label">{{ $ec->name ?? '—' }}{{ $ec->relationship ? ' · ' . $ec->relationship : '' }}</span>
                        <span class="value">{{ $ec->phone ?? '—' }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($client->medicalProfile)
            <div class="section">
                <h2>Medical — at a glance</h2>
                @if ($client->medicalProfile->allergies)
                    <div class="row">
                        <span class="label">Allergies</span>
                        <span class="value">{{ $client->medicalProfile->allergies }}</span>
                    </div>
                @endif
                @if ($client->medicalProfile->blood_type)
                    <div class="row">
                        <span class="label">Blood type</span>
                        <span class="value">{{ $client->medicalProfile->blood_type }}</span>
                    </div>
                @endif
                @if ($client->medicalProfile->dietary_requirements)
                    <div class="row">
                        <span class="label">Dietary</span>
                        <span class="value">{{ $client->medicalProfile->dietary_requirements }}</span>
                    </div>
                @endif
            </div>
        @endif
    @else
        <div class="section">
            <p class="empty">
                @if (!$room->is_assignable)
                    Communal space — no occupant card needed.
                @else
                    No client currently assigned.
                @endif
            </p>
        </div>
    @endif

    @if ($room->notes)
        <div class="section">
            <h2>Room notes</h2>
            <div>{{ $room->notes }}</div>
        </div>
    @endif

    <div class="footer">
        Generated {{ $generatedAt->format('d/m/Y H:i') }}
        @if ($generatedBy) · by {{ $generatedBy->name }} @endif
        · Oblivion Findings
    </div>
</body>
</html>
