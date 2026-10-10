<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->date('from')?->startOfDay() ?? now()->startOfMonth();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();

        $orders = Order::whereBetween('received_at', [$from, $to]);
        $payments = Payment::whereBetween('paid_at', [$from, $to]);

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'orders' => (clone $orders)->count(),
            'clients' => Client::whereHas('orders', fn ($query) => $query->whereBetween('received_at', [$from, $to]))->count(),
            'quoted_revenue' => (int) (clone $orders)->where('status', '!=', 'cancelled')->sum('total'),
            'collected_revenue' => (int) (clone $payments)->sum('amount'),
            'outstanding' => (int) (clone $orders)->where('status', '!=', 'cancelled')->get()->sum(fn (Order $order) => $order->balance),
            'average_order_value' => (int) round((clone $orders)->where('status', '!=', 'cancelled')->avg('total') ?? 0),
            'status_counts' => (clone $orders)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'daily_revenue' => (clone $payments)
                ->selectRaw('date(paid_at) as date, sum(amount) as total')
                ->groupBy('date')
                ->orderBy('date')
                ->get(),
        ]);
    }
}
