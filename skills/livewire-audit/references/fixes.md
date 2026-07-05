# Livewire Security Fixes — Canonical Patterns

Fix-mode ordering: apply the smallest sufficient fix, in this order: `#[Locked]` > visibility change > `#[Computed]` conversion > authorization insertion > restructure. Never weaken validation to make a fix simpler. If the right policy or gate is ambiguous, stop and ask the user — never guess an authorization rule.

Each pattern below is referenced by name from `checklist.md`. The names are a fixed interface; do not rename them.

---

## lock-scalar

When to use (LW-02, LW-04): a scalar identifier or flag is a public property the client must never change (record id, owner id, price, server-set status).

```php
// before
public int $postId;

// after
use Livewire\Attributes\Locked;
#[Locked]
public int $postId;
```

A tampered update to a locked property throws `CannotUpdateLockedPropertyException` (HTTP 419 in production).

Volt functional API: the equivalent is chaining the modifier onto the state declaration — `state(['postId'])->locked();` (Volt docs). Apply that form, not the attribute, when fixing functional-API state.

NOT: do not lock a property the user edits through `wire:model` — locking blocks legitimate binds too.

---

## model-typed-property

When to use (LW-02 alt): a record is held as an id scalar and re-loaded on every action, or you want client tampering structurally impossible rather than attribute-guarded.

```php
public Post $post;   // client cannot set/get model internals; serialized as class+key only
public function mount(Post $post) { $this->post = $post; }
```

The synthesizer dehydrates to class + key only and refuses direct get/set/call, so attributes never reach the client and cannot be tampered.

NOT: do not pair this with `$this->all()` mass-assignment — the model is safe, but a public-property soup around it is not.

---

## computed-instead-of-public

When to use (LW-03, LW-08, LW-09): derived or sensitive data sits in a public property and therefore enters the snapshot sent to the browser (balances, totals, internal flags — anything not bound by an input).

```php
// before: public string $balance; set in mount()
use Livewire\Attributes\Computed;
#[Computed]
public function balance(): string
{
    return auth()->user()->balance_formatted;   // never enters the snapshot
}
```

Template: `$balance` becomes `{{ $this->balance }}`.

NOT: do not leave a public backing property around the computed — that re-exposes the value.

---

## authorize-in-action

When to use (LW-01, LW-05, LW-07): a public action mutates or deletes a resource without checking the actor can act on it; or a helper is `public` (hence client-callable) but should run server-side only.

```php
// before: no authorization
public function delete(): void
{
    $this->post->delete();   // any client can call $wire.delete()
}

// after: authorize before mutating
public function delete(): void
{
    $this->authorize('delete', $this->post);
    $this->post->delete();
}
```

Visibility variant — every public method is client-callable with no `wire:` directive referencing it. Change `public function recalculate()` to `protected function recalculate()` for helpers that must not be client-callable.

Volt functional API: `$this->authorize(...)` works inside action closures (`Livewire\Volt\Component` extends `Livewire\Component`, and `$this` in a closure is the component). The visibility variant is `protect()`: `$helper = protect(function () { ... });` makes the closure callable from other actions via `$this->helper()` but not from the client (Volt docs).

NOT: do not invent the policy ability or gate name — if the correct rule is not obvious from existing policies, stop and ask.

---

## validate-untrusted-binding

When to use (LW-04, LW-06, LW-19): a public property feeds a query, redirect, view path, or branch and arrives from the client (including `#[Url]` query-string properties) without validation.

```php
use Livewire\Attributes\Url;
#[Url]
public string $tab = 'active';
public function mount(): void
{
    abort_unless(in_array($this->tab, ['active', 'archived'], true), 404);
}
```

For ids, validate existence AND ownership:

```php
'postId' => ['required', 'integer', Rule::exists('posts', 'id')->where('user_id', auth()->id())],
```

NOT: do not rely on `exists` alone — it proves the row exists, not that this user owns it.

