<?php

use App\Models\GiftCard;

use function Livewire\Volt\{mount, state};

state(['cardId' => null, 'note' => '']);

mount(function ($cardId) {
    $this->cardId = $cardId;
});

$redeem = function () {
    $card = GiftCard::findOrFail($this->cardId);

    $card->update([
        'status' => 'redeemed',
        'redeemed_by' => auth()->id(),
        'redemption_note' => $this->note,
    ]);

    session()->flash('status', 'Gift card redeemed to your account.');
};

?>

<div class="mx-auto max-w-lg py-8">
    <h1 class="text-lg font-semibold">Redeem gift card</h1>

    <label class="mt-4 block text-sm">Note (optional)</label>
    <input type="text" wire:model="note" class="mt-1 w-full rounded border px-2 py-1" />

    <button wire:click="redeem" class="mt-4 rounded bg-emerald-600 px-4 py-2 text-white">
        Redeem
    </button>

    @if (session('status'))
        <p class="mt-3 text-sm text-green-600">{{ session('status') }}</p>
    @endif
</div>
