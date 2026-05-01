<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report->report_name }}</title>
    <style>
        body {
            color: #111827;
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.45;
        }

        h1 {
            font-size: 22px;
            margin: 0 0 4px;
        }

        h2 {
            font-size: 14px;
            margin: 24px 0 8px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th,
        td {
            border-bottom: 1px solid #e5e7eb;
            padding: 7px 6px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f3f4f6;
            font-weight: 700;
        }

        .meta {
            color: #4b5563;
            margin-bottom: 18px;
        }

        .summary {
            margin-top: 18px;
        }

        .summary td:first-child {
            color: #4b5563;
            width: 70%;
        }

        .amount {
            text-align: right;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <h1>{{ $report->report_name }}</h1>
    <div class="meta">
        {{ $fund->fund_name }} ({{ $fund->fund_code }})<br>
        Period: {{ $report->period_from->toDateString() }} to {{ $report->period_to->toDateString() }}
    </div>

    <table class="summary">
        <tr>
            <td>Opening balance</td>
            <td class="amount">${{ number_format((float) $report->opening_balance, 2) }}</td>
        </tr>
        <tr>
            <td>Total receipts</td>
            <td class="amount">${{ number_format((float) $report->total_receipts, 2) }}</td>
        </tr>
        <tr>
            <td>Total expenditure</td>
            <td class="amount">${{ number_format((float) $report->total_expenditure, 2) }}</td>
        </tr>
        <tr>
            <td>Closing balance</td>
            <td class="amount">${{ number_format((float) $report->closing_balance, 2) }}</td>
        </tr>
    </table>

    <h2>Transactions</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Description</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($transactions as $transaction)
                <tr>
                    <td>{{ data_get($transaction, 'transaction_date') }}</td>
                    <td>{{ ucfirst((string) data_get($transaction, 'type')) }}</td>
                    <td>{{ data_get($transaction, 'description') }}</td>
                    <td class="amount">${{ number_format((float) data_get($transaction, 'amount', 0), 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">No transactions in this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
