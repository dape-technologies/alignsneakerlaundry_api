<?php

namespace App\Http\Controllers;

use App\Models\Tag;

class TagController extends Controller
{
    public function index()
    {
        $tags = Tag::query()
            ->whereDoesntHave('cleaningSessions', fn ($query) => $query
                ->whereIn('status', ['received', 'cleaning', 'ready_for_collection']))
            ->orderBy('number')
            ->get(['id', 'number']);

        return response()->json(['data' => $tags]);
    }
}
