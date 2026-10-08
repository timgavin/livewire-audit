<?php

use App\Models\Invoice;
use Livewire\Component;

new class extends Component
{
    public Invoice $invoice;

    public function mount(Invoice $invoice): void
    {
        $this->invoice = $invoice;
    }

    public function refund(): void
    {
        $this->invoice->update([
            'status' => 'refunded',
            'refunded_at' => now(),
        ]);

        session()->flash('status', 'Invoice refunded.');
    }
};
