<?php

use Livewire\Component;

new class extends Component {
    public string $email = '';

    public $apiKeys = [];

    public $debugInfo = [];

    public $displayName = '';

    public function mount(): void
    {
        $user = auth()->user();

        $this->displayName = $user->display_name;
        $this->email = $user->email;
        $this->apiKeys = $user->api_keys;

        $this->debugInfo = [
            'db_host' => config('database.connections.pgsql.host'),
            'queue' => config('queue.default'),
            'stripe_key' => config('services.stripe.secret'),
            'app_env' => config('app.env'),
        ];
    }

    public function updateDisplayName(): void
    {
        auth()->user()->update(['display_name' => $this->displayName]);
        session()->flash('status', 'Profile updated.');
    }
}; ?>

<div class="mx-auto max-w-lg py-8">
    <h1 class="text-lg font-semibold">Your profile</h1>

    <label class="mt-4 block text-sm">Display name</label>
    <input wire:model="displayName" class="mt-1 w-full rounded border px-2 py-1" />

    <p class="mt-3 text-sm text-gray-500">Signed in as {{ $email }}</p>

    <button wire:click="updateDisplayName" class="mt-6 rounded bg-black px-4 py-2 text-white">
        Save
    </button>
</div>
