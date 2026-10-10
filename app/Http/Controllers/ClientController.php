<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $clients = Client::query()
            ->withCount(['orders', 'shoes'])
            ->when($request->string('search')->toString(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(20);

        return response()->json($clients);
    }

    public function store(Request $request)
    {
        $request->merge(['phone' => $this->normalizePhone((string) $request->input('phone'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'unique:clients,phone'],
            'email' => ['required', 'email', 'max:190'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(Client::create($data), 201);
    }

    public function show(Client $client)
    {
        $client->load([
            'shoes' => fn ($query) => $query->withCount('cleaningSessions')->with([
                'cleaningSessions' => fn ($query) => $query
                    ->with('order:id,reference,received_at')
                    ->latest('received_at')
                    ->limit(1),
            ]),
        ]);

        $activeSessions = $client->shoes()
            ->with(['cleaningSessions' => fn ($query) => $query
                ->whereNotIn('status', ['collected', 'cancelled'])
                ->with('order:id,reference,received_at,due_at')])
            ->get()
            ->pluck('cleaningSessions')
            ->flatten()
            ->values();

        return response()->json([
            ...$client->toArray(),
            'active_sessions' => $activeSessions,
            'order_history' => $client->orders()
                ->with(['cleaningSessions.shoe', 'payments'])
                ->latest('received_at')
                ->paginate(20),
        ]);
    }

    public function update(Request $request, Client $client)
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => $this->normalizePhone((string) $request->input('phone'))]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'phone' => ['sometimes', 'required', 'string', 'max:30', Rule::unique('clients', 'phone')->ignore($client)],
            'email' => ['sometimes', 'required', 'email', 'max:190'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $client->update($data);

        return response()->json($client->fresh());
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
