<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Thank you for contacting Oblivion Findings</title>
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
        .logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 16px;
            color: white;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .header h1 {
            color: #333;
            margin: 0;
            font-size: 24px;
        }
        .content {
            color: #555;
        }
        .content p {
            margin-bottom: 16px;
        }
        .next-steps {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .next-steps h2 {
            margin-top: 0;
            font-size: 18px;
            color: #333;
        }
        .next-steps ol {
            margin: 0;
            padding-left: 20px;
        }
        .next-steps li {
            margin-bottom: 8px;
            color: #555;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white;
            text-decoration: none;
            padding: 14px 28px;
            border-radius: 8px;
            font-weight: 600;
            margin: 20px 0;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 12px;
            color: #999;
        }
        .social-links {
            margin-top: 15px;
        }
        .social-links a {
            color: #6366f1;
            text-decoration: none;
            margin: 0 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">OF</div>
            <h1>Thank you for getting in touch!</h1>
        </div>

        <div class="content">
            <p>Hi {{ $name }},</p>

            <p>We've received your message and wanted to let you know that we're on it. 
            One of our team members will review your enquiry and get back to you within 24 hours.</p>

            <div class="next-steps">
                <h2>What happens next?</h2>
                <ol>
                    <li>Our team reviews your requirements</li>
                    <li>We prepare a personalised demo tailored to your service</li>
                    <li>A team member contacts you to schedule at your convenience</li>
                    <li>You see exactly how Oblivion Findings can help your organisation</li>
                </ol>
            </div>

            <p>In the meantime, feel free to explore our website to learn more about our features 
            and how we're helping supported living providers across New Zealand.</p>

            <center>
                <a href="{{ config('app.url') }}/features" class="cta-button">Explore Features</a>
            </center>

            <p>If you have any urgent questions, you can reach us directly at <a href="mailto:hello@oblivionfindings.co.uk">hello@oblivionfindings.co.uk</a> 
            or call us on <a href="tel:+442012345678">020 1234 5678</a>.</p>

            <p>Best regards,<br>
            <strong>The Oblivion Findings Team</strong></p>
        </div>

        <div class="footer">
            <p>© {{ date('Y') }} Oblivion Findings. All rights reserved.</p>
            <p>London, United Kingdom</p>
            <div class="social-links">
                <a href="{{ config('app.url') }}">Website</a>
                <a href="{{ config('app.url') }}/contact">Contact</a>
            </div>
        </div>
    </div>
</body>
</html>
