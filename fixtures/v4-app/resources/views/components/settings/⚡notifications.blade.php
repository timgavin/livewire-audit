<?php

use App\Models\NotificationPreference;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public int $preferenceId;

    public bool $emailDigest = false;

    public bool $pushMentions = false;

    public function mount(): void
    {
        $preference = auth()->user()->notificationPreference;

        $this->preferenceId = $preference->id;
        $this->emailDigest = $preference->email_digest;
        $this->pushMentions = $preference->push_mentions;
    }

    public function toggle(string $channel): void
    {
        $preference = NotificationPreference::findOrFail($this->preferenceId);

        $this->authorize('update', $preference);

        $validated = $this->validate([
            'emailDigest' => ['boolean'],
            'pushMentions' => ['boolean'],
        ]);

        abort_unless(in_array($channel, ['emailDigest', 'pushMentions'], true), 422);

        $preference->update([
            'email_digest' => $validated['emailDigest'],
            'push_mentions' => $validated['pushMentions'],
        ]);

        session()->flash('status', 'Preferences saved.');
    }
}; ?>

<div class="mx-auto max-w-md py-8">
    <h1 class="text-lg font-semibold">Notifications</h1>

    <label class="mt-4 flex items-center gap-2">
        <input type="checkbox" wire:model="emailDigest" wire:change="toggle('emailDigest')" />
        <span>Weekly email digest</span>
    </label>

    <label class="mt-3 flex items-center gap-2">
        <input type="checkbox" wire:model="pushMentions" wire:change="toggle('pushMentions')" />
        <span>Push me when I'm mentioned</span>
    </label>
</div>
