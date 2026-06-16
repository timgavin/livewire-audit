<?php

use Livewire\Component;

new class extends Component {
    public $displayName = '';

    public function mount(): void
    {
        $user = auth()->user();

        $this->displayName = $user->display_name;
    }

    public function welcome(): void
    {
        $this->js("showToast('Welcome back, {$this->displayName}')");
    }
}; ?>

<div class="mx-auto max-w-lg py-8">
    <h1 class="text-lg font-semibold">Hello again</h1>

    <p class="mt-3 text-sm text-gray-600">Good to see you, {{ $displayName }}.</p>

    <button wire:click="welcome" class="mt-6 rounded bg-black px-4 py-2 text-white">
        Say hi
    </button>
</div>
