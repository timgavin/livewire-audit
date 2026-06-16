<div class="mx-auto max-w-lg py-8">
    <h1 class="text-lg font-semibold">Contact us</h1>

    @if ($sent)
        <p class="mt-3 rounded bg-green-50 p-3 text-sm text-green-700">
            Thanks! We'll be in touch soon.
        </p>
    @endif

    <label class="mt-4 block text-sm">Name</label>
    <input wire:model="name" class="mt-1 w-full rounded border px-2 py-1" />
    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

    <label class="mt-3 block text-sm">Email</label>
    <input wire:model="email" type="email" class="mt-1 w-full rounded border px-2 py-1" />
    @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

    <label class="mt-3 block text-sm">Message</label>
    <textarea wire:model="message" rows="4" class="mt-1 w-full rounded border px-2 py-1"></textarea>
    @error('message') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

    <button wire:click="submit" class="mt-4 rounded bg-black px-4 py-2 text-white">Send message</button>
</div>
