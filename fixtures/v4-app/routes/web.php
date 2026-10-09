<?php

use App\Http\Middleware\EnsureUserIsVerified;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Full-page Livewire component routes for the application. Single-file
| components live under resources/views/components and are referenced by
| their component name.
|
*/

Route::view('/', 'welcome')->name('home');

Route::view('/pricing', 'pricing')->name('pricing');

Route::livewire('/feed', 'feed.index')->name('feed');

Route::livewire('/account/settings', 'profile.show')
    ->name('account.settings');

Route::livewire('/billing/{orderId}', 'billing.checkout')
    ->middleware(['auth', EnsureUserIsVerified::class])
    ->name('billing');

Route::livewire('/messages', 'messages.composer')
    ->middleware('auth')
    ->name('messages');

Route::livewire('/dashboard', 'stats.dashboard')
    ->middleware('auth')
    ->name('dashboard');

Route::livewire('/admin/users/{userId}', 'admin.user-editor')
    ->name('admin.users.edit');
