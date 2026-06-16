<?php

use App\Models\User;
use Livewire\Component;

new class extends Component {
    public $userId;

    public $name = '';

    public $email = '';

    public $role = 'member';

    public function mount($userId): void
    {
        $this->userId = $userId;
        $user = User::findOrFail($userId);
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->role;
    }

    public function save(): void
    {
        $user = User::findOrFail($this->userId);

        $user->update($this->all());

        session()->flash('status', 'Account updated.');
    }

    public function makeAdmin(): void
    {
        $user = User::findOrFail($this->userId);
        $user->update(['role' => 'admin']);
    }
}; ?>

<div class="mx-auto max-w-lg py-8">
    <h1 class="text-lg font-semibold">Edit member</h1>

    <label class="mt-4 block text-sm">Name</label>
    <input wire:model="name" class="mt-1 w-full rounded border px-2 py-1" />

    <label class="mt-3 block text-sm">Email</label>
    <input wire:model="email" class="mt-1 w-full rounded border px-2 py-1" />

    <label class="mt-3 block text-sm">Role</label>
    <select wire:model="role" class="mt-1 w-full rounded border px-2 py-1">
        <option value="member">Member</option>
        <option value="moderator">Moderator</option>
    </select>

    <button wire:click="save" class="mt-6 rounded bg-indigo-600 px-4 py-2 text-white">
        Save changes
    </button>
</div>
