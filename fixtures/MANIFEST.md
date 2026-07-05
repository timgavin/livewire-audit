# Livewire Security Audit — Planted-Vulnerability Fixtures

This directory contains two deliberately-vulnerable Laravel Livewire apps plus a
set of clean components used as false-positive tripwires. Every file is hand-built
to look like it came from a realistic Laravel Livewire codebase. The tables below map
each file to the check IDs (LW-01..LW-27) it plants, with a one-line description and
expected severity for each plant.

Severity scale: **Critical / High / Medium / Low / Info**.

## App-level vs component-level plants

A few checks are not about an individual component — they live in app-wide
configuration, the service provider, or the route table. These are called out here
so the audit tool is graded against the right file:

- **LW-12** (no `Relation::enforceMorphMap` / morphMap registration) — app-level,
  planted by its absence in `v4-app/app/Providers/AppServiceProvider.php`.
- **LW-20** (no `Livewire::addPersistentMiddleware(...)`) — app-level, planted by
  its absence in `v4-app/app/Providers/AppServiceProvider.php`.
- **LW-21** (no `Livewire::setUpdateRoute(...)` hardened with middleware) —
  app-level **observation**, planted by its absence everywhere (no provider or
  route config sets a custom hardened update route). Expected severity: Info.
- **LW-22** (unprotected full-page Livewire component route) — app-level, planted
  in `v4-app/routes/web.php`.

**LW-23** (untyped public properties) is planted by the untyped `public $...`
declarations throughout the vulnerable components. Every vulnerable component with at
least one untyped public property carries an LW-23 row in its table below; the clean
components are fully typed.

## Grading notes

- Consolidation follows SKILL.md: checks that fire on the same code AND share the
  same fix may merge into one report block, and a merged block satisfies every check
  ID it names. Rows below marked `LW-02 + LW-04` are one such block (checklist LW-04
  says to tag both on a mount-set scalar).
- Secondary check IDs that legitimately co-fire on an already-vulnerable component
  are correct, not invented, when they are consistent with checklist.md — e.g. LW-03
  on `profile/show`'s never-rendered `$apiKeys`, or LW-04 on any client-writable
  property reaching a sensitive sink. "No invented findings" is graded against the
  clean components and against findings with no quotable evidence.
- `/`, `/pricing`, and `/feed` in `v4-app/routes/web.php` are intentionally public
  routes. LW-22 is planted only on `/account/settings`; an LW-22 finding on `/feed`
  is a false positive.

---

## v4-app — vulnerable single-file components

Filenames are ⚡-prefixed (U+26A1) per the Livewire 4 single-file-component
convention.

