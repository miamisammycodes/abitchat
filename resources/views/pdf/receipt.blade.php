<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Receipt - {{ $transaction->transaction_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
            padding: 40px;
        }
        .header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e5e5e5;
        }
        .header h1 {
            font-size: 24px;
            color: #1a1a1a;
            margin-bottom: 5px;
        }
        .header .subtitle {
            color: #666;
            font-size: 14px;
        }
        .receipt-badge {
            display: inline-block;
            background: #22c55e;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            margin-top: 10px;
        }
        .receipt-badge.pending {
            background: #f59e0b;
        }
        .receipt-badge.rejected {
            background: #ef4444;
        }
        .company-info {
            margin-bottom: 30px;
        }
        .company-info h2 {
            font-size: 18px;
            color: #1a1a1a;
            margin-bottom: 5px;
        }
        .company-info p {
            color: #666;
            font-size: 11px;
        }
        .receipt-details {
            margin-bottom: 30px;
        }
        .receipt-details table {
            width: 100%;
            border-collapse: collapse;
        }
        .receipt-details th,
        .receipt-details td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e5e5e5;
        }
        .receipt-details th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            width: 40%;
        }
        .receipt-details td {
            color: #1a1a1a;
        }
        .amount-box {
            background: #f0fdf4;
            border: 2px solid #22c55e;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 30px 0;
        }
        .amount-box .label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .amount-box .amount {
            font-size: 28px;
            font-weight: bold;
            color: #16a34a;
            margin-top: 5px;
        }
        .plan-info {
            background: #f9fafb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .plan-info h3 {
            font-size: 14px;
            color: #1a1a1a;
            margin-bottom: 10px;
        }
        .plan-info p {
            color: #666;
            font-size: 11px;
            margin-bottom: 5px;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e5e5;
            text-align: center;
            color: #999;
            font-size: 10px;
        }
        .footer p {
            margin-bottom: 5px;
        }
        .two-columns {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }
        .two-columns .column {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }
        .two-columns .column.right {
            text-align: right;
        }
        .info-block {
            margin-bottom: 15px;
        }
        .info-block .label {
            font-size: 10px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }
        .info-block .value {
            font-size: 13px;
            color: #1a1a1a;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Payment Receipt</h1>
        <p class="subtitle">Thank you for your payment</p>
        <span class="receipt-badge {{ $transaction->status }}">
            {{ ucfirst($transaction->status) }}
        </span>
    </div>

    <div class="two-columns">
        <div class="column">
            <div class="company-info">
                <h2>AbitChat</h2>
                <p>AI-Powered Chatbot SaaS</p>
                <p>Thimphu, Bhutan</p>
            </div>
        </div>
        <div class="column right">
            <div class="info-block">
                <div class="label">Receipt Number</div>
                <div class="value">{{ $transaction->transaction_number }}</div>
            </div>
            <div class="info-block">
                <div class="label">Payment Date</div>
                <div class="value">{{ $transaction->payment_date?->format('F j, Y') }}</div>
            </div>
            <div class="info-block">
                <div class="label">Issue Date</div>
                <div class="value">{{ $transaction->created_at->format('F j, Y') }}</div>
            </div>
        </div>
    </div>

    <div class="receipt-details">
        <table>
            <tr>
                <th>Customer</th>
                <td>{{ $transaction->tenant?->name ?? 'N/A' }}</td>
            </tr>
            <tr>
                <th>Plan</th>
                <td>{{ $transaction->plan?->name ?? 'N/A' }}</td>
            </tr>
            <tr>
                <th>Billing Period</th>
                <td>{{ ucfirst($transaction->plan?->billing_period ?? 'monthly') }}</td>
            </tr>
            <tr>
                <th>Bank</th>
                <td>
                    @php
                        $banks = [
                            'bob' => 'Bank of Bhutan',
                            'bnb' => 'Bhutan National Bank',
                            'dpnb' => 'Druk PNB Ltd',
                            'bdbl' => 'Bhutan Development Bank Ltd.',
                            'tbank' => 'T Bank Ltd',
                            'dk' => 'Dk.',
                            // Legacy
                            'bank_transfer' => 'Bank Transfer',
                            'upi' => 'UPI',
                            'card' => 'Card',
                            'cash' => 'Cash',
                            'other' => 'Other',
                        ];
                    @endphp
                    {{ $banks[$transaction->payment_method] ?? $transaction->payment_method ?? 'N/A' }}
                </td>
            </tr>
            <tr>
                <th>Transaction Number</th>
                <td>{{ $transaction->transaction_number }}</td>
            </tr>
            @if($transaction->reference_number)
            <tr>
                <th>Reference Number</th>
                <td>{{ $transaction->reference_number }}</td>
            </tr>
            @endif
            @if($transaction->status === 'approved' && $transaction->approved_at)
            <tr>
                <th>Approved On</th>
                <td>{{ $transaction->approved_at->format('F j, Y \a\t g:i A') }}</td>
            </tr>
            @endif
        </table>
    </div>

    <div class="amount-box">
        <div class="label">Amount Paid</div>
        <div class="amount">Nu. {{ number_format($transaction->amount, 2) }}</div>
    </div>

    @if($transaction->plan)
    <div class="plan-info">
        <h3>Plan Details: {{ $transaction->plan->name }}</h3>
        <p>{{ $transaction->plan->description }}</p>
        @if($transaction->plan->features && count($transaction->plan->features) > 0)
        <p style="margin-top: 10px;"><strong>Features:</strong></p>
        <ul style="margin-left: 20px; margin-top: 5px;">
            @foreach($transaction->plan->features as $feature)
            <li style="color: #666; font-size: 11px;">{{ $feature }}</li>
            @endforeach
        </ul>
        @endif
    </div>
    @endif

    @if($transaction->notes)
    <div style="margin-bottom: 20px;">
        <strong>Customer Notes:</strong>
        <p style="color: #666; margin-top: 5px;">{{ $transaction->notes }}</p>
    </div>
    @endif

    <div class="footer">
        <p>This is an automatically generated receipt.</p>
        <p>For any queries, please contact support@abitchat.com</p>
        <p style="margin-top: 10px;">&copy; {{ date('Y') }} AbitChat. All rights reserved.</p>
    </div>
</body>
</html>
