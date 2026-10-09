# Snapshot Internals: What Livewire Serializes to the Browser

Facts verified against Livewire v4.3.0 source and official 3.x/4.x docs, 2026-06-11. Citations are
short parentheticals against the Livewire v4.3.0 tree (`vendor/livewire/livewire/`). Where Livewire 3
behaves differently, section 5 says how; those differences were checked against the v3.8.10 source,
2026-10-08.

This is the reference for what a Livewire component puts in front of the client, what of that is
readable in page source, and what a client can change. If you are auditing a Livewire app, this is
the trust boundary.

## 1. What the snapshot is

Every Livewire component renders a `wire:snapshot` attribute into the HTML it returns. It is a JSON
object embedded in page source — fully readable, no auth required to read it
(src/Mechanisms/HandleComponents/HandleComponents.php emits `'wire:snapshot' => $snapshot`). It has
three top-level keys (HandleComponents.php, `toSnapshot()`):

- **`data`** — every public property defined on the component subclass, in dehydrated form. This is
  the part that leaks data (see section 2).
- **`memo`** — component metadata: `id`, `name`, plus a spread of the render context's memo, which
  carries `path`, `method`, `locale`, `children`, `lazyLoaded`, and validation `errors`
  (HandleComponents.php builds `memo => ['id' => ..., 'name' => ..., ...$context->memo]`).
- **`checksum`** — an HMAC-SHA256 over `data` + `memo`, keyed by the app encryption key
  (src/Mechanisms/HandleComponents/Checksum.php, `hash_hmac('sha256', ..., app('encrypter')->getKey())`).
  On the next request, a mismatch throws `CorruptComponentPayloadException` (419). v4 core also
  rate-limits checksum failures at 10 per IP per 600s before throwing `TooManyRequestsHttpException`
  (Checksum.php, `$maxFailures = 10`, `$decaySeconds = 600`, keyed by `request()->ip()`); v3 has no
  such limiter.

Example (illustrative shape — values depend on the component):

```json
{
  "data": {
    "count": 3,
    "tags": ["draft", "review"],
    "post": [null, { "class": "App\\Models\\Post", "key": "01HXYZ...", "s": "mdl" }]
  },
  "memo": {
    "id": "k3nf9",
    "name": "posts.editor",
    "path": "posts/42/edit",
    "method": "GET",
    "locale": "en",
    "children": [],
    "lazyLoaded": true,
    "errors": []
  },
  "checksum": "9a1c...<hmac-sha256>"
}
```

Note the `post` property: an Eloquent model dehydrates to a `class` + `key` tuple with a synth tag
(`mdl`), not its attributes. That is the subject of the next section.

## 2. What serializes, by type

The shape in `data` depends entirely on the property's runtime type.

| Property type | What lands in `data` | Visible in page source? |
| --- | --- | --- |
| scalar (int/float/string/bool), array, plain `Collection` | the full value | Yes — entire value |
| `Eloquent\Model` | `class` + primary `key` only; attributes never serialized (relationship names are serialized only under legacy model binding, off by default) | Class name + ID only |
| `Eloquent\Collection` | `class` + the keys of its members only; attributes never serialized | Class names + IDs only |
| enum / `DateTime` / `Stringable` | their value form (enum value, datetime string, string) | Yes — the value |
| `#[Computed]` property | nothing | Never serialized |

Models and Eloquent collections are re-fetched on the next hydrate, not restored from the snapshot:
`ModelSynth::hydrate()` runs `newQueryForRestoration($key)->useWritePdo()->firstOrFail()`
(src/Features/SupportModels/ModelSynth.php). Two consequences worth flagging in an audit:

- **`firstOrFail()` on hydrate** — in v4 the re-fetch is wrapped in a lazy proxy, so a
  deleted/inaccessible row throws when the property is first accessed (lazily) on the next request,
  not silently — but a component that never touches the property that request will not trigger the
  throw. v3 runs the query during hydration on every request, so there it always throws.
- **Query constraints are not re-applied.** If the property was assigned from a `select(...)`- or
  `where(...)`-narrowed query, hydrate re-loads the full row by primary key with no constraint
  (ModelSynth.php). See section 3(d).

`#[Computed]` properties are never put in the snapshot (Livewire docs, computed-properties); they
are recomputed server-side on demand and are not a client surface.

## 3. The implications attackers care about

**(a) Public scalar/array properties are world-readable.** Anything you assign to a non-model public
property — a scalar, an array, a plain Collection — is in page source verbatim
(HandleComponents.php `dehydrateProperties()` serializes every public property defined on the
subclass). Treat a public property as equivalent to printing the value into the HTML. Do not stash
secrets, internal IDs you wouldn't expose, full unfiltered query results, or another user's data in
one. Use `#[Computed]` or a `protected`/`private` property instead.