| File | Check IDs | Plant description | Severity |
|------|-----------|-------------------|----------|
| `v4-app/resources/views/components/billing/⚡checkout.blade.php` | LW-01 | `pay()` charges money with no authorization / ownership check | Critical |
| | LW-02 + LW-04 | untyped, unlocked `public $amount` and `public $orderId` tamperable from the client — mount-set, reachable via `$wire.set()`, drive the charge (one block, fix lock-scalar) | Critical |
| | LW-18 | `pay()` charges a payment gateway with no rate limiting / throttle | High |
| | LW-19 | `pay()` persists `charged_amount`/`note` with no validation | High |
| | LW-23 | untyped public properties | High |
| `v4-app/resources/views/components/admin/⚡user-editor.blade.php` | LW-01 | `save()` and `makeAdmin()` mutate a client-tamperable `$userId`'s account (`makeAdmin()` sets `role='admin'`) with no authorization / ownership check | Critical |
| | LW-15 | `save()` does `$user->update($this->all())` — full mass-assignment from component state | Critical |
| | LW-07 | `public function makeAdmin()` privileged action never referenced by any `wire:` directive but still callable | High |
| | LW-02 + LW-04 | untyped, unlocked `public $userId` used in queries — mount-set, reachable via `$wire.set()` (one block, fix lock-scalar) | High |
| | LW-19 | `save()` passes `$this->all()` to `update()` with no validation | High |
| | LW-23 | untyped public properties | High |
| `v4-app/resources/views/components/profile/⚡show.blade.php` | LW-08 | `public $apiKeys` holds secrets copied from the model, serialized to the browser | Critical |
| | LW-08 | `public $debugInfo` array embeds `config('services.stripe.secret')` — secret serialized into `wire:snapshot` | Critical |
| | LW-09 | `public string $email` PII copied into component state | Medium |
| | LW-03 | `public $debugInfo` holds internal config (db host, stripe secret) and is never rendered | High |
| | LW-19 | `updateDisplayName()` persists `$displayName` with no validation | Medium |
| | LW-23 | untyped public properties (`$apiKeys`, `$debugInfo`, `$displayName`) | Medium |
| `v4-app/resources/views/components/feed/⚡index.blade.php` | LW-16 | client-controlled `$this->filter` interpolated into `whereRaw(...)` — SQL injection | Critical |
| | LW-06 | `#[Url] public $filter` bound to query string with no validation / allow-list | High |
| | LW-17 | `{!! $post->body !!}` renders user content unescaped — stored XSS | High |
| | LW-23 | untyped `#[Url] public $filter` feeds a raw SQL query | High |
| `v4-app/resources/views/components/messages/⚡composer.blade.php` | LW-05 | `#[On('refresh-inbox')]` loads another user's messages by id from the event payload, no auth check | Critical |
| | LW-04 | `wire:model="recipientId"` drives the DB write in `send()` | High |
| | LW-18 | `send()` has no rate limiting | Medium |
| | LW-19 | recipient validated with only `['required']` — no existence/integer/ownership rule | Medium |
| | LW-23 | untyped public `$recipientId` and `$body` | High |
| `v4-app/resources/views/components/stats/⚡dashboard.blade.php` | LW-10 | `#[Computed(cache: true)]` account summary not keyed by user — cross-user cache bleed | High |
| | LW-11 | `#[Computed(persist: true, seconds: 86400)]` subscription persisted per-day, same bleed risk | High |
| | LW-13 | `addError()` echoes the submitted card number back into the message | High |
| | LW-23 | untyped public `$cardNumber` | Low |
| `v4-app/resources/views/components/uploads/⚡avatar.blade.php` | LW-14 | `WithFileUploads` `public $avatar` with no `#[Validate]` and no validation in `save()` | High |
| | LW-23 | untyped public `$avatar` | Medium |
| `v4-app/resources/views/components/orders/⚡status-editor.blade.php` | LW-24 | `updatedStatus()` lifecycle hook writes the order on every property set with no ownership check | Critical |
| | LW-05 | legacy `$listeners` array `['order-refresh' => 'reloadOrder']` loads any order by id from the event payload, no auth check | High |
| | LW-23 | untyped public property `$status` | High |
| `v4-app/resources/views/components/social/⚡greeting.blade.php` | LW-25 | `welcome()` interpolates the user-controlled `$displayName` straight into the `$this->js(...)` string | High |
| | LW-23 | untyped public property `$displayName` | High |
| `v4-app/resources/views/components/wallet/⚡balance.blade.php` | LW-26 | `broadcastBalance()` puts the raw balance and api token into the `dispatch(...)->to(...)` payload | High |
| `v4-app/resources/views/components/auth/⚡return-redirect.blade.php` | LW-27 | `#[Url] $returnTo` is passed straight to `$this->redirect(...)` in `continue()` with no allow-list | High |
| | LW-23 | untyped public property `$returnTo` | High |
| `v4-app/resources/views/components/profile/⚡bulk-update.blade.php` | LW-15 | `save()` does `auth()->user()->update($this->data)` where `$data` is an array bound key-by-key — deep-write key injection | Critical |
| | LW-19 | `save()` persists `$this->data` with no validation | High |

## v4-app — clean components (expected: zero findings)

