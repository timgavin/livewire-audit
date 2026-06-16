<?php

use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public $avatar;

    public function save(): void
    {
        $path = $this->avatar->store('avatars', 'public');

        auth()->user()->update(['avatar_path' => $path]);

        $this->reset('avatar');
        session()->flash('status', 'Avatar updated.');
    }
}; ?>

<div class="mx-auto max-w-md py-8">
    <h1 class="text-lg font-semibold">Update avatar</h1>

    <input type="file" wire:model="avatar" class="mt-4 block w-full text-sm" />

    @if ($avatar)
        <img src="{{ $avatar->temporaryUrl() }}" class="mt-4 h-24 w-24 rounded-full object-cover" />
    @endif

    <button wire:click="save" class="mt-4 rounded bg-black px-4 py-2 text-white">Save</button>
</div>
