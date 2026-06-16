<?php

use App\Models\BillingAccount;
use Livewire\Component;

new class extends Component {
    public BillingAccount $account;

    public function mount(): void
    {
        $this->account = auth()->user()->billingAccount;
    }

    public function broadcastBalance(): void
    {
        $this->dispatch(
            'balance-updated',
            balance: $this->account->raw_balance,
            token: $this->account->api_token,
        )->to('account.summary');
    }
}; ?>

<div class="mx-auto max-w-md py-8">
    <h1 class="text-lg font-semibold">Account balance</h1>

    <div class="mt-4 rounded border p-4">
        <p class="text-sm text-gray-500">Available balance</p>
        <p class="text-2xl font-bold">${{ number_format($account->display_balance, 2) }}</p>
    </div>

    <button wire:click="broadcastBalance" class="mt-6 rounded bg-black px-4 py-2 text-white">
        Refresh summary
    </button>
</div>
