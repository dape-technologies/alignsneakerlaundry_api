<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Order;
use App\Models\Shoe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::query()
            ->with(['client', 'creator:id,name', 'cleaningSessions.shoe', 'payments'])
            ->when($request->string('status')->toString(), fn ($query, $status) => $query
                ->whereHas('cleaningSessions', fn ($sessions) => $sessions->where('status', $status)))
            ->when($request->string('search')->toString(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhere('client_name', 'like', "%{$search}%")
                        ->orWhere('notification_phone', 'like', "%{$search}%")
                        ->orWhereHas('cleaningSessions.shoe', fn ($shoes) => $shoes
                            ->where('reference', 'like', "%{$search}%")
                            ->orWhere('brand', 'like', "%{$search}%")
                            ->orWhere('model', 'like', "%{$search}%"));
                });
            })
            ->latest('received_at')
            ->paginate(20);

        return response()->json($orders);
    }

    public function show(Order $order)
    {
        return response()->json($this->loadOrder($order));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'sessions' => ['required', 'array', 'min:1'],
            'sessions.*.shoe_id' => ['nullable', 'integer', 'exists:shoes,id'],
            'sessions.*.shoe.shoe_type' => ['required_without:sessions.*.shoe_id', 'string', 'max:100'],
            'sessions.*.shoe.brand' => ['nullable', 'string', 'max:100'],
            'sessions.*.shoe.model' => ['nullable', 'string', 'max:100'],
            'sessions.*.shoe.color' => ['nullable', 'string', 'max:100'],
            'sessions.*.shoe.size' => ['nullable', 'string', 'max:40'],
            'sessions.*.shoe.identifying_notes' => ['nullable', 'string', 'max:2000'],
            'sessions.*.service' => ['required', 'string', 'max:120'],
            'sessions.*.condition_notes' => ['nullable', 'string', 'max:2000'],
            'sessions.*.unit_price' => ['required', 'integer', 'min:0'],
            'sessions.*.photos' => ['nullable', 'array', 'max:6'],
            'sessions.*.photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'deposit' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['nullable', Rule::in(['cash', 'mobile_money', 'card', 'bank'])],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $client = Client::findOrFail($data['client_id']);
        if (! $client->email) {
            throw ValidationException::withMessages(['client_id' => ['This client needs an email address before a service order can be created.']]);
        }

        $subtotal = collect($data['sessions'])->sum('unit_price');
        $discount = min((int) ($data['discount'] ?? 0), $subtotal);
        $total = $subtotal - $discount;
        if (($data['deposit'] ?? 0) > $total) {
            throw ValidationException::withMessages(['deposit' => ['The deposit cannot exceed the order total.']]);
        }

        $order = DB::transaction(function () use ($request, $data, $client, $subtotal, $discount, $total) {
            $order = Order::create([
                'client_id' => $client->id,
                'client_name' => $client->name,
                'notification_phone' => $client->phone,
                'notification_email' => $client->email,
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

            foreach ($data['sessions'] as $index => $sessionData) {
                $shoe = $this->resolveShoe($client, $sessionData);
                $session = $order->cleaningSessions()->create([
                    'shoe_id' => $shoe->id,
                    'status' => 'received',
                    'service' => $sessionData['service'],
                    'condition_notes' => $sessionData['condition_notes'] ?? null,
                    'unit_price' => $sessionData['unit_price'],
                    'received_at' => now(),
                ]);
                $session->statusHistory()->create([
                    'from_status' => null,
                    'to_status' => 'received',
                    'changed_by' => $request->user()->id,
                    'notes' => 'Footwear received for care.',
                ]);

                foreach ($request->file("sessions.{$index}.photos", []) as $photo) {
                    $stored = $photo->store("orders/{$order->id}/sessions/{$session->id}", 'public');
                    $session->photos()->create(['order_id' => $order->id, 'path' => $stored, 'type' => 'before']);
                    if (! $shoe->reference_photo_path) {
                        $shoe->update(['reference_photo_path' => $stored]);
                    }
                }
            }

            if (($data['deposit'] ?? 0) > 0) {
                $order->payments()->create([
                    'amount' => $data['deposit'],
                    'method' => $data['payment_method'] ?? 'cash',
                    'paid_at' => now(),
                    'recorded_by' => $request->user()->id,
                ]);
            }

            return $order;
        });

        return response()->json($this->loadOrder($order), 201);
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
            ...$data, 'paid_at' => now(), 'recorded_by' => $request->user()->id,
        ]);

        return response()->json($payment->load('recorder:id,name'), 201);
    }

    private function resolveShoe(Client $client, array $sessionData): Shoe
    {
        if (! empty($sessionData['shoe_id'])) {
            $shoe = $client->shoes()->find($sessionData['shoe_id']);
            if (! $shoe) {
                throw ValidationException::withMessages(['sessions' => ['Selected footwear does not belong to this client.']]);
            }

            return $shoe;
        }

        $shoe = $client->shoes()->create($sessionData['shoe']);
        $shoe->update(['reference' => 'ASL-S-'.str_pad((string) $shoe->id, 6, '0', STR_PAD_LEFT)]);

        return $shoe;
    }

    private function loadOrder(Order $order): Order
    {
        return $order->fresh()->load([
            'client', 'creator:id,name', 'payments.recorder:id,name', 'photos',
            'cleaningSessions.shoe', 'cleaningSessions.photos',
            'cleaningSessions.statusHistory.changer:id,name', 'cleaningSessions.notifications',
        ]);
    }
}
