<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Shoe;
use Illuminate\Http\Request;

class ShoeController extends Controller
{
    public function store(Request $request, Client $client)
    {
        $data = $this->validated($request);
        if ($request->hasFile('reference_photo')) {
            $data['reference_photo_path'] = $request->file('reference_photo')->store("clients/{$client->id}/shoes", 'public');
        }

        $shoe = $client->shoes()->create($data);
        $shoe->update(['reference' => 'ASL-S-'.str_pad((string) $shoe->id, 6, '0', STR_PAD_LEFT)]);

        return response()->json($shoe->fresh(), 201);
    }

    public function update(Request $request, Shoe $shoe)
    {
        $data = $this->validated($request, true);
        if ($request->hasFile('reference_photo')) {
            $data['reference_photo_path'] = $request->file('reference_photo')->store("clients/{$shoe->client_id}/shoes", 'public');
        }
        $shoe->update($data);

        return response()->json($shoe->fresh());
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'shoe_type' => [$required, 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:40'],
            'identifying_notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
            'reference_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
        ]);
    }
}
