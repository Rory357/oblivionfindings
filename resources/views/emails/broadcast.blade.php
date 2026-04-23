@php
$headerColour = \App\Models\AppSetting::query()
    ->where('key', 'branding.email_header_colour')
    ->value('value') ?? '#7c3aed';
$appName = \App\Models\AppSetting::query()
    ->where('key', 'branding.name')
    ->value('value') ?? config('app.name');
$footerText = \App\Models\AppSetting::query()
    ->where('key', 'branding.email_footer_text')
    ->value('value');
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $subject ?? 'Broadcast Message' }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0"
                       style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:{{ $headerColour }};padding:16px 24px;color:#ffffff;font-weight:600;font-size:15px;">
                            {{ $appName }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;color:#1f2937;font-size:14px;line-height:1.55;">
                            @if (! empty($templateLabel))
                                <p style="margin:0 0 8px 0;font-size:12px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;">
                                    {{ $templateLabel }}
                                </p>
                            @endif
                            <div style="white-space:pre-wrap;">{{ $body }}</div>
                        </td>
                    </tr>
                    @if (! empty($footerText))
                        <tr>
                            <td style="padding:16px 24px;background:#f9fafb;color:#6b7280;font-size:12px;border-top:1px solid #e5e7eb;">
                                {{ $footerText }}
                            </td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
