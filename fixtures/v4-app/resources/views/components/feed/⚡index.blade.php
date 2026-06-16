<?php

use App\Models\Post;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    #[Url]
    public $filter = 'all';

    public function posts(): Collection
    {
        return Post::query()
            ->whereRaw("category = '$this->filter'")
            ->latest()
            ->limit(50)
            ->get();
    }

    public function with(): array
    {
        return ['posts' => $this->posts()];
    }
}; ?>

<div class="mx-auto max-w-2xl py-8">
    <div class="flex gap-2">
        <button wire:click="$set('filter', 'all')" class="rounded border px-3 py-1">All</button>
        <button wire:click="$set('filter', 'news')" class="rounded border px-3 py-1">News</button>
        <button wire:click="$set('filter', 'updates')" class="rounded border px-3 py-1">Updates</button>
    </div>

    <div class="mt-6 space-y-6">
        @foreach ($posts as $post)
            <article class="rounded border p-4">
                <h2 class="font-semibold">{{ $post->title }}</h2>
                <div class="prose mt-2">{!! $post->body !!}</div>
            </article>
        @endforeach
    </div>
</div>
