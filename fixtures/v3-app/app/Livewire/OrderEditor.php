<?php

namespace App\Livewire;

use App\Models\Order;
use Livewire\Component;

class OrderEditor extends Component
{
    public $orderId;

    public $reason = '';

    public function mount($orderId)
    {
        $this->orderId = $orderId;
    }

    public function cancel()
    {
        $order = Order::findOrFail($this->orderId);

        $order->update([
            'status' => 'cancelled',
            'cancellation_reason' => $this->reason,
        ]);

        session()->flash('status', 'Order cancelled.');
    }

    public function render()
    {
        return view('livewire.order-editor', [
            'order' => Order::findOrFail($this->orderId),
        ]);
    }
}
