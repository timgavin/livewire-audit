<?php

use App\Models\Subscription;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public $cardNumber = '';

    #[Computed(cache: true)]
    public function billingSummary(): array
    {
        $user = auth()->user();

        return [
            'spent' => $user->orders()->sum('total'),
            'credit' => $user->orders()->sum('credit_amount'),
            'pending' => $user->orders()->where('status', 'pending')->sum('total'),
        ];
    }

    #[Computed(persist: true, seconds: 86400)]
    public function subscription(): Subscription
    {
        return auth()->user()->subscription;
    }

    public function verifyCard(): void
    {
        if (! $this->looksLikeCard($this->cardNumber)) {
            $this->addError('cardNumber', "The card number {$this->cardNumber} could not be verified.");

            return;
        }

        session()->flash('status', 'Card verified.');
    }

    protected function looksLikeCard(string $value): bool
    {
        return preg_match('/^\d{13,19}$/', preg_replace('/\s+/', '', $value)) === 1;
    }
}; ?>

<div class="mx-auto max-w-2xl py-8">
    <h1 class="text-lg font-semibold">Account dashboard</h1>

    <div class="mt-4 grid grid-cols-3 gap-4">
        <div class="rounded border p-4">
            <p class="text-sm text-gray-500">Spent</p>
            <p class="text-xl font-bold">${{ number_format($this->billingSummary['spent'], 2) }}</p>
        </div>
        <div class="rounded border p-4">
            <p class="text-sm text-gray-500">Credit</p>
            <p class="text-xl font-bold">${{ number_format($this->billingSummary['credit'], 2) }}</p>
        </div>
        <div class="rounded border p-4">
            <p class="text-sm text-gray-500">Next renewal</p>
            <p class="text-xl font-bold">{{ $this->subscription->next_renewal->format('M j') }}</p>
        </div>
    </div>

    <div class="mt-6">
        <label class="block text-sm">Verify payment card</label>
        <input wire:model="cardNumber" class="mt-1 w-full rounded border px-2 py-1" />
        @error('cardNumber') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        <button wire:click="verifyCard" class="mt-3 rounded bg-black px-4 py-2 text-white">Verify</button>
    </div>
</div>