| File | Why it is clean |
|------|-----------------|
| `v4-app/resources/views/components/settings/⚡notifications.blade.php` | typed properties, `#[Locked] int $preferenceId`, `$this->authorize(...)` + `$this->validate(...)` in the toggle action |
| `v4-app/resources/views/components/posts/⚡show.blade.php` | model-typed `public Post $post`, authorized `like()`/`comment()`, rate-limited `comment()`, escaped `{{ }}` output, typed `mount(Post $post)` |

## v4-app — app-level files

| File | Check IDs | Plant description | Severity |
|------|-----------|-------------------|----------|
| `v4-app/routes/web.php` | LW-22 | `/account/settings` full-page Livewire route has no auth middleware (a protected `/billing` route is included for contrast) | High |
| `v4-app/app/Providers/AppServiceProvider.php` | LW-20 | `boot()` exists but never calls `Livewire::addPersistentMiddleware(...)`, so page middleware is not re-applied to component update requests | High |
| | LW-12 | no `Relation::enforceMorphMap(...)` / morphMap registration anywhere | Medium |
| `v4-app/app/Providers/AppServiceProvider.php` (+ routes) | LW-21 | no `Livewire::setUpdateRoute(...)` hardened with middleware — observation only | Info |

Supporting (non-planted) files: `v4-app/composer.json`, `v4-app/config/livewire.php`,
`v4-app/app/Http/Middleware/EnsureUserIsVerified.php` (the middleware referenced by
the protected route).

---

## v3-app — class-based components (Livewire 3)

| File | Check IDs | Plant description | Severity |
|------|-----------|-------------------|----------|
| `v3-app/app/Livewire/OrderEditor.php` | LW-01 | `cancel()` mutates an order with no authorization / ownership check | Critical |
| | LW-02 + LW-04 | untyped, unlocked `public $orderId` tamperable from the client — mount-set, reachable via `$wire.set()` (one block, fix lock-scalar) | Critical |
| | LW-19 | `cancel()` persists `$reason` with no validation | High |
| | LW-23 | untyped public property | High |

## v3-app — clean component (expected: zero findings)

| File | Why it is clean |
|------|-----------------|
| `v3-app/app/Livewire/ContactForm.php` | typed `#[Validate]` properties, rate-limited `submit()`, no privileged actions |

## v3-app — Volt functional components

`v3-app/composer.json` declares `livewire/volt`, so Step B's Volt discovery searches must
fire for this app. Neither file below contains `extends Component`, an anonymous
`new ... class` block, or a ⚡ filename — if either is missing from the audited inventory,
the discovery union failed. This is a coverage plant as much as a vulnerability plant.

| File | Check IDs | Plant description | Severity |
|------|-----------|-------------------|----------|
| `v3-app/resources/views/pages/gift-cards/redeem.blade.php` | LW-01 | `$redeem` closure updates a gift card (financial) with no authorization / ownership check | Critical |
| | LW-02 + LW-04 | unlocked functional state `cardId` — mount-set, reachable via `$wire.set()`, drives the redemption write (one block, fix lock-scalar in its Volt form `state([...])->locked()`) | Critical |
| | LW-18 | `$redeem` performs a financial redemption with no rate limiting — card IDs are brute-forceable | High |
| | LW-19 | `$redeem` persists `note` and acts on `cardId` with no validation | High |

Grading note: LW-23 does NOT apply to functional Volt state — `state()` takes names and
default values and has no native type declaration syntax (checklist.md "Volt components").
An LW-23 finding on either Volt file is a false positive.

## v3-app — clean Volt inline fragment (expected: zero findings)

| File | Why it is clean |
|------|-----------------|
| `v3-app/resources/views/dashboard.blade.php` | inline `@volt('account-alias')` fragment: `state(['accountId'])->locked()`, `Gate::authorize(...)` + `$this->validate(...)` in `$rename`, internal lookup helper wrapped in `protect()` |

Supporting (non-planted) files: `v3-app/composer.json` (declares `livewire/volt`, which
arms the Volt discovery searches),
`v3-app/resources/views/livewire/order-editor.blade.php`,
`v3-app/resources/views/livewire/contact-form.blade.php`.

