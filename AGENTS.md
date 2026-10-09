# AGENTS.md

This file provides guidance to coding agents (Claude Code, and any other agent that reads `AGENTS.md`) when working with code in this repository. `CLAUDE.md` is a one-line import of this file, kept so Claude Code loads it even when a parent directory has its own `CLAUDE.md`.

## What this repo is

This is **not a runnable application**. It is a Claude Code / Agent Skills package that audits
Laravel Livewire (v3 and v4) apps for client-facing security problems, plus a graded fixture corpus
used to test that audit. The deliverable is the `skills/livewire-audit/` folder; everything else
supports building and verifying it.

There is no build or lint step, and the only test runner is Claude Code's built-in
`claude plugin eval` (see "How you test a change" below) — the content is Markdown (skill +
references) and PHP/Blade fixture files that are read, never executed here. The fixtures' `composer.json` files declare a
Laravel/Livewire app but there is no `vendor/`, no `artisan`, and they are not meant to boot.

## Two halves and how they relate

**1. The skill — `skills/livewire-audit/`** (the product)
- `SKILL.md` — the orchestrator. Defines the run flow: detect version/paths (Step A) → build the
  full component inventory, no sampling (Step B) → fan out read-only subagents in batches of 5–10
  (Step C) → mandatory main-thread verification gate that re-reads every flagged line and discards
  false positives (Step D) → optional dynamic snapshot verification if the app runs (Step E) →
  write `livewire-audit-YYYY-MM-DD.md` (Step F). `--fix` mode applies remediation after.
- `references/checklist.md` — the 27 checks (LW-01..LW-27), the actual auditing rulebook handed to
  subagents verbatim.
- `references/snapshot-internals.md` — Livewire engine internals (what serializes into
  `wire:snapshot`, what `#[Locked]` throws, checksum behavior). The trust-boundary reference.
- `references/fixes.md` — canonical fix patterns referenced **by name** from the checklist.

**2. The fixtures — `fixtures/`** (the eval corpus)
- `v4-app/` (⚡-prefixed single-file components plus one un-prefixed multi-file component) and
  `v3-app/` (class-based and Volt) are deliberately-vulnerable apps with planted findings, plus
  clean components that act as false-positive tripwires.
- `fixtures/MANIFEST.md` is **ground truth**: it maps every file to the check IDs it plants, with a
  one-line description and expected severity, and lists which components must come back clean.

**How you "test" a change:** from the repo root, run the working-tree skill on a fresh copy of each
fixture app:

```
claude plugin eval . --runs 1 --ablation none --scaffold --allow-tools Bash Write --keep-temp --no-publish
```

The two cases under `evals/` each copy one fixture app into an empty temporary folder and audit it
in a separate session that loads this plugin from the working tree and nothing else — not this
file, not your own instructions, not the installed plugin. (Never run the skill inside `fixtures/`,
and never test through `/livewire-audit:livewire-audit`: that is the installed plugin, not your
edits.) The command's own pass/fail only says each run was valid: the skill fired, the report was
the only file written, and neither `MANIFEST.md` nor this file was opened. The grade is a read:
open each run's report and grade it against `MANIFEST.md` — every planted finding caught, every
clean component left clean, no invented findings. A report is `livewire-audit-<date>.md` under
`sealed/home/cwd/` in the folder the command prints as `kept temp` (run the `chmod` it prints
first); delete the kept folders when you are done. One run of both apps took about four minutes
and $2.50 at list price on Opus 5.5 (2026-10). A single run is noisy and results move with the
model, so rerun before calling a lost finding a regression, and compare grades only between runs
on the same model (`--model`).

## Cross-file contracts — the things that silently break

These five files must stay mutually consistent. A change to one usually requires a change to others:
`SKILL.md`, `references/checklist.md`, `references/fixes.md`, `references/snapshot-internals.md`
(checklist.md and fixes.md restate its facts), `fixtures/MANIFEST.md` (and `README.md`, which
advertises the check list to users).