**(b) `$hidden` is irrelevant to snapshot safety.** Eloquent's `$hidden`/`$visible` governs array/JSON
serialization of a model's attributes. The snapshot never serializes model attributes at all — only
`class` + `key` (ModelSynth.php). So `$hidden` neither protects nor exposes anything in the snapshot,
in either direction. A property holding a model with a "hidden" password column is just as safe (and
just as constrained) as one without; `$hidden` does no work here.

**(c) Class names leak.** A model property exposes its fully-qualified class name in page source
(e.g. `App\Models\InternalAuditLog`) (ModelSynth.php dehydrates `class => <alias>`). The alias is the
FQCN unless a `morphMap` entry remaps it. If you don't want your namespace/model layout visible,
register a `morphMap` alias for the model.

**(d) Re-fetch on hydrate defeats `select()`-narrowing.** A property assigned from a column-limited
query (`Post::select('id','title')->find($id)`) dehydrates to class+key, and on the next request
hydrates by re-loading the **entire** row, every column, via `firstOrFail()` (ModelSynth.php). Code
that relied on a property never holding a sensitive column because it was `select()`-ed out is wrong
after the first round-trip. Authorize on access; do not rely on narrowed loads as a guard.

## 4. Tampering protections and their limits

**Checksum prevents snapshot forgery.** A client cannot alter `data` or `memo` and have it accepted:
the HMAC is keyed by the app encryption key, which the client does not have, so any edit fails
`Checksum::verify()` with `CorruptComponentPayloadException` / 419 (Checksum.php). On v4, repeated
failures hit the 10/IP/600s limiter. One deliberate exception: `memo.children` is `unset()` before hashing
(Checksum.php) and is intentionally client-mutable — it is DOM-diff bookkeeping, not a security
boundary, so do not treat anything you put in `children` as integrity-protected.

**`#[Locked]` blocks client writes to that property.** A client update targeting a `#[Locked]`
property throws `CannotUpdateLockedPropertyException`, which renders a bare 419 in production and the
full error page in debug (src/Features/SupportLockedProperties/BaseLocked.php,
CannotUpdateLockedPropertyException.php). The throw is dispatched by the attribute `update()` hook
(src/Features/SupportAttributes/SupportAttributes.php).

**Model-typed properties are immutable from the client — but by a DIFFERENT mechanism.** The docs'
"model-typed properties are auto-locked" phrasing is imprecise. In v4 core there is no injected
`#[Locked]` on model properties. Instead the model synthesizer refuses direct client access:
`ModelSynth::get()`/`set()`/`call()` each throw a plain `\Exception` ("Can't set/access/call model
properties/methods directly") — NOT `CannotUpdateLockedPropertyException`
(ModelSynth.php; EloquentCollectionSynth.php throws the same for collections). And because dehydrate
emits only class+key, there is nothing attribute-shaped to tamper with anyway. So the protection is
real, but it is the synthesizer + dehydrate design, not the `#[Locked]` path. (Legacy attribute
binding exists only behind `livewire.legacy_model_binding`, default `false`, and even then is gated
on an explicit validation `rule()` — src/Features/SupportLegacyModels/EloquentModelSynth.php.)

**Everything else public and unlocked is client-writable.** Any public, non-model, non-`#[Locked]`
property can be set by the client via `$wire.set()` or an injected `wire:model` directive — Livewire
docs state public methods and properties are reachable from the client without any `wire:` directive
referencing them (Livewire docs, actions/properties). Lock what must not change client-side; validate
and re-authorize everything else server-side on every request.

**Callable surface.** What a client can invoke is the component's own public methods (those defined on
the subclass, minus `render`) — the allowlist checked in `HandleComponents::callMethods()`
(`in_array($method, $methods)`). PLUS three framework magic entry points that short-circuit that
allowlist via lifecycle `call` hooks that return early before the `in_array` check:

- `__dispatch` — event dispatch (src/Features/SupportEvents/SupportEvents.php).
- `__lazyLoad` — re-runs the component `mount()` with encoded mount params
  (src/Features/SupportLazyLoading/SupportLazyLoading.php).
- `__lazyLoadIsland` — island lazy render (src/Features/SupportIslands/SupportIslands.php).

These are framework-controlled and cannot be turned into arbitrary method calls, but each is
limited by a different mechanism, and only one of them protects its params:

- `__lazyLoad` takes an encoded snapshot and passes it through `fromSnapshot()`, whose first
  step is `Checksum::verify()` (SupportLazyLoading.php `resurrectMountParams()`;
  HandleComponents.php `fromSnapshot()`). Its mount params are integrity-protected.
- `__dispatch` is NOT checksum-protected. The event name and params come straight from the call.
  The name must match one of the component's registered listeners; any authorization attribute
  on that listener method is run; the method is then called with the client's params
  (SupportEvents.php). Reach is limited to listener methods, the payload is not limited at all —
  see checklist LW-05.
