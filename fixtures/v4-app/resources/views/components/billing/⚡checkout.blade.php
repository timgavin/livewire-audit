<?php

use App\Models\Order;
use App\Services\PaymentGateway;
use Livewire\Component;

new class extends Component {
    public $amount;

    public $orderId;

    public $note = '';

    public function mount($orderId): void
    {
        $this->orderId = $orderId;
        $order = Order::findOrFail($orderId);
        $this->amount = $order->total;
    }

    public function pay(PaymentGateway $gateway): void
    {
        $order = Order::findOrFail($this->orderId);

        $gateway->charge($order->customer, $this->amount, [
            'description' => "Order {$order->reference}",
            'note' => $this->note,
        ]);

        $order->update(['status' => 'paid', 'charged_amount' => $this->amount]);

        $this->redirectRoute('billing', navigate: true);
    }
}; ?>

<div class="mx-auto max-w-md py-10">
    <h1 class="text-xl font-semibold">Complete your purchase</h1>

    <div class="mt-4 rounded border p-4">
        <p class="text-sm text-gray-600">Amount due</p>
        <p class="text-2xl font-bold">${{ number_format($amount, 2) }}</p>
    </div>

    <label class="mt-4 block text-sm">Add a note</label>
    <input type="text" wire:model="note" class="mt-1 w-full rounded border px-2 py-1" />

    <button wire:click="pay" class="mt-6 w-full rounded bg-black px-4 py-2 text-white">
        Pay ${{ number_format($amount, 2) }}
    </button>
</div>
