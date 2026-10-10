<?php

namespace App\Http\Controllers;

use App\Jobs\SendPickupReadyEmail;
use App\Models\CleaningSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CleaningSessionController extends Controller
{
    public function updateStatus(Request $request, CleaningSession $cleaningSession)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(CleaningSession::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $previous = $cleaningSession->status;
        $allowed = CleaningSession::TRANSITIONS[$previous] ?? [];
        if (! in_array($data['status'], $allowed, true) && $request->user()->role !== 'admin') {
            throw ValidationException::withMessages(['status' => ['That workflow change is not permitted.']]);
        }
        if ($previous === $data['status']) {
            throw ValidationException::withMessages(['status' => ['The footwear is already at that stage.']]);
        }

        $order = $cleaningSession->order;
        if ($data['status'] === 'ready_for_collection' && ! $order->notification_email) {
            throw ValidationException::withMessages(['status' => ['Add a valid client email before marking this footwear ready for collection.']]);
        }

        $delivery = DB::transaction(function () use ($request, $cleaningSession, $data, $previous, $order) {
            $timestamps = match ($data['status']) {
                'cleaning' => ['cleaning_at' => now(), 'ready_at' => null, 'collected_at' => null],
                'drying' => ['drying_at' => now()],
                'quality_check' => ['quality_checked_at' => now()],
                'ready_for_collection' => ['ready_at' => now(), 'ready_cycle' => $cleaningSession->ready_cycle + 1],
                'collected' => ['collected_at' => now()],
                default => [],
            };

            $cleaningSession->update(['status' => $data['status'], ...$timestamps]);
            $cleaningSession->statusHistory()->create([
                'from_status' => $previous,
                'to_status' => $data['status'],
                'changed_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->syncOrderStatus($cleaningSession->order);

            if ($data['status'] !== 'ready_for_collection') {
                return null;
            }

            return $cleaningSession->notifications()->firstOrCreate(
                ['idempotency_key' => "ready:{$cleaningSession->id}:{$cleaningSession->ready_cycle}"],
                [
                    'event' => 'ready_for_collection',
                    'channel' => 'mail',
                    'trigger' => 'automatic',
                    'destination' => $order->notification_email,
                    'status' => 'queued',
                    'queued_at' => now(),
                ],
            );
        });

        if ($delivery) {
            SendPickupReadyEmail::dispatch($delivery)->afterCommit();
        }

        return response()->json($this->loadSession($cleaningSession));
    }

    public function resend(Request $request, CleaningSession $cleaningSession)
    {
        if (! in_array($cleaningSession->status, ['ready_for_collection', 'collected'], true)) {
            throw ValidationException::withMessages(['notification' => ['Pickup email is only available after footwear is ready.']]);
        }
        if (! $cleaningSession->order->notification_email) {
            throw ValidationException::withMessages(['notification' => ['This service order has no email destination.']]);
        }

        $delivery = $cleaningSession->notifications()->create([
            'idempotency_key' => 'manual:'.Str::uuid(),
            'event' => 'ready_for_collection',
            'channel' => 'mail',
            'trigger' => 'manual',
            'destination' => $cleaningSession->order->notification_email,
            'status' => 'queued',
            'queued_at' => now(),
        ]);
        SendPickupReadyEmail::dispatch($delivery)->afterCommit();

        return response()->json($delivery, 202);
    }

    private function syncOrderStatus($order): void
    {
        $statuses = $order->cleaningSessions()->pluck('status');
        $priority = ['received', 'cleaning', 'drying', 'quality_check', 'ready_for_collection', 'collected'];
        $active = $statuses->reject(fn ($status) => $status === 'cancelled');
        $status = $active->isEmpty()
            ? 'cancelled'
            : collect($priority)->first(fn ($candidate) => $active->contains($candidate), 'collected');
        $order->update([
            'status' => $status,
            'ready_at' => $status === 'ready_for_collection' ? ($order->ready_at ?? now()) : $order->ready_at,
            'collected_at' => $status === 'collected' ? ($order->collected_at ?? now()) : $order->collected_at,
        ]);
    }

    private function loadSession(CleaningSession $session): CleaningSession
    {
        return $session->fresh()->load([
            'shoe', 'photos', 'notifications', 'statusHistory.changer:id,name',
        ]);
    }
}
