<div class="mx-auto max-w-lg py-8">
    <h1 class="text-lg font-semibold">Order {{ $order->reference }}</h1>

    <p class="mt-2 text-sm text-gray-600">
        Status: <span class="font-medium">{{ $order->status }}</span>
    </p>

    <label class="mt-4 block text-sm">Reason for cancellation</label>
    <textarea wire:model="reason" rows="3" class="mt-1 w-full rounded border px-2 py-1"></textarea>

    <button wire:click="cancel" class="mt-4 rounded bg-red-600 px-4 py-2 text-white">
        Cancel order
    </button>

    @if (session('status'))
        <p class="mt-3 text-sm text-green-600">{{ session('status') }}</p>
    @endif
</div>
