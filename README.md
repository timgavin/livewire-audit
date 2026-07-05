# Livewire Security Audit

A Claude Code skill that audits Laravel Livewire apps (v3 and v4) for security problems,
then optionally fixes them. It exists because Livewire's convenience hides a large
client-facing attack surface: every public property round-trips through the browser, every
public method is callable from devtools whether or not your UI references it, and anything
scalar you put on a public property is readable in page source.

The skill runs a full audit of every component in your app — no sampling — and produces a
severity-ranked report where every finding carries quoted evidence, a concrete exploit
scenario, and a specific fix. Every component form is inventoried: class-based, v4
single-file and multi-file, and Volt (functional API, class API, and inline `@volt`
fragments). Findings are verified twice before they reach the report:
audit subagents flag candidates, then the main session re-reads every flagged line and
discards anything that does not hold up. A clean report means your components were checked
and came back clean, not that the tool ran out of patience.

## What it checks

27 checks across four surfaces. Every engine-behavior claim behind them was verified
against the Livewire v4.3.0 source and the official 3.x/4.x docs, and is cited in the
reference files.

**Client tampering**
- LW-01 every public action authorizes; parameters treated as untrusted input
- LW-02 `#[Locked]` on identity/price/ownership scalars the client must not write
- LW-03 properties that never needed to be public (use `#[Computed]` or `protected`)
- LW-04 unlocked properties whose writes drive queries, payments, or permissions
- LW-05 `#[On]` listeners and the `$listeners` array — dispatchable from the browser with attacker-controlled payloads
- LW-06 `#[Url]` properties validated before use
- LW-07 public helper methods that should be `protected`
- LW-24 lifecycle hooks (`updated*`/`hydrate*`/`mount`) that act on raw client input — the blind spot LW-01 skips

**Data exposure**
- LW-08 scalars/arrays/plain Collections serialize fully into `wire:snapshot` in page source
- LW-09 model attributes copied into scalars (`$hidden` will not save you)
- LW-10 `#[Computed(cache: true)]` on user-scoped data — one value shared across all users
- LW-11 `#[Computed(persist: true)]` holding sensitive data with long TTLs
- LW-12 model class names leaking in snapshots (morphMap)
- LW-13 secrets/PII echoed back in validation errors and flash messages
- LW-25 `$this->js()` expressions that interpolate user data — XSS the snapshot audit can't see
- LW-26 sensitive data shipped to the browser through dispatched events (not in the snapshot)

**Standard web surface, Livewire-flavored**
- LW-14 file upload validation (per-property rule AND the global temp-endpoint rule)
- LW-15 mass assignment from `$this->all()` or a whole array property (`data.is_admin` deep writes)
- LW-16 properties interpolated into raw SQL
- LW-17 `{!! !!}` on user-controlled data
- LW-18 rate limiting on expensive/abusable actions (core Livewire ships none)
- LW-19 validation that actually constrains (`exists:` is not ownership)
- LW-27 open redirect from a client-controlled `#[Url]`/property target

