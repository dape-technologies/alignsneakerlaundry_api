<?php

namespace App\Http\Controllers;

use App\Models\CleaningSession;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $activeStatuses = ['received', 'cleaning', 'ready_for_collection'];
        $monthOrders = Order::whereBetween('received_at', [now()->startOfMonth(), now()->endOfMonth()]);
        $monthRevenue = Payment::whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->whereHas('order', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->sum('amount');
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
            'active_sessions' => CleaningSession::with(['shoe', 'tag', 'order.client', 'order.payments'])
                ->whereIn('status', $activeStatuses)
                ->latest('received_at')
                ->get(),
            'recent_orders' => Order::with(['client', 'cleaningSessions.shoe', 'cleaningSessions.tag', 'payments'])->latest('received_at')->limit(6)->get(),
            'cancelled_orders' => $request->user()->role === 'admin'
                ? Order::with([
                    'client', 'payments', 'cleaningSessions.shoe', 'cleaningSessions.tag',
                    'cleaningSessions.statusHistory.changer:id,name',
                ])->where('status', 'cancelled')->latest('updated_at')->limit(20)->get()
                : [],
        ]);
    }
}
