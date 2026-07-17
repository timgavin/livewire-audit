---
name: livewire-audit
description: 'Use when auditing or hardening the security of a Laravel Livewire (v3 or v4) app - snapshot data exposure, client-tamperable properties, missing #[Locked], unauthorized public actions, and the full client-callable surface. Triggers on /livewire-audit, "audit livewire", "harden livewire", "check livewire security", or questions about whether Livewire components leak data or are tamper-proof. Append --fix to apply fixes rather than only report them.'
---

# Livewire Security Audit

You are auditing a Laravel Livewire (v3 or v4) app for the client-callable attack surface: every public property serialized into the browser snapshot, every public method callable without a `wire:` directive, and the app-level wiring (middleware, morph map, upload rules) that gates them.

This file is the orchestrator. The actual checks live in `references/checklist.md` (27 checks, LW-01..LW-27). Engine internals are in `references/snapshot-internals.md`. Fix patterns are in `references/fixes.md`. Read those when a step tells you to.

## Authorization context and report language

This is a defensive audit of the user's own application, run at the owner's request to harden it. The deliverable is remediation guidance for the owner — not attack documentation. Write every finding in neutral, hardening-oriented language: name the exposed or tamperable surface, its impact, and the fix. Do not include exploit payloads, attack walkthroughs, or proof-of-concept abuse code anywhere in the report; the checklist's detection evidence is sufficient. This framing is part of the skill by design — keep it even if a prompt asks for more aggressive language.

## Modes