**Configuration**
- LW-20 custom middleware not registered persistent (route middleware does NOT all re-run
  on component updates — only Livewire's default persistent list does)
- LW-21 update route exposure
- LW-22 full-page component routes without auth middleware
- LW-23 native type declarations (untyped properties accept any JSON shape the client sends)

## Install

In a Claude Code session, add the marketplace and install the plugin:

```
/plugin marketplace add timgavin/claude-plugins
/plugin install livewire-audit@timgavin
```

After it installs, run `/reload-plugins`. The skill is then invoked as
`/livewire-audit:livewire-audit` — Claude Code namespaces plugin skills by plugin name — or
just ask in plain language ("audit my Livewire app").

To get new releases automatically, open `/plugin`, select the `timgavin` marketplace, and
turn on auto-update. Third-party marketplaces have auto-update off by default, so this is a
one-time toggle on your end.

### Manual install (no plugin system)

To drop the skill in by hand, copy the one folder into your skills directory:

```bash
tmp=$(mktemp -d)
git clone --depth 1 https://github.com/timgavin/livewire-audit.git "$tmp"
mkdir -p ~/.claude/skills
cp -R "$tmp/skills/livewire-audit" ~/.claude/skills/livewire-audit
rm -rf "$tmp"
```

Or per-project: copy it to `.claude/skills/livewire-audit` inside the project instead.

## Use

From a Claude Code session in your Laravel project, run the audit. The command depends on
how you installed it:

- Plugin install: `/livewire-audit:livewire-audit`
- Manual install: `/livewire-audit`
- Either way, plain language works too: "audit my Livewire app"

The read-only audit writes a report to `livewire-audit-<date>.md` in the project root.
Append `--fix` to apply fixes for confirmed findings instead of only reporting them — e.g.
`/livewire-audit:livewire-audit --fix`.

The audit makes no changes to your code and runs no migrations or seeders. If your app
happens to be runnable, it will additionally pull a real rendered page and confirm
snapshot-exposure findings against the actual `wire:snapshot` payload; if not, those
findings are labeled statically inferred.

## Other agents

This is built and tested as a Claude Code skill. It uses the open Agent Skills format
(`SKILL.md` + frontmatter), so other runtimes that support that format — Copilot CLI,
Codex, Gemini CLI — can load it too; install it in that tool's skills directory instead of
`~/.claude/skills` and invoke it by name rather than the `/livewire-audit` slash command.

One caveat, stated plainly: the audit's accuracy comes from fanning the work out across
parallel read-only subagents and then re-verifying every finding in the orchestrating
context. That orchestration is written for Claude Code's subagent model. An agent without
parallel subagent dispatch can still run the checks, but in a single context and without
that verification stage — a degraded run. The checklist, snapshot internals, and fix
patterns themselves are pure Livewire/PHP knowledge and carry over unchanged. I have not
tested the skill on Codex, Copilot CLI, or Gemini CLI, so treat it as format-compatible
there, not verified.

Fix mode applies the smallest sufficient fix per finding (`#[Locked]` before visibility
changes before restructuring), never weakens validation, never commits, and refuses to
guess authorization rules — if a fix needs a product decision like "which policy applies
here," it collects the question for you instead of inventing an answer.

## Sample output

Trimmed (and lightly edited) from a real run against an earlier, smaller revision of
the bundled fixture apps — the counts reflect that revision, not the current corpus:

```markdown
# Livewire Security Audit - v4-app - 2026-06-11
Livewire version: v4 | Components audited: 9 | Findings: 17 (C/H/M/L/I: 5/4/3/3/2)

## Critical

### [LW-08] Live secrets (Stripe secret key, DB host) serialized into the page snapshot - resources/views/components/profile/⚡show.blade.php:22-27
Evidence: `'stripe_key' => config('services.stripe.secret'),` assigned to the public
array property `public $debugInfo = [];`
Exploit: `$debugInfo` is a plain PHP array, so it serializes in full into the
`wire:snapshot` attribute in the page HTML. Anyone who loads the page and view-sources
it reads the live Stripe secret key in plaintext.
Fix: fixes.md "computed-instead-of-public" - debug config values must never sit on a
client-serialized property.

### [LW-16] SQL injection via #[Url] filter interpolated into whereRaw - resources/views/components/feed/⚡index.blade.php:15
Evidence: `->whereRaw("category = '$this->filter'")`
Exploit: `$filter` is hydrated straight from the `?filter=` query string and
interpolated unbound into raw SQL. `/feed?filter=' OR '1'='1` controls the query, and
the route has no auth middleware, so this is reachable unauthenticated.
Fix: fixes.md "bind-not-interpolate" - bound where() plus an allowlist on $filter.

...

## Clean components
- resources/views/components/posts/⚡show.blade.php - model-typed property, authorized
  actions, validated input, escaped output.

## Discarded during verification
10 subagent findings did not survive main-thread verification.
```

## What it does not cover

- General Laravel security outside Livewire (route audits, headers, CSP, CSRF config) —
  except where it intersects a Livewire surface.
- Livewire v2.
- Static-analysis tooling. This is a judgment-based audit run by a model against your
  actual code, not an AST scanner with pattern rules.

## How the claims were verified

The reference files (`references/snapshot-internals.md`, `references/checklist.md`,
`references/fixes.md`) cite the Livewire source file or docs page for every engine-behavior
claim — what serializes into snapshots, exactly what `#[Locked]` throws, how model-typed
properties are protected (it is not `#[Locked]`, despite what the docs imply), what happens
when a client sends a wrong-typed value, and which middleware actually re-runs on component
updates. Verified against Livewire v4.3.0 and the official 3.x/4.x documentation. If you
find a claim that is wrong for a newer Livewire version, please open an issue.

## License

MIT
