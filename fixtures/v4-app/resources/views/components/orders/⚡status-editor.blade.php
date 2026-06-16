<?php

use App\Models\Order;
use Livewire\Component;

new class extends Component {
    public Order $order;

    public $status = '';

    protected $listeners = ['order-refresh' => 'reloadOrder'];

    public function mount(Order $order): void
    {
        $this->order = $order;
        $this->status = $order->status;
    }

    public function updatedStatus($value): void
    {
        $this->order->update(['status' => $value]);

        session()->flash('status', 'Order status saved.');
    }

    public function reloadOrder($orderId): void
    {
        $this->order = Order::find($orderId);
        $this->status = $this->order->status;
    }
}; ?>

<div class="mx-auto max-w-md py-8">
    <h1 class="text-lg font-semibold">Order #{{ $order->reference }}</h1>

    <label class="mt-4 block text-sm">Fulfilment status</label>
    <select wire:model.live="status" class="mt-1 w-full rounded border px-2 py-1">
        <option value="pending">Pending</option>
        <option value="packed">Packed</option>
        <option value="shipped">Shipped</option>
        <option value="delivered">Delivered</option>
    </select>

    <p class="mt-3 text-sm text-gray-500">Last updated {{ $order->updated_at->diffForHumans() }}</p>
</div>
