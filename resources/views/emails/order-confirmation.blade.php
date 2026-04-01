<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmation</title>
    <style>
        /* Plain inline styles — email clients don't support external CSS or Tailwind */
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; }
        .wrapper { max-width: 600px; margin: 40px auto; background: #ffffff; border-radius: 8px; overflow: hidden; }
        .header { background: #1e3a5f; padding: 32px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 22px; }
        .body { padding: 32px; color: #333333; }
        .body p { line-height: 1.6; }
        table { width: 100%; border-collapse: collapse; margin: 24px 0; }
        th { background: #f0f4f8; text-align: left; padding: 10px 12px; font-size: 13px; color: #555; }
        td { padding: 10px 12px; border-bottom: 1px solid #eeeeee; font-size: 14px; }
        .totals td { border: none; }
        .totals .label { color: #555; }
        .totals .total-row td { font-weight: bold; font-size: 16px; padding-top: 12px; }
        .footer { background: #f0f4f8; padding: 20px 32px; text-align: center; font-size: 12px; color: #888; }
    </style>
</head>
<body>
<div class="wrapper">

    <div class="header">
        <h1>Order Confirmed</h1>
    </div>

    <div class="body">
        <p>Hi {{ $order->customer_name }},</p>
        <p>
            Thank you for your order. We've received <strong>{{ $order->order_ref }}</strong>
            and it's being processed now.
        </p>

        {{-- Line items table --}}
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th style="text-align:right">Unit Price</th>
                    <th style="text-align:right">Line Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->line_items as $item)
                    @php
                        $unitPence  = intval(round(floatval($item['unit_price']) * 100));
                        $linePence  = $unitPence * intval($item['qty']);
                    @endphp
                    <tr>
                        <td>{{ $item['name'] }}</td>
                        <td>{{ $item['qty'] }}</td>
                        <td style="text-align:right">{{ \App\Models\Order::penceToCurrency($unitPence) }}</td>
                        <td style="text-align:right">{{ \App\Models\Order::penceToCurrency($linePence) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Order totals --}}
        <table class="totals">
            <tr>
                <td class="label">Subtotal</td>
                <td style="text-align:right">{{ $order->formattedSubtotal() }}</td>
            </tr>
            <tr>
                <td class="label">VAT (20%)</td>
                <td style="text-align:right">{{ $order->formattedTax() }}</td>
            </tr>
            <tr class="total-row">
                <td>Total</td>
                <td style="text-align:right">{{ $order->formattedTotal() }}</td>
            </tr>
        </table>

        <p>If you have any questions, reply to this email and we'll be happy to help.</p>
        <p>Thanks,<br>The StoreSync Team</p>
    </div>

    <div class="footer">
        StoreSync &mdash; automated order processing
    </div>

</div>
</body>
</html>
