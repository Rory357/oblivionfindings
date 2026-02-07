<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Contact Form Submission</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: #ffffff;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 2px solid #6366f1;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #6366f1;
            margin: 0;
            font-size: 24px;
        }
        .field {
            margin-bottom: 20px;
        }
        .field-label {
            font-weight: 600;
            color: #666;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        .field-value {
            color: #333;
            font-size: 16px;
        }
        .message-box {
            background-color: #f8f9fa;
            border-left: 4px solid #6366f1;
            padding: 15px;
            border-radius: 0 8px 8px 0;
            white-space: pre-wrap;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 12px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📬 New Contact Form Submission</h1>
        </div>

        <div class="field">
            <div class="field-label">Name</div>
            <div class="field-value">{{ $name }}</div>
        </div>

        <div class="field">
            <div class="field-label">Email</div>
            <div class="field-value">
                <a href="mailto:{{ $email }}">{{ $email }}</a>
            </div>
        </div>

        <div class="field">
            <div class="field-label">Company</div>
            <div class="field-value">{{ $company }}</div>
        </div>

        <div class="field">
            <div class="field-label">Phone</div>
            <div class="field-value">{{ $phone }}</div>
        </div>

        <div class="field">
            <div class="field-label">Service Type</div>
            <div class="field-value">{{ $service_type }}</div>
        </div>

        <div class="field">
            <div class="field-label">Number of Residents</div>
            <div class="field-value">{{ $residents_count }}</div>
        </div>

        <div class="field">
            <div class="field-label">Message</div>
            <div class="message-box">{{ $message }}</div>
        </div>

        <div class="footer">
            <p>This message was sent from the Oblivion Findings contact form.</p>
            <p>© {{ date('Y') }} Oblivion Findings. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