- `__lazyLoadIsland` reads the island name from the call metadata and is limited by the
  server-side `getIslands()` allowlist (the named island must already exist on the component),
  not by the checksum (SupportIslands.php).

All three are client-invokable endpoints beyond plain public methods. (v3 has the first two only;
islands are a v4 feature.)

**Lifecycle hooks are NOT on the callable surface, but they DO run on client input.** `mount`,
`boot`/`booted`, `hydrate*`/`dehydrate*`, and `updating*`/`updated*` are in `$protectedMethods`
(SupportLifecycleHooks.php) so they cannot be invoked as actions (v3's list is shorter and leaves
`boot`/`booted` callable; see section 5) — but `updating{Prop}`/
`updated{Prop}` fire on every property update (every keystroke under `wire:model.live`) with the raw
client value passed in. A write or authorization decision inside such a hook runs on untrusted input
with none of the action-level scrutiny. See checklist LW-24.

**The snapshot is not the only thing sent to the client.** Two other response channels carry
server data to the browser and are invisible to a snapshot-only audit: the `dispatches` effect
(every `$this->dispatch()` event name + params, including `->to()` targeted ones — see LW-26) and
the `xjs` effect (every `$this->js()` expression, evaluated as JavaScript client-side — see LW-25).
Audit those alongside the snapshot.

## 5. v3 vs v4

The trust boundary is the same in Livewire 3 and Livewire 4. These were compared in the v3.8.10
source against v4.3.0 and match: the snapshot's `data`/`memo`/`checksum` shape and the HMAC over it
(with `memo.children` left out), what the model and Eloquent-collection synthesizers dehydrate and
refuse, `#[Locked]` (including deep writes under a locked name), the callable-surface allowlist,
`PublicPropertyNotFoundException` for an undeclared property, deep writes into arrays, the default
persistent-middleware list, the `cache:` and `persist:` computed keys and the 3600-second default,
the temporary-upload defaults, and the `dispatches` and `xjs` effects.

These differ, and the checklist's version notes rely on them:

- **`setUpdateRoute()`.** v4 adds the Livewire header guard and the `web` group to whatever route
  the callback returns. v3 adds nothing: the route keeps exactly the middleware the callback gave it
  (src/Mechanisms/HandleRequests/HandleRequests.php in each). On v3 a custom update route without
  `web` has no session and no CSRF verification. See checklist LW-21.
- **Checksum-failure rate limiting** (10 per IP per 600s) is v4 only. v3's Checksum.php throws
  `CorruptComponentPayloadException` and does nothing else.
- **Model re-fetch on hydrate.** v4 wraps it in a lazy proxy and reuses a route-bound model; v3
  runs `firstOrFail()` during hydration on every request (ModelSynth.php in each).
- **Lifecycle methods that cannot be called as actions.** v4: `mount`, `boot`, `booted`,
  `exception`, `hydrate*`, `dehydrate*`, `updating*`, `updated*`, `rendering`, `rendered`. v3:
  `mount`, `exception`, `hydrate*`, `dehydrate*`, `updating*`, `updated*` only, so a public
  `boot()`, `booted()`, `rendering()` or `rendered()` is client-callable there
  (SupportLifecycleHooks.php in each).
- **Framework magic entry points.** v3 has `__dispatch` and `__lazyLoad`. Islands and
  `__lazyLoadIsland` are v4 only.
- **Authorization attribute.** `#[Authorize]` exists in v4 only, and v4 runs it on the `__dispatch`
  path before the listener is called (SupportEvents.php). v3 has no such attribute.
- **A wrong-type value on a typed property.** v4 turns the `TypeError` into `abort(419)` in
  production; v3 re-throws it (HandleComponents.php, `setComponentPropertyAwareOfTypes()`). Both
  reject the value. Section 6 describes v4.
- **Routing and layout.** `Route::livewire()`, single-file and multi-file components, and the
  optional, purely-cosmetic high-voltage filename prefix are v4 only.

## 6. Type-mismatch behavior

For the live-update path (a `wire:model` / property-update payload), Livewire neither coerces nor
silently ignores a JSON/PHP type mismatch on a typed scalar property
(src/Mechanisms/HandleComponents/HandleComponents.php):

- The assignment in `setComponentPropertyAwareOfTypes()` runs inside a `try` and relies on PHP's own
  typed-property `\TypeError`.
- Empty string or `null` is the one special case: the property is `unset()` (becomes `null`).
- Anything else that triggers a `\TypeError` (e.g. an array sent to `public int $count`) is caught
  and converted to `abort(419)` in production; in debug the raw `\TypeError` is re-thrown. The
  in-source comment attributes this to "a bot/scanner probing typed properties with wrong-type values."

So you cannot, for example, smuggle an array into `public int $count` and get a coerced `0` — you get
a 419. The security argument for typing: **untyped public properties accept any JSON shape the client
sends**, with no `\TypeError` to catch and no 419. Typing a property is what makes the framework
reject malformed input for that property. Type your public properties.
