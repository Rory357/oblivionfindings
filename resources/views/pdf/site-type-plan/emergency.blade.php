<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $site['name'] }} Emergency Plan</title>
    <style>
        @page { margin: 24px; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #0f172a;
            font-size: 12px;
            line-height: 1.4;
        }
        .header {
            border-bottom: 3px solid #0f172a;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }
        .brand {
            font-size: 13px;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        h1 {
            font-size: 28px;
            margin: 4px 0 6px;
        }
        h2 {
            font-size: 15px;
            margin: 0 0 8px;
        }
        .meta {
            color: #334155;
        }
        .grid {
            width: 100%;
            border-collapse: collapse;
        }
        .grid td {
            vertical-align: top;
        }
        .main {
            width: 68%;
            padding-right: 16px;
        }
        .side {
            width: 32%;
        }
        .panel {
            border: 1px solid #cbd5e1;
            padding: 10px;
            margin-bottom: 10px;
        }
        .plan {
            border: 2px solid #334155;
            height: 520px;
            overflow: hidden;
        }
        .plan svg {
            width: 100%;
            height: 100%;
        }
        ul, ol {
            margin: 0;
            padding-left: 18px;
        }
        li {
            margin-bottom: 4px;
        }
        .contact-row {
            border-bottom: 1px solid #e2e8f0;
            padding: 5px 0;
        }
        .label {
            color: #475569;
            font-size: 10px;
            text-transform: uppercase;
        }
        .footer {
            position: fixed;
            bottom: 0;
            left: 24px;
            right: 24px;
            border-top: 1px solid #cbd5e1;
            padding-top: 6px;
            font-size: 10px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">{{ $organisation['name'] }}</div>
        <h1>Emergency Plan</h1>
        <div class="meta">
            {{ $site['name'] }}
            @if($site['address'])
                | {{ $site['address'] }}
            @endif
            @if($site['phone'])
                | {{ $site['phone'] }}
            @endif
        </div>
    </div>

    <table class="grid">
        <tr>
            <td class="main">
                <div class="plan">{!! $svg !!}</div>
            </td>
            <td class="side">
                <div class="panel">
                    <h2>Assembly Point</h2>
                    @forelse($assembly_points as $point)
                        <div><strong>{{ $point['label'] }}</strong></div>
                        @if($point['notes'])
                            <div>{{ $point['notes'] }}</div>
                        @endif
                    @empty
                        <div>No assembly point recorded.</div>
                    @endforelse
                </div>

                <div class="panel">
                    <h2>Emergency Contacts</h2>
                    @foreach($contacts as $contact)
                        <div class="contact-row">
                            <strong>{{ $contact['name'] }}</strong>
                            <div>{{ $contact['role'] }}</div>
                            @if($contact['phone'])
                                <div>{{ $contact['phone'] }}</div>
                            @endif
                            @if($contact['email'])
                                <div>{{ $contact['email'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </td>
        </tr>
    </table>

    <table class="grid" style="margin-top: 12px;">
        <tr>
            <td class="main">
                <div class="panel">
                    <h2>Procedure</h2>
                    <ol>
                        @foreach($procedures as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>
                </div>
            </td>
            <td class="side">
                <div class="panel">
                    <h2>Legend</h2>
                    <ul>
                        @foreach($legend as $item)
                            <li>{{ $item['label'] }} ({{ $item['count'] }})</li>
                        @endforeach
                    </ul>
                </div>
                <div class="panel">
                    <h2>Resident Support Notes</h2>
                    <div>{{ $support_notes ?: 'No saved notes. Add site-specific support needs before printing if required.' }}</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">{{ $footer }}</div>
</body>
</html>