---

## explicit-fill

When to use (LW-15): an update or create is fed from `$this->all()` (or another whole-component dump), letting any public property reach a mass assignment.

```php
// before: $post->update($this->all());
$post->update($this->validate());          // or ->only(['title', 'body'])
```

NOT: do not pass unvalidated input to `update()`/`create()` even after narrowing keys — narrow AND validate.

---

## bind-not-interpolate

When to use (LW-16): a public property is string-interpolated into a SQL query or raw query fragment.

```php
// before: DB::select("... where status = '{$this->status}'")
DB::select('... where status = ?', [$this->status]);
```

NOT: do not "escape" the value by hand — use parameter binding.

---

## escape-output

When to use (LW-17): client-influenced data is rendered with the unescaped `{!! !!}` Blade directive. Replace `{!! $comment->body !!}` with `{{ $comment->body }}`. If HTML is required, sanitize server-side before storage and document why the unescaped output is safe.

NOT: do not sanitize at render time and call it done — store the clean value, and never unescape raw user input.

---

## rate-limit-action

When to use (LW-18): a sensitive or costly action (login, send, payment, email) has no per-actor throttle. Core Livewire ships no action rate limiting — this is framework-native and must be added by the app.

```php
use Illuminate\Support\Facades\RateLimiter;
public function send(): void
{
    $key = 'send-message:'.auth()->id();
    // attempt($key, $maxAttempts, $callback, $decaySeconds = 60): 5 sends / 60s
    if (! RateLimiter::attempt($key, 5, fn () => $this->deliver())) {
        $this->addError('message', 'Too many attempts. Try again shortly.');
    }
}
```

The `attempt()` signature is `($key, $maxAttempts, Closure $callback, $decaySeconds = 60)` — there is no `perMinute` named argument. Pass `$decaySeconds` as the 4th arg for a non-60s window.

The `danharrin/livewire-rate-limiting` package (its `WithRateLimiting` trait) is an optional convenience — never assume it ships with Livewire core.

NOT: do not key the limiter by something the client controls — key by `auth()->id()` or IP.

---

## upload-rules

When to use (LW-14): a file-upload property has no validation, allowing arbitrary type or size.

```php
use Livewire\Attributes\Validate;
use Livewire\WithFileUploads;
// in class: use WithFileUploads;
#[Validate('image|mimes:jpg,jpeg,png,webp|max:1024')]
public $avatar;
```

Two layers, do BOTH:
- The `#[Validate]` rule runs on the component's validate/update cycle — it gates what gets stored, but NOT the initial temporary-upload POST.
- The temporary-upload endpoint (`livewire/upload-file`) is gated only by the GLOBAL `temporary_file_upload.rules` in `config/livewire.php`, whose default is `['required','file','max:12288']` — 12 MB and ANY MIME type. So an unconstrained app accepts any 12 MB file at the temp endpoint regardless of the property rule. Set a real global rule:

```php
// config/livewire.php
'temporary_file_upload' => [
    'rules' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
],
```

NOT: do not rely on `#[Validate]` alone — it does not constrain the temporary-upload POST; the global config rule is the gate for that endpoint.

---

## persistent-middleware

When to use (LW-20): subsequent Livewire update requests must keep enforcing middleware (subscription, role, tenant) that ran on initial load but does not re-run automatically on component updates.

```php
// AppServiceProvider::boot()
Livewire::addPersistentMiddleware([EnsureUserIsSubscribed::class]);
```

Note: middleware arguments are not supported there — register the class form only.

How it actually works (verified, src/Mechanisms/PersistentMiddleware/PersistentMiddleware.php): Livewire keeps a default persistent list — `Authenticate`, `Authorize` (`can:`), `SubstituteBindings`, basic auth, Sanctum/Jetstream auth middleware — and re-applies the original route's middleware that appear on that list to every subsequent component update. Anything outside the list (custom subscription, role, or tenant middleware) is silently NOT re-applied unless you register it.

