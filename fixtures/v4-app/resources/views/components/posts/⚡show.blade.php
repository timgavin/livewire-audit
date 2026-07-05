<?php

use App\Models\Post;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

new class extends Component {
    public Post $post;

    public string $comment = '';

    public function mount(Post $post): void
    {
        $this->post = $post;
    }

    public function like(): void
    {
        $this->authorize('view', $this->post);

        $this->post->likes()->firstOrCreate(['user_id' => auth()->id()]);
    }

    public function comment(): void
    {
        $this->authorize('comment', $this->post);

        $validated = $this->validate([
            'comment' => ['required', 'string', 'max:1000'],
        ]);

        $key = 'post-comment:'.auth()->id();

        if (! RateLimiter::attempt($key, 10, function () use ($validated) {
            $this->post->comments()->create([
                'user_id' => auth()->id(),
                'body' => $validated['comment'],
            ]);
        })) {
            $this->addError('comment', 'Too many comments. Please try again shortly.');

            return;
        }

        $this->reset('comment');
    }
}; ?>

<div class="mx-auto max-w-2xl py-8">
    <h1 class="text-xl font-semibold">{{ $post->title }}</h1>

    <div class="prose mt-3">{{ $post->body }}</div>

    <button wire:click="like" class="mt-4 rounded border px-3 py-1">
        Like ({{ $post->likes_count }})
    </button>

    <div class="mt-6">
        <label class="block text-sm">Add a comment</label>
        <textarea wire:model="comment" rows="3" class="mt-1 w-full rounded border px-2 py-1"></textarea>
        @error('comment') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        <button wire:click="comment" class="mt-2 rounded bg-black px-4 py-2 text-white">Post</button>
    </div>

    <div class="mt-8 space-y-3">
        @foreach ($post->comments as $c)
            <div class="rounded border p-3 text-sm">{{ $c->body }}</div>
        @endforeach
    </div>
</div>