---

## Per-check coverage checklist

Each check below is planted at least once. Count is the number of distinct planted
instances across both apps.

- [x] LW-01 (x4) — billing/checkout `pay()`, admin/user-editor `save()`+`makeAdmin()`, v3 OrderEditor `cancel()`, v3 Volt gift-cards/redeem `$redeem`
- [x] LW-02 (x4) — billing/checkout (`$amount`,`$orderId`), admin/user-editor (`$userId`), v3 OrderEditor (`$orderId`), v3 Volt gift-cards/redeem (`cardId`)
- [x] LW-03 (x1) — profile/show `$debugInfo`
- [x] LW-04 (x5) — messages/composer `wire:model="recipientId"`; LW-02 co-tags on billing/checkout (`$amount`,`$orderId`), admin/user-editor (`$userId`), v3 OrderEditor (`$orderId`), v3 Volt gift-cards/redeem (`cardId`)
- [x] LW-05 (x2) — messages/composer `#[On('refresh-inbox')]`; orders/status-editor legacy `$listeners` array `order-refresh` => `reloadOrder`
- [x] LW-06 (x1) — feed/index `#[Url] $filter`
- [x] LW-07 (x1) — admin/user-editor `makeAdmin()`
- [x] LW-08 (x2) — profile/show `$apiKeys`; profile/show `$debugInfo` (embeds stripe secret in snapshot)
- [x] LW-09 (x1) — profile/show `$email`
- [x] LW-10 (x1) — stats/dashboard `billingSummary()` cache:true
- [x] LW-11 (x1) — stats/dashboard `subscription()` persist:true
- [x] LW-12 (x1) — AppServiceProvider missing morphMap (app-level)
- [x] LW-13 (x1) — stats/dashboard `verifyCard()` addError echo
- [x] LW-14 (x1) — uploads/avatar `$avatar` no validation
- [x] LW-15 (x2) — admin/user-editor `update($this->all())`; profile/bulk-update array-deep-write `update($this->data)`
- [x] LW-16 (x1) — feed/index `whereRaw` interpolation
- [x] LW-17 (x1) — feed/index `{!! $post->body !!}`
- [x] LW-18 (x3) — messages/composer `send()` no rate limit; billing/checkout `pay()` charges a card with no rate limit; v3 Volt gift-cards/redeem `$redeem` no rate limit on a brute-forceable financial action
- [x] LW-19 (x7) — messages/composer recipient `['required']` only; unvalidated persists in billing/checkout `pay()`, admin/user-editor `save()`, profile/show `updateDisplayName()`, profile/bulk-update `save()`, v3 OrderEditor `cancel()`, v3 Volt gift-cards/redeem `$redeem`
- [x] LW-20 (x1) — AppServiceProvider missing `addPersistentMiddleware` (app-level)
- [x] LW-21 (x1) — missing `setUpdateRoute` hardening (app-level, observation)
- [x] LW-22 (x1) — routes/web.php unprotected `/account/settings`
- [x] LW-23 (x11) — untyped public properties across billing/checkout, admin/user-editor, messages/composer, v3 OrderEditor, orders/status-editor, social/greeting, auth/return-redirect, profile/show, feed/index, uploads/avatar, stats/dashboard
- [x] LW-24 (x1) — orders/status-editor `updatedStatus()` hook writes with no ownership check
- [x] LW-25 (x1) — social/greeting user-controlled `$displayName` interpolated into `$this->js(...)`
- [x] LW-26 (x1) — wallet/balance raw balance + api token in `dispatch(...)->to(...)` payload
- [x] LW-27 (x1) — auth/return-redirect `#[Url] $returnTo` passed to `$this->redirect(...)` with no allow-list

Clean files (expected zero findings): `settings/⚡notifications.blade.php`,
`posts/⚡show.blade.php`, `v3-app/.../ContactForm.php`,
`v3-app/resources/views/dashboard.blade.php` (Volt inline fragment).
