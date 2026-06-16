<?php

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public $recipientId;

    public $body = '';

    public Collection $inbox;

    public function mount(): void
    {
        $this->inbox = collect();
    }

    public function send(): void
    {
        $this->validate([
            'recipientId' => ['required'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        Message::create([
            'sender_id' => auth()->id(),
            'recipient_id' => $this->recipientId,
            'body' => $this->body,
        ]);

        $this->body = '';
    }

    #[On('refresh-inbox')]
    public function refreshInbox($userId): void
    {
        $this->inbox = Message::query()
            ->where('recipient_id', $userId)
            ->latest()
            ->get();
    }
}; ?>

<div class="mx-auto max-w-xl py-8">
    <h1 class="text-lg font-semibold">New message</h1>

    <label class="mt-4 block text-sm">Recipient</label>
    <input wire:model="recipientId" class="mt-1 w-full rounded border px-2 py-1" />

    <label class="mt-3 block text-sm">Message</label>
    <textarea wire:model="body" rows="4" class="mt-1 w-full rounded border px-2 py-1"></textarea>

    <button wire:click="send" class="mt-4 rounded bg-black px-4 py-2 text-white">Send</button>

    <div class="mt-8 space-y-3">
        @foreach ($inbox as $message)
            <div class="rounded border p-3 text-sm">{{ $message->body }}</div>
        @endforeach
    </div>
</div>
