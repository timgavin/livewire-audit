<?php

use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    #[Url]
    public $returnTo = '';

    public function continue()
    {
        return $this->redirect($this->returnTo);
    }
}; ?>

<div class="mx-auto max-w-md py-10 text-center">
    <h1 class="text-xl font-semibold">You're signed in</h1>

    <p class="mt-3 text-sm text-gray-600">We'll take you back to where you left off.</p>

    <button wire:click="continue" class="mt-6 rounded bg-black px-4 py-2 text-white">
        Continue
    </button>
</div>
