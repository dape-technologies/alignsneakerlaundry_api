<x-mail::message>
# Your footwear is ready for collection

Hello {{ $cleaningSession->order->client_name }},

Your **{{ $cleaningSession->shoe->display_name }}** has completed our care process and is ready for collection.

**Service order:** {{ $cleaningSession->order->reference }}  
**Footwear reference:** {{ $cleaningSession->shoe->reference }}  
**Service:** {{ $cleaningSession->service }}  
**Balance due:** UGX {{ number_format($cleaningSession->order->balance) }}

{{ config('app.pickup_instructions') }}

For assistance, contact us at **{{ config('app.business_phone') }}** or reply to this email.

Thank you for choosing Go Fresh Shoe Laundry.
</x-mail::message>
