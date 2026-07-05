<?php

use App\Models\Account;
use Illuminate\Support\Facades\Gate;

use function Livewire\Volt\{mount, protect, state};

state(['accountId' => null])->locked();
state(['alias' => '']);

mount(function () {
    $this->accountId = auth()->user()->current_account_id;
    $this->alias = $this->currentAccount()->alias;
});

$rename = function () {
    $account = $this->currentAccount();

    Gate::authorize('update', $account);

    $validated = $this->validate([
        'alias' => ['required', 'string', 'max:60'],
    ]);

    $account->update(['alias' => $validated['alias']]);

    session()->flash('status', 'Account renamed.');
};

$currentAccount = protect(function () {
    return Account::findOrFail($this->accountId);
});

?>

<x-app-layout>
    <h1 class="text-xl font-semibold">Dashboard</h1>

    @volt('account-alias')
        <div class="mt-6 max-w-md">
            <label class="block text-sm">Account alias</label>
            <input type="text" wire:model="alias" class="mt-1 w-full rounded border px-2 py-1" />

            <button wire:click="rename" class="mt-3 rounded bg-gray-800 px-4 py-2 text-white">
                Save
            </button>

            @if (session('status'))
                <p class="mt-3 text-sm text-green-600">{{ session('status') }}</p>
            @endif
        </div>
    @endvolt
</x-app-layout>
