@php
    $at = fn (?string $iso) => $iso ? \Carbon\CarbonImmutable::parse($iso)->setTimezone($zone)->format('j M Y, g:i a') : '—';
    $kind = fn (array $conversation) => match ($conversation['type']) {
        'channel' => 'Team channel',
        'group' => 'Private group',
        default => 'Direct chat',
    };
    $stamp = $caseReference.' · '.$investigator.' · '.$exportedAt;
@endphp
<!DOCTYPE html>
<html lang="en-NZ">
<head>
    <meta charset="utf-8">
    <title>Chat investigation record · {{ $caseReference }}</title>
    <style>
        @page { margin: 92px 40px 64px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #1f2430; line-height: 1.45; }
        .page-header { position: fixed; top: -72px; left: 0; right: 0; height: 56px; border-bottom: 1px solid #9aa0ad; }
        .page-header .label { font-size: 8px; letter-spacing: 1px; text-transform: uppercase; font-weight: bold; color: #7a1f1f; }
        .page-header .stamp { font-size: 9px; margin-top: 3px; }
        .page-footer { position: fixed; bottom: -44px; left: 0; right: 0; height: 30px; border-top: 1px solid #9aa0ad; font-size: 7px; color: #555b66; padding-top: 4px; }
        .watermark { position: fixed; top: 300px; left: -60px; width: 700px; text-align: center; transform: rotate(-35deg); font-size: 26px; font-weight: bold; color: #c9ccd3; opacity: 0.45; }
        .watermark span { display: block; font-size: 14px; font-weight: normal; margin-top: 6px; }
        h1 { font-size: 15px; margin: 0 0 6px; }
        h2 { font-size: 12px; margin: 18px 0 4px; padding-bottom: 3px; border-bottom: 1px solid #c9ccd3; }
        .summary td { padding: 2px 10px 2px 0; vertical-align: top; }
        .summary td.key { font-weight: bold; white-space: nowrap; }
        .note { margin-top: 8px; padding: 6px 8px; border: 1px solid #c9ccd3; background: #f3f4f6; }
        .message { margin: 6px 0; padding: 5px 7px; border-left: 2px solid #9aa0ad; page-break-inside: avoid; }
        .message.removed { border-left-color: #7a1f1f; background: #f8eeee; }
        .meta { color: #555b66; font-size: 8px; }
        .tag { font-size: 7.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; color: #7a1f1f; }
        .content { white-space: pre-wrap; margin-top: 2px; }
        .version { margin: 3px 0 0 10px; padding: 3px 6px; border: 1px dashed #c9ccd3; }
        .empty { color: #555b66; font-style: italic; }
    </style>
</head>
<body>
    <div class="watermark">{{ $watermark }}<span>{{ $stamp }}</span></div>
    <div class="page-header">
        <div class="label">{{ $watermark }}</div>
        <div class="stamp">Case {{ $caseReference }} · Exported by {{ $investigator }} · {{ $exportedAt }}</div>
    </div>
    <div class="page-footer">
        Export reference {{ $exportId }}. This record was exported under authorised, time-limited case access and the export is logged.
        Do not copy or share it beyond the case. Times are New Zealand time.
    </div>

    <h1>Chat investigation record</h1>
    <table class="summary">
        <tr><td class="key">Case reference</td><td>{{ $caseReference }}</td></tr>
        <tr><td class="key">Exported by</td><td>{{ $investigator }}</td></tr>
        <tr><td class="key">Exported</td><td>{{ $exportedAt }}</td></tr>
        <tr><td class="key">Case access ends</td><td>{{ $expiresAt }}</td></tr>
        <tr><td class="key">Conversations</td><td>{{ count($sections) }}</td></tr>
    </table>
    <div class="note">
        This is the complete record of each conversation below, including messages the sender removed and earlier
        versions of edited messages. Attachments are listed by name only; their contents are not included.
        @if ($omitted > 0)
            {{ $omitted }} {{ $omitted === 1 ? 'conversation' : 'conversations' }} in this case {{ $omitted === 1 ? 'is' : 'are' }}
            not included because access to {{ $omitted === 1 ? 'it' : 'them' }} has ended.
        @endif
    </div>

    @foreach ($sections as $section)
        @php $conversation = $section['conversation']; @endphp
        <h2>{{ $kind($conversation) }} · {{ $conversation['title'] ?: implode(', ', $conversation['members']) }}</h2>
        <div class="meta">
            Members: {{ implode(', ', $conversation['members']) ?: 'None remaining' }} ·
            Started {{ $at($conversation['created_at']) }} ·
            {{ count($section['messages']) }} {{ count($section['messages']) === 1 ? 'message' : 'messages' }}
            @if ($conversation['held']) · Preservation hold active @endif
        </div>
        @forelse ($section['messages'] as $message)
            <div class="message {{ $message['removed_at'] ? 'removed' : '' }}">
                <div class="meta">
                    <strong>{{ $message['sender'] }}</strong> · {{ $at($message['created_at']) }}
                    @if ($message['edited_at']) · <span class="tag">Edited</span> {{ $at($message['edited_at']) }} @endif
                    @if ($message['removed_at']) · <span class="tag">Removed by sender</span> {{ $at($message['removed_at']) }} @endif
                </div>
                <div class="content">{{ $message['content'] }}</div>
                @foreach ($message['attachments'] as $file)
                    <div class="meta">Attachment: {{ $file['name'] }} ({{ $file['mime_type'] }}, {{ number_format($file['size_bytes'] / 1024, 1) }} KB) — contents not included</div>
                @endforeach
                @foreach ($message['previous_versions'] as $version)
                    <div class="version">
                        <div class="meta">Earlier version {{ $version['version'] }} · replaced {{ $at($version['replaced_at']) }}</div>
                        <div class="content">{{ $version['content'] ?? 'Earlier text unavailable' }}</div>
                    </div>
                @endforeach
            </div>
        @empty
            <p class="empty">This conversation has no messages.</p>
        @endforelse
    @endforeach
</body>
</html>
