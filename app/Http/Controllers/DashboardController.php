<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $activeStatuses = ['received', 'washing', 'drying', 'quality_check', 'ready'];
        $monthOrders = Order::whereBetween('received_at', [now()->startOfMonth(), now()->endOfMonth()]);
        $monthRevenue = Payment::whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount');
        $outstanding = Order::whereNotIn('status', ['cancelled'])
            ->get()
            ->sum(fn (Order $order) => $order->balance);

        return response()->json([
            'metrics' => [
                'today_orders' => Order::whereDate('received_at', today())->count(),
                'month_clients' => Client::whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
                'month_orders' => (clone $monthOrders)->count(),
                'month_revenue' => (int) $monthRevenue,
                'outstanding' => (int) $outstanding,
                'active_orders' => Order::whereIn('status', $activeStatuses)->count(),
            ],
            'status_counts' => Order::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'recent_orders' => Order::with(['client', 'items', 'payments'])->latest('received_at')->limit(6)->get(),
        ]);
    }
}
