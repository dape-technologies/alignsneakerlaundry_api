<?php

namespace App\Jobs;

use App\Mail\PickupReadyMail;
use App\Models\NotificationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendPickupReadyEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public NotificationDelivery $delivery) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $this->delivery->refresh();
        if ($this->delivery->status === 'sent') {
            return;
        }

        $this->delivery->update([
            'status' => 'sending',
            'attempts' => $this->delivery->attempts + 1,
            'failure_reason' => null,
            'failed_at' => null,
        ]);

        $session = $this->delivery->cleaningSession()->with(['shoe', 'order'])->firstOrFail();
        $deliveryCycle = (int) str($this->delivery->idempotency_key)->afterLast(':')->toString();
        if ($this->delivery->trigger === 'automatic' && (
            ! in_array($session->status, ['ready_for_collection', 'collected'], true)
            || $session->ready_cycle !== $deliveryCycle
        )) {
            $this->delivery->update([
                'status' => 'cancelled',
                'failure_reason' => 'The footwear left its ready stage before this email was sent.',
            ]);

            return;
        }
        Mail::to($this->delivery->destination)->send(new PickupReadyMail($session));
        $this->delivery->update(['status' => 'sent', 'sent_at' => now()]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->delivery->update([
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => str($exception?->getMessage() ?? 'Mail delivery failed.')->limit(2000),
        ]);
    }
}
