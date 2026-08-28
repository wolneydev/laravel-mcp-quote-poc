<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report['quote_number'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        .banner { border: 1px solid #333; padding: 8px 12px; margin: 0 0 16px; font-weight: bold; }
        .meta { margin: 0 0 16px; }
        .meta p { margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th, td { border: 1px solid #333; padding: 6px 8px; text-align: left; }
        th { background: #eee; }
        .num { text-align: right; }
        .total { font-size: 14px; font-weight: bold; }
        .notes { margin-top: 16px; }
    </style>
</head>
<body>
    <h1>Quote {{ $report['quote_number'] }}</h1>

    @if ($report['status'] !== 'approved')
        <div class="banner">
            This quote is a draft and is not approved. Generating this PDF does not approve the quote.
        </div>
    @endif

    <div class="meta">
        <p>Status: {{ $report['status'] }}</p>
        <p>Seller: {{ $report['seller']['name'] }} ({{ $report['seller']['account_code'] }})</p>
        <p>Customer: {{ $report['customer']['customer_name'] }} ({{ $report['customer']['customer_code'] }} / {{ $report['customer']['account_code'] }})</p>
        @if (filled($report['valid_until']))
            <p>Valid until: {{ $report['valid_until'] }}</p>
        @endif
        <p>{{ $report['approval_summary'] }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Product code</th>
                <th>Product name</th>
                <th class="num">Quantity</th>
                <th>Unit</th>
                <th class="num">Unit price</th>
                <th class="num">Line total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report['items'] as $item)
                <tr>
                    <td>{{ $item['product_code'] }}</td>
                    <td>{{ $item['product_name'] }}</td>
                    <td class="num">{{ $item['quantity'] }}</td>
                    <td>{{ $item['unit'] }}</td>
                    <td class="num">{{ $item['unit_price'] }}</td>
                    <td class="num">{{ $item['line_total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="total">Total: {{ $report['currency'] }} {{ $report['total'] }}</p>

    @if (filled($report['notes']))
        <div class="notes">
            <p>Notes: {{ $report['notes'] }}</p>
        </div>
    @endif
</body>
</html>