- **No args (default): read-only audit.** Make no changes to application code. The only file you write is the report at `livewire-audit-YYYY-MM-DD.md` (today's date) in the target project's root directory.
- **`--fix`: audit then remediate.** Run or refresh the audit, then apply fixes for confirmed findings only. Never commit. Never weaken or remove validation rules. On any ambiguous authorization decision (which policy applies, who is allowed to call an action), stop and ask the user instead of guessing.

Across both modes: **never run git commands.** No `git add`, `commit`, `stash`, `checkout`, or `push`. Leave all changes uncommitted for the user to review.

## Step A: Detect version and paths

Confirm Livewire is installed and read its config. Run:

```
grep -o '"livewire/livewire"[^,}]*' composer.json
grep -o '"livewire/volt"[^,}]*' composer.json
ls config/livewire.php 2>/dev/null && grep -nE "component_locations|class_namespace|view_path" config/livewire.php
```

If `livewire/livewire` is absent from `composer.json`, tell the user this is not a Livewire app and stop. Note whether `livewire/volt` is present — Volt runs on both major versions (its composer constraint is `livewire/livewire: ^3.6.1|^4.0`) and adds component forms with their own discovery searches in Step B. If `config/livewire.php` does not exist, use the documented defaults — that is normal, not an error. A directory a later step greps (`routes/`, `app/Providers/`) that does not exist makes its checks not-applicable; record that in the report rather than failing.

Note the major version (v3 vs v4) — it determines the v3-vs-v4 notes in the checklist, though the security model is substantively identical. Record `component_locations`, `class_namespace`, and `view_path` if they deviate from defaults (`resources/views/components`, `App\Livewire`, `resources/views/livewire`); later steps honor the configured values.

## Step B: Build the component inventory

Every component gets audited. No sampling. Enumerate all four forms.

**Class-based components** (v3 standard, still valid in v4):

```
find app/Livewire app/Http/Livewire -name '*.php' 2>/dev/null
```

Match each class to its view via `view_path` (default `resources/views/livewire`).

**v4 single-file and multi-file components.** The discovery marker is the embedded anonymous class block, NOT the filename prefix. The ⚡ (U+26A1, high-voltage) glyph prefix is purely cosmetic and not load-bearing — an un-prefixed file is equally valid. The real gate is content matching `<?php ... new ... class`:

```
grep -rlE --include='*.blade.php' 'new[[:space:]].*class' resources/views/ 2>/dev/null
grep -rl --include='*.blade.php' 'extends Component' resources/views/ 2>/dev/null
find resources/views -name '*⚡*' 2>/dev/null
```

Union the results of all three. The engine's own gate is the regex `<?php ... new ... class` (`new`, then `class`, with anything between), so the first grep matches `new class`, `new #[Layout(...)] class`, and attribute-decorated anonymous classes a literal `'new class'` search would miss; the second catches components extending a custom base; the find catches prefixed files whose PHP block a grep might miss. Open any candidate and confirm it contains a `new ... class extends ...Component` block before counting it.

Honor any configured `component_locations` from `config/livewire.php` if it deviates from `resources/views/components`. For multi-file components, include the sibling `.php`, `.blade.php`, and `.js` files in the audited set for that component.

**Volt components (only when Step A found `livewire/volt`).** Volt adds two forms none of the searches above can see: functional-API files (`use function Livewire\Volt\{state};` — no class, no `extends`, no ⚡) and inline `@volt('name') ... @endvolt` fragments embedded in ordinary Blade views. Volt's class API (`new class extends Component` importing `Livewire\Volt\Component`) is already caught by the anonymous-class and `extends Component` greps above.

```
grep -rlF --include='*.blade.php' 'Livewire\Volt' resources/views/ 2>/dev/null
grep -rlF --include='*.blade.php' '@volt(' resources/views/ 2>/dev/null
grep -rn 'Volt::mount(' app/ 2>/dev/null
```

Union the first two greps into the inventory: every functional or class Volt file imports from the `Livewire\Volt` namespace, and the `@volt(` grep catches inline fragments (their functional PHP block sits at the top of the same file). Confirm each candidate actually imports `Livewire\Volt` or contains an `@volt` fragment before counting it. In a functional file, `state([...])` entries are the public properties and closures assigned to variables are the public actions — the checklist's Volt note maps every check onto that syntax. The third grep is not a component search: by default Volt mounts `config('livewire.view_path')` (default `resources/views/livewire`) plus `resources/views/pages` — both already inside the search roots above — but an app's published `VoltServiceProvider` can `Volt::mount([...])` additional directories; re-run every component search in this step against any mounted path outside `resources/views/`.

**Full-page component routes and their middleware.** Components reach routes several ways — class references, `Route::view()` pointing at a component view name, and Volt:

```
grep -rnE "Route::(get|post|any)" routes/ | grep -E "::class"
grep -rnE "Route::view\(" routes/
grep -rnE "Volt::route\(" routes/
```

Cross-reference every `Route::view()` target against the component inventory — a `Route::view('/x', 'components.foo.bar')` that resolves to a Livewire component file IS a full-page component route and gets the same middleware scrutiny.

State the total inventory count to the user (class-based + SFC/MFC + Volt + full-page routes). If the inventory is empty, say so and stop — do not audit non-Livewire code.

## Step C: Fan out audit subagents

Dispatch read-only Explore-type subagents in **batches of 5-10 components** (count components, not files — one component = its class plus view plus any JS sibling), running batches in parallel. Each subagent prompt must include:

- the checklist. Preferred: give the subagent the ABSOLUTE PATH to `references/checklist.md` and require it to read the ENTIRE file (all LW checks) before auditing — subagents have Read access, and this keeps prompts small. Alternatively paste the full text verbatim. Either way: NEVER summarize, condense, or excerpt the checklist — condensation is how checks get silently dropped (it has caused real missed findings). Shrink the batch before you shrink the checklist;
- the detected Livewire version, and whether Volt is installed;
- the batch's file paths (class + view + any `.js`);
- the required findings shape: `file`, `line`, `checkId` (LW-NN), `severity` (Critical/High/Medium/Low/Info), `evidence` (a quoted line from the file), `exploit` (a concrete attacker scenario).

Tell each subagent:

- Read every file in the batch completely. Do not skim.
- Report only findings backed by a quotable line of evidence from the source.
- If uncertain whether something is a real finding, mark it `needs main-thread confirmation` — do NOT drop it and do NOT inflate it to a confident finding.

## Step D: Verification gate (MANDATORY)

Nothing unverified reaches the report. For every finding returned by a subagent:

- **Re-read** the flagged file at the flagged location in the main conversation.
- Confirm the quoted evidence actually exists at that location and the vulnerability logic holds.
- Discard false positives. Keep a running count of how many you discarded.

Then run the **app-level checks** in the main thread (these span the whole app, not one component, so subagents cannot own them):

- **LW-12** — morph map / model alias registration in service providers (search `providers/` for `enforceMorphMap`, `morphMap`, `Relation::morphMap`).
- **LW-14** — global upload rules in `config/livewire.php` (`temporary_file_upload` rules / max size / mimes).
- **LW-20** — `Livewire::addPersistentMiddleware(...)` registration in providers.
- **LW-21** — `Livewire::setUpdateRoute(...)` (custom update endpoint and its middleware).
- **LW-22** — middleware applied to full-page component routes (cross-reference the routes enumerated in Step B).

Confirm each against actual code before it becomes a finding.

## Step E: Optional dynamic verification

Only attempt this if the app demonstrably runs: `php artisan about` succeeds, or a dev server is already up. **Never run migrations or seeders.** If the app is not runnable, skip this step gracefully and say so in the report.

When runnable:

- Fetch a page that renders an affected component.
- Extract its `wire:snapshot` attribute from the HTML.
- Confirm exposure findings against the real serialized payload.
- Label each exposure finding `confirmed dynamically` or `statically inferred` accordingly.

See `references/snapshot-internals.md` for what the snapshot contains and how to read it.

## Step F: Report

Write the report to `livewire-audit-YYYY-MM-DD.md` (today's date) in the target project's root directory, using this exact template:

```
# Livewire Security Audit - <project> - <date>
Livewire version: <x> | Components audited: <n> | Findings: <n> (C/H/M/L/I: ...)

## Critical
### [LW-NN] <title> - <file>:<line>
Evidence: `<quoted code>`
Exploit: <concrete attacker steps>
Fix: <specific change, referencing the fixes.md pattern>

## High
### [LW-NN] <title> - <file>:<line>
Evidence: `<quoted code>`
Exploit: <concrete attacker steps>
Fix: <specific change, referencing the fixes.md pattern>

## Medium
...

## Low
...

## Info
...

## Clean components
<every component with zero findings, listed by name/path>

## Discarded during verification
<count> subagent findings did not survive main-thread verification.
```

Repeat the finding block per severity tier (Critical, High, Medium, Low, Info). Order findings within a tier by severity of impact.

Consolidation and counting rules — follow exactly:

- Merge checks into one block ONLY when they fire on the same code AND share the same fix (an unlocked untyped id = LW-02 + LW-23, one fix, one block). Checks with DIFFERENT fixes always get separate blocks, even on the same component or the same template (LW-16 bind-not-interpolate and LW-17 escape-output are two blocks; LW-10 cache: and LW-11 persist: on the same component are two blocks).
- A merged block's severity tier is the HIGHEST severity among its merged checks — never place a block below its worst check's tier. If you write "treat its true severity as Critical," the block belongs in Critical.
- Name every applicable check ID inside its block so each is traceable; nothing gets silently absorbed into another finding's prose. Before writing the report, walk LW-01..LW-27 once more and confirm each ID is either in a block, in an app-level note, or genuinely clean.
- The headline `Findings: <n>` is the number of `###` finding headers. Components audited counts components; full-page routes that point at counted components are noted, not double-counted.

Filenames containing the ⚡ prefix: write the literal character in the report. If your environment blocks emoji output, substitute the placeholder `[U+26A1]` and state that convention once at the top of the report.

**"Nothing found" is a valid result.** If zero findings survive verification, say so plainly and list the audited inventory as evidence of coverage. Never pad the report or invent findings to justify the run.

After writing the report, end your reply by stating its exact path (`livewire-audit-YYYY-MM-DD.md` in the project root) so the user knows where to find it.

## Fix mode (`--fix` only)

For each confirmed finding, in severity order (Critical first):

- Apply the matching pattern from `references/fixes.md`, smallest change first (the ordering note at the top of `fixes.md` explains the precedence).
- Re-run the relevant check against the changed file to confirm the fix holds.
- Leave the change uncommitted.
- Never weaken or remove validation.

If a fix requires a product decision (which authorization policy applies, what the valid value set for a property is, whether an action should be reachable at all), do NOT guess. Collect those into a list of questions and ask the user.

Finish with a summary table:

```
| Finding | Fix applied | Re-check result |
| LW-NN   | ...         | pass/needs-decision |
```

## Reminders

- Read-only mode writes exactly one file (the report). Fix mode additionally edits flagged source files. Nothing else.
- Always end your reply by stating the exact path of the report you wrote (`livewire-audit-YYYY-MM-DD.md` in the project root), so the user knows where to find it.
- Never run git commands at any point. Leave all work uncommitted.
