<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 12px;
            color: #333;
            line-height: 1.5;
        }
        .container {
            padding: 40px;
        }
        .header {
            margin-bottom: 30px;
            overflow: hidden;
        }
        .header-left {
            float: left;
            width: 50%;
        }
        .header-right {
            float: right;
            width: 50%;
            text-align: right;
        }
        .company-name {
            font-size: 22px;
            font-weight: bold;
            color: #1a1a1a;
            margin-bottom: 5px;
        }
        .invoice-title {
            font-size: 28px;
            font-weight: bold;
            color: #2563eb;
            margin-bottom: 8px;
        }
        .invoice-meta {
            margin-bottom: 4px;
            font-size: 11px;
        }
        .invoice-meta strong {
            display: inline-block;
            min-width: 100px;
        }
        .divider {
            border-top: 2px solid #2563eb;
            margin: 20px 0;
        }
        .billing-section {
            margin-bottom: 30px;
            overflow: hidden;
        }
        .bill-to {
            float: left;
            width: 50%;
        }
        .bill-to-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6b7280;
            margin-bottom: 5px;
        }
        .client-name {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 3px;
        }
        .client-detail {
            font-size: 11px;
            color: #4b5563;
        }
        .status-badge {
            float: right;
            padding: 6px 16px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-draft { background: #f3f4f6; color: #4b5563; }
        .status-sent { background: #dbeafe; color: #1d4ed8; }
        .status-viewed { background: #e0e7ff; color: #4338ca; }
        .status-paid { background: #dcfce7; color: #166534; }
        .status-overdue { background: #fee2e2; color: #991b1b; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        table.line-items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table.line-items thead th {
            background: #f8fafc;
            border-bottom: 2px solid #e5e7eb;
            padding: 10px 8px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
        }
        table.line-items thead th.text-right {
            text-align: right;
        }
        table.line-items tbody td {
            padding: 10px 8px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
        }
        table.line-items tbody td.text-right {
            text-align: right;
        }
        .totals-section {
            float: right;
            width: 280px;
            margin-top: 10px;
        }
        .totals-row {
            overflow: hidden;
            padding: 6px 0;
        }
        .totals-label {
            float: left;
            color: #6b7280;
            font-size: 11px;
        }
        .totals-value {
            float: right;
            font-size: 11px;
        }
        .totals-divider {
            border-top: 1px solid #e5e7eb;
            margin: 4px 0;
        }
        .totals-grand {
            font-size: 16px;
            font-weight: bold;
        }
        .totals-grand .totals-label,
        .totals-grand .totals-value {
            font-size: 16px;
            color: #1a1a1a;
        }
        .notes-section {
            clear: both;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        .notes-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6b7280;
            margin-bottom: 5px;
        }
        .notes-text {
            font-size: 11px;
            color: #4b5563;
            white-space: pre-wrap;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #2563eb;
            text-align: center;
            font-size: 10px;
            color: #9ca3af;
        }
        .bank-details {
            margin-top: 30px;
            padding: 15px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
        }
        .bank-details-title {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6b7280;
            margin-bottom: 8px;
        }
        .bank-details-line {
            font-size: 11px;
            color: #4b5563;
            margin-bottom: 2px;
        }
        .clearfix::after {
            content: "";
            display: table;
            clear: both;
        }
    </style>
</head>
<body>
    <div class="container">
        {{-- Header --}}
        <div class="header clearfix">
            <div class="header-left">
                <div class="company-name">{{ config('app.name', 'Your Company') }}</div>
                <div style="font-size: 11px; color: #6b7280;">Tax Invoice</div>
            </div>
            <div class="header-right">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-meta">
                    <strong>Invoice #:</strong> {{ $invoice->invoice_number }}
                </div>
                <div class="invoice-meta">
                    <strong>Date:</strong> {{ $invoice->invoice_date->format('j F Y') }}
                </div>
                <div class="invoice-meta">
                    <strong>Due Date:</strong> {{ $invoice->due_date->format('j F Y') }}
                </div>
                <div class="invoice-meta">
                    <strong>Currency:</strong> {{ $invoice->currency_code }}
                </div>
            </div>
        </div>

        <div class="divider"></div>

        {{-- Client Details --}}
        <div class="billing-section clearfix">
            <div class="bill-to">
                <div class="bill-to-label">Bill To</div>
                <div class="client-name">{{ $invoice->client_name }}</div>
                @if($invoice->client_email)
                    <div class="client-detail">{{ $invoice->client_email }}</div>
                @endif
                @if($invoice->client_address)
                    <div class="client-detail">{!! nl2br(e($invoice->client_address)) !!}</div>
                @endif
            </div>
            <div style="float: right;">
                <span class="status-badge status-{{ $invoice->status }}">
                    {{ ucfirst($invoice->status) }}
                </span>
            </div>
        </div>

        {{-- Line Items --}}
        <table class="line-items">
            <thead>
                <tr>
                    <th style="width: 40%;">Description</th>
                    <th class="text-right" style="width: 10%;">Qty</th>
                    <th class="text-right" style="width: 15%;">Unit Price</th>
                    <th class="text-right" style="width: 15%;">GST</th>
                    <th class="text-right" style="width: 20%;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->lines as $line)
                    <tr>
                        <td>
                            {{ $line->description }}
                            @if($line->account)
                                <br><span style="font-size: 9px; color: #9ca3af;">{{ $line->account->code }} - {{ $line->account->name }}</span>
                            @endif
                        </td>
                        <td class="text-right">{{ number_format($line->quantity, 2) }}</td>
                        <td class="text-right">${{ number_format($line->unit_price, 2) }}</td>
                        <td class="text-right">${{ number_format($line->tax_amount, 2) }}</td>
                        <td class="text-right"><strong>${{ number_format($line->line_total, 2) }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Totals --}}
        <div class="totals-section">
            <div class="totals-row">
                <span class="totals-label">Subtotal</span>
                <span class="totals-value">${{ number_format($invoice->subtotal, 2) }}</span>
            </div>
            <div class="totals-row">
                <span class="totals-label">GST (15%)</span>
                <span class="totals-value">${{ number_format($invoice->tax_amount, 2) }}</span>
            </div>
            <div class="totals-divider"></div>
            <div class="totals-row totals-grand">
                <span class="totals-label">Total {{ $invoice->currency_code }}</span>
                <span class="totals-value">${{ number_format($invoice->total_amount, 2) }}</span>
            </div>
        </div>

        <div style="clear: both;"></div>

        {{-- Payment Terms --}}
        @if($invoice->terms)
            <div class="notes-section">
                <div class="notes-label">Payment Terms</div>
                <div class="notes-text">{{ $invoice->terms }}</div>
            </div>
        @endif

        {{-- Notes --}}
        @if($invoice->notes)
            <div class="notes-section">
                <div class="notes-label">Notes</div>
                <div class="notes-text">{{ $invoice->notes }}</div>
            </div>
        @endif

        {{-- Bank Details --}}
        <div class="bank-details">
            <div class="bank-details-title">Bank Details for Payment</div>
            <div class="bank-details-line"><strong>Bank:</strong> [Your Bank Name]</div>
            <div class="bank-details-line"><strong>Account Name:</strong> [Your Account Name]</div>
            <div class="bank-details-line"><strong>Account Number:</strong> [XX-XXXX-XXXXXXX-XXX]</div>
            <div class="bank-details-line"><strong>Reference:</strong> {{ $invoice->invoice_number }}</div>
        </div>

        {{-- Footer --}}
        <div class="footer">
            <p>Thank you for your business.</p>
            <p>Please include the invoice number as payment reference.</p>
        </div>
    </div>
</body>
</html>
