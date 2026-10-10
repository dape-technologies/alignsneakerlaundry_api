<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Channels\TwilioSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        $channels = [TwilioSmsChannel::class];

        if ($notifiable->email) {
            array_unshift($channels, 'mail');
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your clean shoes are ready - {$this->order->reference}")
            ->greeting("Hello {$notifiable->name},")
            ->line('Your shoes are freshly cleaned and ready for pickup.')
            ->line("Order: {$this->order->reference}")
            ->line('Balance due: UGX '.number_format($this->order->balance))
            ->line('Thank you for choosing Align Sneaker Laundry.');
    }

    public function toSms(object $notifiable): string
    {
        return "Align Sneaker Laundry: {$this->order->reference} is freshly clean and ready for pickup. Balance due: UGX ".number_format((float) $this->order->balance).'.';
    }
}
