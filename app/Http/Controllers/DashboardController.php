<?php

namespace App\Http\Controllers;

use App\Models\CleaningSession;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $activeStatuses = ['received', 'cleaning', 'ready_for_collection'];
        $monthOrders = Order::whereBetween('received_at', [now()->startOfMonth(), now()->endOfMonth()]);
        $monthRevenue = Payment::whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount');
        $outstanding = Order::whereNotIn('status', ['cancelled'])
            ->get()
            ->sum(fn (Order $order) => $order->balance);

        $pipelineCounts = array_merge(
            array_fill_keys($activeStatuses, 0),
            CleaningSession::whereIn('status', $activeStatuses)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($total) => (int) $total)
                ->all(),
        );

        return response()->json([
            'metrics' => [
                'today_orders' => Order::whereDate('received_at', today())->count(),
                'month_clients' => Client::whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
                'month_orders' => (clone $monthOrders)->count(),
                'month_revenue' => (int) $monthRevenue,
                'outstanding' => (int) $outstanding,
                'active_orders' => CleaningSession::whereIn('status', $activeStatuses)->count(),
            ],
            'pipeline_counts' => $pipelineCounts,
            'active_sessions' => CleaningSession::with(['shoe', 'order.client', 'order.payments'])
                ->whereIn('status', $activeStatuses)
                ->latest('received_at')
                ->get(),
            'recent_orders' => Order::with(['client', 'cleaningSessions.shoe', 'payments'])->latest('received_at')->limit(6)->get(),
        ]);
    }
}
