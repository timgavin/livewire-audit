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
- **LW-14** (default global upload rule) — app-level, planted in
  `v4-app/config/livewire.php`: `temporary_file_upload.rules` is `null`, so the
  temporary-upload endpoint takes any file type up to 12 MB, and the app has an upload
  component. The component's own missing rule is a separate row.
- **LW-20** (no `Livewire::addPersistentMiddleware(...)`) — app-level, planted by
  its absence in `v4-app/app/Providers/AppServiceProvider.php`.
- **LW-21** (custom update route without the `web` group) — app-level, planted in
  `v4-app/app/Providers/AppServiceProvider.php`. Whether it is a finding depends on the
  release, which is what the plant tests: `v4-app/composer.json` allows `^4.0` and there
  is no lock file, so the app is audited as 4.0.0, and no release before 4.2.0 adds `web`
  to a custom route. A report that calls it safe because "v4 adds `web`" has missed it.
  `v3-app` customizes nothing; its expected result is a line, not a finding.
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
- The Severity column is a guide to the intended tier, not a graded expectation. A run
  passes or fails on what it found, not on the tier it chose.
- Expected inventory: v4-app has 15 components (14 single-file, 1 multi-file) and 6
  full-page component routes; v3-app has 4 components (2 class-based, 1 Volt functional
  page, 1 inline `@volt` fragment) and no routes.
- `profile/show` copies the signed-in user's own email into `public string $email` and
  renders it. Checklist LW-09 excludes the user's own data that the page shows or lets
  them edit, so that is not a finding. The same goes for the bound fields of
  `settings/notifications` and of the v3 `dashboard` fragment.
- Secondary check IDs that legitimately co-fire on an already-vulnerable component
  are correct, not invented, when they are consistent with checklist.md — e.g. LW-03
  on `profile/show`'s never-rendered `$apiKeys`, or LW-04 on any client-writable
  property reaching a sensitive sink. "No invented findings" is graded against the
  clean components and against findings with no quotable evidence.
- The report's `Livewire release:` line is graded on the release it names: `4.0.0`
  inferred from `^4.0` for v4-app and `3.6.0` inferred from `^3.6` for v3-app, each with
  the protections it predates (4.2.0, 4.3.0 and 4.4.1 for v4-app; 3.8.0 and 3.8.5 for
  v3-app). Neither fixture has a `composer.lock`.
- A check result that is not a finding (v3-app's default update route; `/feed` being public by
  design; a check that cannot run because v3-app has no `routes/` or `app/Providers/`)
  belongs on a line in the report's "App-level checks and run notes" section. Written
  as a `###` block in any tier, Info included, it is graded as an invented finding.
- `/`, `/pricing`, and `/feed` in `v4-app/routes/web.php` are intentionally public
  routes. LW-22 is planted on `/account/settings` and on `/admin/users/{userId}`; an
  LW-22 finding on `/feed` is a false positive. Every component route in that file uses
  `Route::livewire(...)`, the form Livewire 4 requires for single-file and multi-file
  components; `/` and `/pricing` are plain `Route::view` pages with no component.

---

## v4-app — vulnerable single-file components

Filenames are ⚡-prefixed (U+26A1) per the Livewire 4 single-file-component
convention.

| File | Check IDs | Plant description | Severity |
|------|-----------|-------------------|----------|
| `v4-app/resources/views/components/billing/⚡checkout.blade.php` | LW-01 | `pay()` charges money with no authorization / ownership check | Critical |
| | LW-02 + LW-04 | untyped, unlocked `public $amount` and `public $orderId` tamperable from the client — mount-set, reachable via `$wire.set()`, drive the charge (one block, fix lock-scalar) | Critical |
| | LW-18 | `pay()` charges a payment gateway with no rate limiting / throttle | High |
| | LW-19 | `pay()` persists `charged_amount` and sends `note` to the payment gateway with no validation | High |
| | LW-23 | untyped public properties | High |
| `v4-app/resources/views/components/admin/⚡user-editor.blade.php` | LW-01 | `save()` and `makeAdmin()` mutate a client-tamperable `$userId`'s account (`makeAdmin()` sets `role='admin'`) with no authorization / ownership check | Critical |
| | LW-15 | `save()` does `$user->update($this->all())` — full mass-assignment from component state | Critical |
| | LW-07 | `public function makeAdmin()` privileged action never referenced by any `wire:` directive but still callable | High |
| | LW-02 + LW-04 | untyped, unlocked `public $userId` used in queries — mount-set, reachable via `$wire.set()` (one block, fix lock-scalar) | High |
| | LW-19 | `save()` passes `$this->all()` to `update()` with no validation | High |
| | LW-23 | untyped public properties | High |
| `v4-app/resources/views/components/profile/⚡show.blade.php` | LW-08 + LW-09 | `public $apiKeys` holds secrets copied from the model (`$user->api_keys`) and never rendered, so they are serialized to the browser (one block: removing the property fixes both) | Critical |
| | LW-08 | `public $debugInfo` array embeds `config('services.stripe.secret')` — secret serialized into `wire:snapshot` | Critical |
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
| | LW-11 | `#[Computed(persist: true, seconds: 86400)]` keeps the subscription in the cache for a day — sensitive data held far longer than needed (persist is keyed per component instance, so this is not the cross-user bleed of the LW-10 row) | High |
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

