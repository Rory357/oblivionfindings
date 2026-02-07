<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Board Pack - {{ $meeting->title }}</title>
    <style>
        @page { margin: 2cm; }
        body { font-family: Arial, sans-serif; font-size: 11pt; line-height: 1.5; color: #333; }
        .cover { text-align: center; padding-top: 150px; page-break-after: always; }
        .cover h1 { font-size: 28pt; margin-bottom: 20px; }
        .cover .meta { font-size: 14pt; color: #666; margin-top: 40px; }
        h2 { font-size: 18pt; border-bottom: 2px solid #333; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f5f5f5; font-weight: bold; }
        .agenda-item { padding: 15px; border-left: 4px solid #333; background-color: #f9f9f9; margin: 10px 0; }
        .confidential { color: #c00; font-weight: bold; }
        .metric-box { display: inline-block; padding: 15px 25px; background-color: #f5f5f5; margin: 10px; text-align: center; }
        .metric-value { font-size: 24pt; font-weight: bold; }
    </style>
</head>
<body>
    <div class="cover">
        <h1>{{ $meeting->title }}</h1>
        <div class="meta">
            <p><strong>Date:</strong> {{ $meeting->scheduled_at->format('l, j F Y') }}</p>
            <p><strong>Time:</strong> {{ $meeting->scheduled_at->format('g:i A') }}</p>
            @if($meeting->location)<p><strong>Location:</strong> {{ $meeting->location }}</p>@endif
        </div>
        <div style="margin-top: 100px; padding: 20px; border: 2px solid #c00;" class="confidential">
            {{ $watermark ?? 'CONFIDENTIAL - BOARD ONLY' }}
        </div>
    </div>

    <h2>Agenda</h2>
    @foreach($content['agenda'] as $item)
        <div class="agenda-item">
            <strong>{{ $item['order'] }}. {{ $item['title'] }}</strong>
            @if($item['presenter'])<br><small>Presenter: {{ $item['presenter'] }}</small>@endif
        </div>
    @endforeach

    <h2>Risk Summary</h2>
    @if(isset($content['dashboard']['top_risks']))
        <p>Critical Risks: <strong>{{ $content['dashboard']['top_risks']['critical'] }}</strong> | 
           High Risks: <strong>{{ $content['dashboard']['top_risks']['high'] }}</strong></p>
    @endif

    <div style="margin-top: 50px; padding: 20px; background-color: #fee; border: 1px solid #c00;">
        <strong>Confidentiality Notice</strong>
        <p>This document is confidential and intended for board members only.</p>
    </div>
</body>
</html>
