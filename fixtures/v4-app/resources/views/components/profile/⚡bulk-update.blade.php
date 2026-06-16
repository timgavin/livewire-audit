<?php

use Livewire\Component;

new class extends Component {
    public array $data = [];

    public function mount(): void
    {
        $user = auth()->user();

        $this->data = [
            'name' => $user->name,
            'bio' => $user->bio,
            'location' => $user->location,
        ];
    }

    public function save(): void
    {
        auth()->user()->update($this->data);

        session()->flash('status', 'Profile saved.');
    }
}; ?>

<div class="mx-auto max-w-lg py-8">
    <h1 class="text-lg font-semibold">Edit your profile</h1>

    <label class="mt-4 block text-sm">Name</label>
    <input wire:model="data.name" class="mt-1 w-full rounded border px-2 py-1" />

    <label class="mt-3 block text-sm">Bio</label>
    <textarea wire:model="data.bio" rows="3" class="mt-1 w-full rounded border px-2 py-1"></textarea>

    <label class="mt-3 block text-sm">Location</label>
    <input wire:model="data.location" class="mt-1 w-full rounded border px-2 py-1" />

    <button wire:click="save" class="mt-6 rounded bg-black px-4 py-2 text-white">
        Save profile
    </button>
</div>