## v4-app — vulnerable multi-file component (no ⚡ prefix)

A Livewire 4 multi-file component is a directory holding `<name>.php` (the class, a plain
PHP file) and `<name>.blade.php` (the template). This one has no ⚡ on its directory, which
is what `make_command.emoji => false` produces. So its class is not in a `*.blade.php` file
and nothing about it carries the glyph: only a search that looks inside plain `.php` files
under `resources/views/` finds it. If it is missing from the audited inventory, discovery
failed. This is a coverage plant as much as a vulnerability plant.

| File | Check IDs | Plant description | Severity |
|------|-----------|-------------------|----------|
| `v4-app/resources/views/components/invoices/refund/refund.php` | LW-01 | `refund()` marks an invoice refunded with no authorization / ownership check, and `mount()` checks nothing either | Critical |

Supporting (non-planted) file: `v4-app/resources/views/components/invoices/refund/refund.blade.php`
(the component's template; not a component on its own).

## v4-app — clean components (expected: zero findings)

| File | Why it is clean |
|------|-----------------|
| `v4-app/resources/views/components/settings/⚡notifications.blade.php` | typed properties, `#[Locked] int $preferenceId`, `$this->authorize(...)` + `$this->validate(...)` in the toggle action |
| `v4-app/resources/views/components/posts/⚡show.blade.php` | model-typed `public Post $post`, authorized `like()`/`comment()`, rate-limited `comment()`, escaped `{{ }}` output, typed `mount(Post $post)` |

## v4-app — app-level files

| File | Check IDs | Plant description | Severity |
|------|-----------|-------------------|----------|
| `v4-app/routes/web.php` | LW-22 | `/account/settings` full-page Livewire route has no auth middleware (a protected `/billing/{orderId}` route is included for contrast) | High |
| | LW-22 | `/admin/users/{userId}` points at `admin.user-editor` with no auth middleware, and the component checks nothing itself | Critical |
| `v4-app/app/Providers/AppServiceProvider.php` | LW-20 | `boot()` exists but never calls `Livewire::addPersistentMiddleware(...)`, so the custom `EnsureUserIsVerified` middleware on `/billing/{orderId}` (`v4-app/routes/web.php`) is not re-applied to component update requests | High |
| | LW-12 | no `Relation::enforceMorphMap(...)` / morphMap registration anywhere | Medium |
| `v4-app/config/livewire.php` | LW-14 | `'rules' => null` under `temporary_file_upload`: the default global rule (any file type, 12 MB) gates the temporary-upload endpoint for every component, whatever a property's own rule says | Medium |
| `v4-app/app/Providers/AppServiceProvider.php` | LW-21 | `Livewire::setUpdateRoute(...)` returns `Route::post('/app/livewire/update', $handle)` with no `web` group. The app is audited as Livewire 4.0.0 (`^4.0`, no lock file), which adds nothing to a custom route (4.2.0 is the first release that appends `web`), so every component update runs without session or CSRF verification | High |

Supporting (non-planted) files: `v4-app/composer.json`,
`v4-app/app/Http/Middleware/EnsureUserIsVerified.php` (the custom middleware on the
protected `/billing/{orderId}` route; it is what makes the LW-20 row fire).

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
fire for this app. Neither Volt file (the one in the table below and the clean inline
fragment in the next section) contains `extends Component`, an anonymous `new ... class`
block, or a ⚡ filename — if either is missing from the audited inventory, the discovery
union failed. This is a coverage plant as much as a vulnerability plant.

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

## v3-app — app-level checks (expected: no finding)

`v3-app` deliberately has no `routes/`, `app/Providers/` or `config/` directory, so the
five app-level checks produce no finding block there. The report's "App-level checks and
run notes" section carries a line for each instead: LW-12, LW-20 and LW-22 `not
applicable`, naming the missing directory; LW-14 and LW-21 `clean` or `not applicable`.

Supporting (non-planted) files: `v3-app/composer.json` (declares `livewire/volt`, which
arms the Volt discovery searches),
`v3-app/resources/views/livewire/order-editor.blade.php`,
`v3-app/resources/views/livewire/contact-form.blade.php`.

---

## Per-check coverage checklist

Each check below is planted at least once. Count is the number of distinct planted
instances across both apps.

- [x] LW-01 (x5) — billing/checkout `pay()`, admin/user-editor `save()`+`makeAdmin()`, v4 multi-file invoices/refund `refund()`, v3 OrderEditor `cancel()`, v3 Volt gift-cards/redeem `$redeem`
- [x] LW-02 (x4) — billing/checkout (`$amount`,`$orderId`), admin/user-editor (`$userId`), v3 OrderEditor (`$orderId`), v3 Volt gift-cards/redeem (`cardId`)
- [x] LW-03 (x1) — profile/show `$debugInfo`
- [x] LW-04 (x5) — messages/composer `wire:model="recipientId"`; LW-02 co-tags on billing/checkout (`$amount`,`$orderId`), admin/user-editor (`$userId`), v3 OrderEditor (`$orderId`), v3 Volt gift-cards/redeem (`cardId`)
- [x] LW-05 (x2) — messages/composer `#[On('refresh-inbox')]`; orders/status-editor legacy `$listeners` array `order-refresh` => `reloadOrder`
- [x] LW-06 (x1) — feed/index `#[Url] $filter`
- [x] LW-07 (x1) — admin/user-editor `makeAdmin()`
- [x] LW-08 (x2) — profile/show `$apiKeys`; profile/show `$debugInfo` (embeds stripe secret in snapshot)
- [x] LW-09 (x1) — profile/show `$apiKeys` (a model attribute copied into a public property and never rendered; merged with LW-08)
- [x] LW-10 (x1) — stats/dashboard `billingSummary()` cache:true
- [x] LW-11 (x1) — stats/dashboard `subscription()` persist:true
- [x] LW-12 (x1) — AppServiceProvider missing morphMap (app-level)
- [x] LW-13 (x1) — stats/dashboard `verifyCard()` addError echo
- [x] LW-14 (x2) — uploads/avatar `$avatar` no validation; config/livewire.php default global upload rule (app-level)
- [x] LW-15 (x2) — admin/user-editor `update($this->all())`; profile/bulk-update array-deep-write `update($this->data)`
- [x] LW-16 (x1) — feed/index `whereRaw` interpolation
- [x] LW-17 (x1) — feed/index `{!! $post->body !!}`
- [x] LW-18 (x3) — messages/composer `send()` no rate limit; billing/checkout `pay()` charges a card with no rate limit; v3 Volt gift-cards/redeem `$redeem` no rate limit on a brute-forceable financial action
- [x] LW-19 (x7) — messages/composer recipient `['required']` only; unvalidated persists in billing/checkout `pay()`, admin/user-editor `save()`, profile/show `updateDisplayName()`, profile/bulk-update `save()`, v3 OrderEditor `cancel()`, v3 Volt gift-cards/redeem `$redeem`
- [x] LW-20 (x1) — AppServiceProvider missing `addPersistentMiddleware` (app-level)
- [x] LW-21 (x1) — AppServiceProvider custom update route without `web`, on a release that does not add it (app-level)
- [x] LW-22 (x2) — routes/web.php unprotected `/account/settings` and `/admin/users/{userId}`
- [x] LW-23 (x11) — untyped public properties across billing/checkout, admin/user-editor, messages/composer, v3 OrderEditor, orders/status-editor, social/greeting, auth/return-redirect, profile/show, feed/index, uploads/avatar, stats/dashboard
- [x] LW-24 (x1) — orders/status-editor `updatedStatus()` hook writes with no ownership check
- [x] LW-25 (x1) — social/greeting user-controlled `$displayName` interpolated into `$this->js(...)`
- [x] LW-26 (x1) — wallet/balance raw balance + api token in `dispatch(...)->to(...)` payload
- [x] LW-27 (x1) — auth/return-redirect `#[Url] $returnTo` passed to `$this->redirect(...)` with no allow-list

Clean files (expected zero findings): `settings/⚡notifications.blade.php`,
`posts/⚡show.blade.php`, `v3-app/.../ContactForm.php`,
`v3-app/resources/views/dashboard.blade.php` (Volt inline fragment).
