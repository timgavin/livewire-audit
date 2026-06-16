# Snapshot Internals: What Livewire Serializes to the Browser

Facts verified against Livewire v4.3.0 source and official 3.x/4.x docs, 2026-06-11. Citations are
short parentheticals against the Livewire v4.3.0 tree (`vendor/livewire/livewire/`).

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
  On the next request, a mismatch throws `CorruptComponentPayloadException` (419). Core rate-limits
  checksum failures at 10 per IP per 600s before throwing `TooManyRequestsHttpException`
  (Checksum.php, `$maxFailures = 10`, `$decaySeconds = 600`, keyed by `request()->ip()`).

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

- **`firstOrFail()` on hydrate** — the re-fetch is wrapped in a lazy proxy, so a deleted/inaccessible
  row throws when the property is first accessed (lazily) on the next request, not silently — but a
  component that never touches the property that request will not trigger the throw.
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
`Checksum::verify()` with `CorruptComponentPayloadException` / 419 (Checksum.php). Repeated failures
hit the 10/IP/600s limiter. One deliberate exception: `memo.children` is `unset()` before hashing
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

- `__dispatch` — event dispatch (HandleComponents.php).
- `__lazyLoad` — re-runs the component `mount()` with encoded mount params
  (src/Features/SupportLazyLoading/SupportLazyLoading.php).
- `__lazyLoadIsland` — island lazy render (src/Features/SupportIslands/SupportIslands.php).

These are framework-controlled and cannot be turned into arbitrary method calls, but by two
different mechanisms: `__dispatch` and `__lazyLoad` carry an encoded snapshot that is re-verified
through `Checksum::verify()` (so the params are integrity-protected), while `__lazyLoadIsland` reads
the island name from the call metadata and is protected instead by the server-side `getIslands()`
allowlist (the named island must already exist on the component) — not by the checksum. Either way a
client cannot substitute arbitrary inputs, but note both are additional client-invokable endpoints
beyond plain public methods.

**Lifecycle hooks are NOT on the callable surface, but they DO run on client input.** `mount`,
`boot`/`booted`, `hydrate*`/`dehydrate*`, and `updating*`/`updated*` are in `$protectedMethods`
(SupportLifecycleHooks.php) so they cannot be invoked as actions — but `updating{Prop}`/
`updated{Prop}` fire on every property update (every keystroke under `wire:model.live`) with the raw
client value passed in. A write or authorization decision inside such a hook runs on untrusted input
with none of the action-level scrutiny. See checklist LW-24.

**The snapshot is not the only thing sent to the client.** Two other response channels carry
server data to the browser and are invisible to a snapshot-only audit: the `dispatches` effect
(every `$this->dispatch()` event name + params, including `->to()` targeted ones — see LW-26) and
the `xjs` effect (every `$this->js()` expression, evaluated as JavaScript client-side — see LW-25).
Audit those alongside the snapshot.

## 5. v3 vs v4

Per the official docs, the snapshot/serialization/tampering behavior described here is substantively
identical between Livewire 3 and Livewire 4 (Livewire docs). The differences are component file layout
and discovery (v4's single-file/multi-file components and the optional, purely-cosmetic high-voltage
filename prefix) — not anything in this document. Everything above applies to both versions.

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
