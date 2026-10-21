<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $shoeName = $request->string('shoe_name')->toString();
        $serviceDate = $request->date('date');

        $clients = Client::query()
            ->with(['shoes' => function ($query) use ($shoeName, $serviceDate) {
                $query->withCount('cleaningSessions')
                    ->withMax('cleaningSessions as last_service_at', 'received_at')
                    ->when($shoeName, fn ($query, $shoe) => $query->where(function ($query) use ($shoe) {
                        $query->where('reference', 'like', "%{$shoe}%")
                            ->orWhere('brand', 'like', "%{$shoe}%")
                            ->orWhere('model', 'like', "%{$shoe}%")
                            ->orWhere('shoe_type', 'like', "%{$shoe}%")
                            ->orWhere('color', 'like', "%{$shoe}%");
                    }))
                    ->when($serviceDate, fn ($query, $date) => $query->whereHas(
                        'cleaningSessions',
                        fn ($sessions) => $sessions->whereDate('received_at', $date),
                    ))
                    ->latest('updated_at');
            }])
            ->withCount(['orders', 'shoes'])
            ->withMax('orders as last_service_at', 'received_at')
            ->when($request->string('client_name')->toString(), fn ($query, $name) => $query
                ->where('name', 'like', "%{$name}%"))
            ->when($shoeName, function ($query, $shoe) {
                $query->whereHas('shoes', function ($query) use ($shoe) {
                    $query->where(function ($query) use ($shoe) {
                        $query->where('reference', 'like', "%{$shoe}%")
                            ->orWhere('brand', 'like', "%{$shoe}%")
                            ->orWhere('model', 'like', "%{$shoe}%")
                            ->orWhere('shoe_type', 'like', "%{$shoe}%")
                            ->orWhere('color', 'like', "%{$shoe}%");
                    });
                });
            })
            ->when($serviceDate, fn ($query, $date) => $query->whereHas(
                'shoes.cleaningSessions',
                fn ($sessions) => $sessions->whereDate('received_at', $date),
            ))
            ->when($request->string('search')->toString(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('shoes', fn ($shoes) => $shoes
                            ->where('reference', 'like', "%{$search}%")
                            ->orWhere('brand', 'like', "%{$search}%")
                            ->orWhere('model', 'like', "%{$search}%")
                            ->orWhere('shoe_type', 'like', "%{$search}%"));
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
            'shoe.shoe_type' => ['required', 'string', 'max:100'],
            'shoe.brand' => ['nullable', 'string', 'max:100'],
            'shoe.model' => ['nullable', 'string', 'max:100'],
            'shoe.color' => ['nullable', 'string', 'max:100'],
            'shoe.size' => ['nullable', 'string', 'max:40'],
            'reference_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
        ]);

        $client = DB::transaction(function () use ($request, $data) {
            $client = Client::create(collect($data)->only(['name', 'phone', 'email', 'notes'])->all());
            $shoeData = $data['shoe'];
            if ($request->hasFile('reference_photo')) {
                $shoeData['reference_photo_path'] = $request->file('reference_photo')->store("clients/{$client->id}/shoes", 'public');
            }
            $shoe = $client->shoes()->create($shoeData);
            $shoe->update(['reference' => 'ASL-S-'.str_pad((string) $shoe->id, 6, '0', STR_PAD_LEFT)]);

            return $client;
        });

        return response()->json($client->load('shoes'), 201);
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
