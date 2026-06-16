<?php

use App\Http\Middleware\EnsureUserIsVerified;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Full-page Livewire component routes for the application. Single-file
| components live under resources/views/components and are referenced by
| their dot-notated view name.
|
*/

Route::view('/', 'welcome')->name('home');

Route::view('/pricing', 'pricing')->name('pricing');

Route::view('/feed', 'components.feed.index')->name('feed');

Route::view('/account/settings', 'components.profile.show')
    ->name('account.settings');

Route::view('/billing', 'components.billing.checkout')
    ->middleware(['auth', EnsureUserIsVerified::class])
    ->name('billing');

Route::view('/messages', 'components.messages.composer')
    ->middleware('auth')
    ->name('messages');

Route::view('/dashboard', 'components.stats.dashboard')
    ->middleware('auth')
    ->name('dashboard');