NOT: do not assume your custom middleware re-runs on Livewire updates because `auth` does — only the default persistent list re-applies; register custom gates explicitly.

---

## harden-update-route

When to use (LW-21): you want app-wide middleware or throttling on every Livewire update request — this is a hardening opt-in, rarely a vulnerability on its own.

```php
Livewire::setUpdateRoute(fn ($handle) =>
    Route::post('/livewire/update', $handle)->middleware(['web', 'throttle:120,1'])
);
```

Add whatever global middleware your app needs (e.g. a throttle). Note (verified, src/Mechanisms/HandleRequests/HandleRequests.php): `setUpdateRoute()` force-adds the `web` group and the Livewire header guard even if you omit them, so you CANNOT accidentally strip CSRF/session via this API — the framework re-adds them. The genuine LW-21 finding is therefore narrow: a custom update route placed on the wrong domain/prefix, or behind an extra middleware group that changes auth context — not "lost CSRF."

NOT: do not report LW-21 as "the update route lost CSRF because web was dropped" — that is not achievable through `setUpdateRoute` in v4.

---

## protect-component-routes

When to use (LW-22): a full-page Livewire component is registered as a route without an auth or authorization gate.

```php
Route::get('/settings', Settings::class)->middleware(['auth']);
```

Full-page components are routes like any other — gate them with the same middleware you would put on a controller route.

NOT: do not rely on in-component checks alone for a route that should never render for a guest — gate at the route.

---

## morph-map

When to use (LW-12): snapshots and persisted morph columns expose fully-qualified class names, leaking internal namespace structure.

```php
// in a service provider's boot()
Relation::enforceMorphMap([
    'post' => Post::class,
    // ...
]);
```

Snapshots then show stable aliases instead of FQCNs.

NOT: do not add a partial map — `enforceMorphMap` makes unmapped models throw, so map every polymorphic type.

---

## generic-error-messages

When to use (LW-13): submitted secrets or PII are echoed back through `addError()` or a flash message, where they land in the client snapshot or logs. Never echo a submitted secret/PII; use generic wording ("That card number could not be verified") and keep specifics in server logs.

NOT: do not reflect any user-submitted secret or PII back into a validation message or flash.

---

## scope-computed-cache

When to use (LW-10, LW-11): a `#[Computed]` holding user-scoped data uses `cache: true`, which shares one value across every component instance application-wide — so one user sees another's data. Never use `cache: true` for user-scoped data; use `persist:` with care and short TTLs.

```php
#[Computed(persist: true, seconds: 300)]
public function balance(): string { ... }
```

NOT: do not use `cache: true` for per-user data — it is keyed by component name and shared application-wide (in the Laravel cache backend) across every user and request, so it leaks across users.

---

## type-everything

When to use (LW-23): public properties and action parameters are untyped, so the engine silently accepts whatever the client sends. `public $ids = [];` becomes `public array $ids = [];`, and `function show($id)` becomes `function show(int $id)`.

Engine behavior: a typed property plus a wrong-type client value raises a `TypeError` that Livewire catches — empty-string/null unsets the property, otherwise it aborts with HTTP 419 in production. Typing turns silent acceptance into rejection.

NOT: do not type only properties while leaving action parameters untyped — the parameter is a client-controlled surface too.

---

## guard-lifecycle-hook

When to use (LW-24): an `updated*`/`updating*`/`hydrate*`/`mount`/`boot` hook performs a write, authorization decision, expensive query, or redirect using the incoming client value. The hook is not a `$wire`-callable action, but it fires on every property update with attacker-controlled input.

Prefer moving the side effect into an authorized action. If it must stay in the hook, authorize and validate inside it before acting:

