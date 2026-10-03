<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('db.Stock Report') }} - {{ $general_setting->site_title ?? 'SalePro' }}</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        body {
            color: #222;
            background: #fff;
            margin: 0;
            padding: 20px;
            font-size: 12px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #333;
            padding-bottom: 12px;
        }
        .header h1 {
            margin: 0 0 6px 0;
            font-size: 20px;
            text-transform: uppercase;
        }
        .header h2 {
            margin: 0 0 10px 0;
            font-size: 15px;
            color: #555;
            font-weight: normal;
        }
        .filter-info {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 15px;
            font-size: 11px;
            color: #444;
            margin-top: 6px;
        }
        .filter-item strong {
            color: #111;
        }
        .summary-cards {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 20px;
        }
        .summary-card {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
            background: #f9f9f9;
        }
        .summary-card .title {
            font-size: 10px;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 4px;
        }
        .summary-card .val {
            font-size: 14px;
            font-weight: bold;
            color: #111;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 11px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 6px 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: 600;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        tfoot th {
            background-color: #e9ecef;
            font-weight: bold;
        }
        .no-print {
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn {
            display: inline-block;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 500;
            color: #fff;
            background: #007bff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-secondary {
            background: #6c757d;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                padding: 0;
            }
            @page {
                size: landscape;
                margin: 10mm;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <div>
            <button onclick="window.print()" class="btn">Print / Save as PDF</button>
            <button onclick="window.close()" class="btn btn-secondary">Close</button>
        </div>
        <div>
            <span style="color: #666; font-size: 11px;">Total items: {{ count($formattedStocks) }}</span>
        </div>
    </div>

    <div class="header">
        <h1>{{ $general_setting->site_title ?? 'SalePro' }}</h1>
        <h2>{{ __('db.Stock Report') }}</h2>
        <div class="filter-info">
            <div class="filter-item"><strong>{{ __('db.Warehouse') }}:</strong> {{ $warehouseName }}</div>
            <div class="filter-item"><strong>{{ __('db.category') }}:</strong> {{ $categoryName }}</div>
            <div class="filter-item"><strong>{{ __('db.Brand') }}:</strong> {{ $brandName }}</div>
            <div class="filter-item"><strong>{{ __('db.Supplier') }}:</strong> {{ $supplierName }}</div>
            <div class="filter-item"><strong>{{ __('db.status') }}:</strong> {{ $stockStatus }}</div>
            <div class="filter-item"><strong>{{ __('db.date') }}:</strong> {{ date(config('date_format', 'd-m-Y')) }}</div>
        </div>
    </div>

    <div class="summary-cards">
        <div class="summary-card">
            <div class="title">{{ __('db.Stock Value by Cost') }}</div>
            <div class="val">{{ config('currency') }} {{ number_format($summary['closing_stock_cost'], (int) config('decimal', 2)) }}</div>
        </div>
        <div class="summary-card">
            <div class="title">{{ __('db.Stock Value by Price') }}</div>
            <div class="val">{{ config('currency') }} {{ number_format($summary['closing_stock_price'], (int) config('decimal', 2)) }}</div>
        </div>
        <div class="summary-card">
            <div class="title">{{ __('db.profit') }}</div>
            <div class="val">{{ config('currency') }} {{ number_format($summary['potential_profit'], (int) config('decimal', 2)) }}</div>
        </div>
        <div class="summary-card">
            <div class="title">{{ __('db.Profit Margin') }}</div>
            <div class="val">{{ $summary['profit_margin'] }}%</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>{{ __('db.Code') }}</th>
                <th>{{ __('db.Product') }}</th>
                <th>{{ __('db.Variant') }}</th>
                <th>{{ __('db.category') }}</th>
                <th>{{ __('db.Warehouse') }}</th>
                <th class="text-right">{{ __('db.Unit Cost') }}</th>
                <th class="text-right">{{ __('db.Unit Price') }}</th>
                <th class="text-right">{{ __('db.stock') }}</th>
                <th class="text-right">{{ __('db.Stock Value by Cost') }}</th>
                <th class="text-right">{{ __('db.Stock Value by Price') }}</th>
                <th class="text-right">{{ __('db.profit') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($formattedStocks as $stock)
                <tr>
                    <td>{{ $stock['code'] }}</td>
                    <td>{{ $stock['name'] }}</td>
                    <td>{{ $stock['variant'] }}</td>
                    <td>{{ $stock['category'] }}</td>
                    <td>{{ $stock['warehouse'] }}</td>
                    <td class="text-right">{{ number_format($stock['cost'], (int) config('decimal', 2)) }}</td>
                    <td class="text-right">{{ number_format($stock['price'], (int) config('decimal', 2)) }}</td>
                    <td class="text-right">{{ number_format($stock['qty'], (int) config('decimal', 2)) }}</td>
                    <td class="text-right">{{ number_format($stock['stock_cost'], (int) config('decimal', 2)) }}</td>
                    <td class="text-right">{{ number_format($stock['stock_price'], (int) config('decimal', 2)) }}</td>
                    <td class="text-right">{{ number_format($stock['profit'], (int) config('decimal', 2)) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="text-center" style="padding: 20px; color: #888;">
                        {{ __('db.No data available') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <th colspan="7" class="text-right">{{ __('db.Total') }}:</th>
                <th class="text-right">{{ number_format($summary['total_qty'], (int) config('decimal', 2)) }}</th>
                <th class="text-right">{{ number_format($summary['closing_stock_cost'], (int) config('decimal', 2)) }}</th>
                <th class="text-right">{{ number_format($summary['closing_stock_price'], (int) config('decimal', 2)) }}</th>
                <th class="text-right">{{ number_format($summary['potential_profit'], (int) config('decimal', 2)) }}</th>
            </tr>
        </tfoot>
    </table>

    <script>
        @if (request('pdf') == 1)
            window.onload = function() {
                window.print();
            };
        @endif
    </script>
</body>
</html>