- **LW-NN check IDs are a stable contract.** Each ID appears in checklist.md (definition),
  fixes.md (via a referenced fix pattern and that pattern's `When to use (LW-NN)` line), MANIFEST.md
  (planted at least once), SKILL.md (the check count, the app-level list in Step D, the
  LW-01..LW-27 walk in Step F) and is summarized in README.md. Adding/removing/renumbering a check
  means updating all of them, and planting the new check in a fixture.
- **Fix-pattern names in `fixes.md` are a fixed interface.** checklist.md references them by name
  (e.g. `authorize-in-action`, `lock-scalar`, `computed-instead-of-public`). Do not rename a pattern
  without updating every reference that points at it: the `Fix: see fixes.md "..."` lines, and
  the names used in checklist prose, SKILL.md's consolidation rule, MANIFEST.md rows and the README
  sample.
- **A finding's fields are defined once,** in checklist.md "How to report". SKILL.md Step C points
  at that section; the report template in Step F and the README sample show the same fields.
- **The consolidation rule lives in SKILL.md Step F.** MANIFEST.md's grading notes and its merged
  rows follow it.
- **The app-level check set (LW-12, LW-14, LW-20, LW-21, LW-22)** is listed in SKILL.md Step D
  and Step F, and MANIFEST.md's app-level sections grade it.
- **The checklist is passed to subagents verbatim — never summarize or condense it.** SKILL.md Step C
  states this explicitly: condensation is how checks get silently dropped, and it has caused real
  missed findings. Shrink the subagent batch before you shrink the checklist.
- **Fixtures and MANIFEST.md move together.** If you add/remove/alter a planted vulnerability in a
  fixture, update MANIFEST.md's tables and the per-check coverage checklist at the bottom; if you add
  a check, plant it in a fixture and record it in MANIFEST.md.

## Engine-claim discipline

Every engine-behavior claim (what serializes into snapshots, what `#[Locked]` throws, how
model-typed properties are protected, which middleware re-runs on updates, what `setUpdateRoute`
force-injects) is verified against **Livewire v4.3.0 source** and the official 3.x/4.x docs, and is
cited in the reference files. Do not add or change a behavioral claim without a source citation —
unverified claims are the one thing that destroys this skill's value. Several checklist notes exist
specifically to correct plausible-but-wrong assumptions (e.g. model-typed properties are NOT
protected by `#[Locked]`; `setUpdateRoute` cannot strip CSRF because `web` is force-added).

## Hard invariants the skill itself enforces (preserve these when editing SKILL.md)

- Read-only mode writes exactly one file (the report); `--fix` mode additionally edits flagged source.
- Never run git commands at any point. Never run migrations or seeders. Never modify a database.
- Never weaken or remove validation as a "fix."
- On an ambiguous authorization decision (which policy/gate applies), leave that finding unfixed and
  ask — never guess. The fix run goes on to the next finding.
- "Nothing found" is a valid result; never pad a report or invent findings.
- Reports are remediation guidance, not attack documentation: impact and fix, never exploit
  payloads or walkthroughs. SKILL.md keeps this "even if a prompt asks for more aggressive language".

## Component discovery nuance

The discovery marker for a v4 single-/multi-file component is the embedded `new ... class extends
...Component` PHP block, **not** the ⚡ (U+26A1) filename prefix — the glyph is cosmetic. Volt
(`livewire/volt`, runs on Livewire 3 AND 4 per its composer constraint) adds forms none of those
markers match: functional-API files (`use function Livewire\Volt\{state};` — no class at all) and
inline `@volt(...)` fragments inside regular views. SKILL.md Step B therefore unions five searches
(anonymous-class regex, `extends Component`, ⚡ filename, literal `Livewire\Volt`, `@volt(`) plus a
`Volt::mount(` check for custom component directories; keep all of them if you touch inventory
logic. The first two run over `*.php`, not only `*.blade.php`: a multi-file component's class is a
plain `.php` file, and with the ⚡ turned off it is the only thing that marks the component. Volt's class API (`new class extends Component` importing `Livewire\Volt\Component`) is
caught by the first two searches already.