```php
// before: side effect on raw client value, runs on every $wire.set('status', ...)
public function updatedStatus($value)
{
    $this->order->update(['status' => $value]);
}

// after: move the write to an authorized, validated action and keep the hook inert
public function updatedStatus($value)
{
    // no side effect here; the hook just lets the property bind
}

public function changeStatus(): void
{
    $this->authorize('update', $this->order);
    $validated = $this->validate(['status' => ['required', Rule::in(['open', 'shipped'])]]);
    $this->order->update($validated);
}
```

If the hook genuinely must act (e.g. a live-search `updatedQuery`), authorize + validate + bound the work inside it, and consider `wire:model.blur`/`.lazy` or a throttle so it does not run per keystroke.

NOT: do not assume a method named like a hook is "internal and safe" — `updated{Prop}` runs on raw client input. Do not leave a destructive write in a hook because "no `wire:click` calls it."

---

## js-params-not-interpolation

When to use (LW-25): a `$this->js()` expression (or `#[Js]` method) interpolates a user-controlled value into the evaluated JavaScript string.

Pass dynamic values as data through the second `params` argument (surfaced to the expression), never by concatenating into the code string:

```php
// before: value concatenated into evaluated JS -> XSS
$this->js("showToast('Welcome, {$user->display_name}')");

// after: value passed as a param, referenced by name -> treated as data, not code
$this->js('showToast(name)', ['name' => $user->display_name]);
```

If you only need to hand data to Alpine, prefer a dispatched event or a `data-*`/`@js()` attribute over building a code string at all.

NOT: do not try to "escape" the value by hand (`addslashes`, manual quoting) — pass it as a param so it is never parsed as code. Do not `json_encode` into the middle of an expression and assume safety.

---

## dispatch-ids-not-data

When to use (LW-26): `$this->dispatch()` carries a secret, token, full PII, or another user's data in its params. Dispatch params serialize into the response `dispatches` effect and reach the browser regardless of `->to()`.

Dispatch only non-sensitive identifiers; have the receiving component re-fetch and re-authorize the sensitive data server-side from the id:

```php
// before: sensitive payload shipped to the client
$this->dispatch('balance-updated', balance: $account->raw_balance, token: $account->plaid_token)
    ->to(BalanceWidget::class);

// after: dispatch an id only; the listener loads + authorizes server-side
$this->dispatch('balance-updated', accountId: $account->id)->to(BalanceWidget::class);

// in BalanceWidget:
#[On('balance-updated')]
public function refresh(int $accountId): void
{
    $account = Account::findOrFail($accountId);
    $this->authorize('view', $account);   // re-authorize; do not trust the dispatched id
    $this->balance = $account->raw_balance;   // stays server-side; render via #[Computed] if sensitive
}
```

NOT: do not rely on `->to(Component::class)` to keep a payload private — it is routing, not confidentiality; the params still pass through the browser.

---

## allowlist-redirect-target

When to use (LW-27): a redirect target derives from a `#[Url]`/public property or an action parameter with no host/path constraint.

Redirect to named routes, or constrain a dynamic return URL to a same-origin relative path before redirecting:

```php
// before: open redirect
#[Url] public $returnTo;
public function finish() {
    return $this->redirect($this->returnTo);
}

// after: allow-list known destinations
public function finish() {
    $targets = ['dashboard' => route('dashboard'), 'orders' => route('orders.index')];
    return $this->redirect($targets[$this->returnTo] ?? route('dashboard'));
}

// or, if an arbitrary return path is required, force it relative + same-origin:
public function finish() {
    $path = '/'.ltrim(parse_url($this->returnTo, PHP_URL_PATH) ?? '', '/');
    return $this->redirect($path);   // drops scheme/host; cannot leave the origin
}
```

NOT: do not redirect a raw client-supplied absolute URL. Do not allow-list by `str_starts_with($url, 'https://yoursite')` — that is bypassable (`https://yoursite.evil.com`); compare parsed host equality or use relative paths only.
