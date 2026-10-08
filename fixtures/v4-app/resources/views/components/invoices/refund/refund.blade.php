<div class="mx-auto max-w-md py-8">
    <h1 class="text-lg font-semibold">Invoice {{ $invoice->number }}</h1>

    <p class="mt-2 text-sm text-gray-500">
        Total: ${{ number_format($invoice->total, 2) }}
    </p>

    <button wire:click="refund" class="mt-6 rounded bg-black px-4 py-2 text-white">
        Refund invoice
    </button>
</div>
