<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Order;
use App\Notifications\OrderReadyNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::query()
            ->with(['client', 'creator:id,name', 'items', 'payments'])
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->string('search')->toString(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhereHas('client', fn ($query) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%"));
                });
            })
            ->latest('received_at')
            ->paginate(20);

        return response()->json($orders);
    }

    public function show(Order $order)
    {
        return response()->json($order->load([
            'client', 'creator:id,name', 'items.photos', 'photos', 'payments.recorder:id,name',
            'statusHistory.changer:id,name',
        ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'client.name' => ['required', 'string', 'max:120'],
            'client.phone' => ['required', 'string', 'max:30'],
            'client.email' => ['nullable', 'email', 'max:190'],
            'client.notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.shoe_type' => ['required', 'string', 'max:100'],
            'items.*.brand' => ['nullable', 'string', 'max:100'],
            'items.*.color' => ['nullable', 'string', 'max:100'],
            'items.*.service' => ['required', 'string', 'max:120'],
            'items.*.condition_notes' => ['nullable', 'string', 'max:2000'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'deposit' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['nullable', Rule::in(['cash', 'mobile_money', 'card', 'bank'])],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
        ]);

        $phone = $this->normalizePhone($data['client']['phone']);
        $subtotal = collect($data['items'])->sum(fn ($item) => $item['quantity'] * $item['unit_price']);
        $discount = min((int) ($data['discount'] ?? 0), $subtotal);
        $total = $subtotal - $discount;

        if (($data['deposit'] ?? 0) > $total) {
            throw ValidationException::withMessages(['deposit' => ['The deposit cannot exceed the order total.']]);
        }

        $order = DB::transaction(function () use ($request, $data, $phone, $subtotal, $discount, $total) {
            $client = Client::updateOrCreate(
                ['phone' => $phone],
                [
                    'name' => $data['client']['name'],
                    'email' => $data['client']['email'] ?? null,
                    'notes' => $data['client']['notes'] ?? null,
                ]
            );

            $order = Order::create([
                'client_id' => $client->id,
                'created_by' => $request->user()->id,
                'status' => 'received',
                'received_at' => now(),
                'due_at' => $data['due_at'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            $order->update(['reference' => 'ASL-'.now()->format('ymd').'-'.str_pad((string) $order->id, 4, '0', STR_PAD_LEFT)]);

            foreach ($data['items'] as $item) {
                $order->items()->create($item);
            }

            foreach ($request->file('photos', []) as $photo) {
                $order->photos()->create([
                    'path' => $photo->store("orders/{$order->id}", 'public'),
                    'type' => 'before',
                ]);
            }

            if (($data['deposit'] ?? 0) > 0) {
                $order->payments()->create([
                    'amount' => $data['deposit'],
                    'method' => $data['payment_method'] ?? 'cash',
                    'paid_at' => now(),
                    'recorded_by' => $request->user()->id,
                ]);
            }

            $order->statusHistory()->create([
                'from_status' => null,
                'to_status' => 'received',
                'changed_by' => $request->user()->id,
                'notes' => 'Order received.',
            ]);

            return $order;
        });

        return response()->json($order->load(['client', 'items', 'photos', 'payments']), 201);
    }

    public function updateStatus(Request $request, Order $order)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $transitions = [
            'received' => ['washing', 'cancelled'],
            'washing' => ['drying', 'quality_check', 'cancelled'],
            'drying' => ['quality_check', 'washing', 'cancelled'],
            'quality_check' => ['ready', 'washing', 'cancelled'],
            'ready' => ['collected', 'washing'],
            'collected' => [],
            'cancelled' => [],
        ];

        if (! in_array($data['status'], $transitions[$order->status] ?? [], true) && $request->user()->role !== 'admin') {
            throw ValidationException::withMessages(['status' => ['That status change is not permitted.']]);
        }

        $previous = $order->status;

        DB::transaction(function () use ($request, $order, $data, $previous) {
            $timestamps = match ($data['status']) {
                'ready' => ['ready_at' => now()],
                'collected' => ['collected_at' => now()],
                default => [],
            };

            $order->update(['status' => $data['status'], ...$timestamps]);
            $order->statusHistory()->create([
                'from_status' => $previous,
                'to_status' => $data['status'],
                'changed_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
            ]);
        });

        if ($data['status'] === 'ready' && ! $order->ready_notified_at) {
            $order->client->notify(new OrderReadyNotification($order));
            $order->update(['ready_notified_at' => now()]);
        }

        return response()->json($order->fresh()->load(['client', 'items', 'payments', 'statusHistory.changer:id,name']));
    }

    public function addPayment(Request $request, Order $order)
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in(['cash', 'mobile_money', 'card', 'bank'])],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        if ($data['amount'] > $order->balance) {
            throw ValidationException::withMessages(['amount' => ['Payment cannot exceed the outstanding balance.']]);
        }

        $payment = $order->payments()->create([
            ...$data,
            'paid_at' => now(),
            'recorded_by' => $request->user()->id,
        ]);

        return response()->json($payment->load('recorder:id,name'), 201);
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        if (str_starts_with($phone, '0')) {
            return '+256'.substr($phone, 1);
        }

        if (str_starts_with($phone, '256')) {
            return '+'.$phone;
        }

        return $phone;
    }
}
