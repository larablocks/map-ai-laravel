# map-ai-laravel

A Laravel package that installs the **MAP** documentation scaffold into your project via a single Artisan command.

## What is MAP?

MAP is a structured set of markdown files that AI coding agents read at session start. It gives every session — with any tool — a reliable starting point: what the project is, how to run it, what's been decided and why, where to pick up from last time, and accumulated knowledge about what doesn't work.

Without it, each AI session starts from zero. With it, sessions start informed.

## What you gain

### The AI remembers what it learned

`docs/memory/` files accumulate project-specific knowledge across sessions — gotchas, database quirks, testing patterns, environment surprises. The AI reads these at startup and doesn't repeat mistakes it's already made on your project.

`docs/memory/shared.md` is committed to the repo, so every developer's AI sessions share the same team-level learnings.

### The AI knows your project from the first message

`AGENTS.md` tells every tool your stack, your test and build commands, and what files to read for what purpose. The AI doesn't ask what framework you're on or how to run tests — it already knows.

### Decisions don't get lost

`docs/ARCHITECTURE_HISTORY.md` is append-only. Every significant architectural decision is recorded with its alternatives and reasoning. When a decision gets revisited months later, the AI can explain why it was made the first time.

### Bugs stay visible

`docs/BUGS.md` is a live list the AI maintains automatically — appended on discovery, moved to `docs/BUGS_ARCHIVE.md` on fix. Known issues don't disappear from context at session end.

### Security and testing standards apply every session

`.claude/rules/security.md` and `.claude/rules/testing.md` load automatically each Claude Code session. The AI follows your coverage requirements and security practices without being reminded.

### One source of truth, four tools

MAP works with Claude Code, Gemini CLI, GitHub Copilot, and Cursor. Each tool gets its own entry point file (`CLAUDE.md`, `GEMINI.md`, `.cursor/rules/agents.mdc`, `.github/copilot-instructions.md`), but they all point at — or inline — the same `AGENTS.md`. Update one file, all tools stay in sync.

### Personal overrides without team noise

`CLAUDE.local.md` is gitignored. Each developer can add personal preferences for their machine without affecting anyone else's sessions.

---

## Documentation that writes itself

MAP defines a set of **write rules** — declarative triggers built into `AGENTS.md` that tell the AI exactly what to write, where, and when. The AI follows them immediately without being asked, as a side effect of normal work:

| When the AI discovers… | It writes to… |
|---|---|
| A bug (from any source) | `docs/BUGS.md` — appended immediately |
| A bug is fixed and verified | Entry moved to `docs/BUGS_ARCHIVE.md` |
| A hard-to-reverse architectural decision | `docs/ARCHITECTURE_HISTORY.md` — decision, alternatives considered, and reasoning |
| A new project-specific pattern | `docs/CODE_PATTERNS.md` — checked first to avoid duplication |
| A new domain term or abbreviation | `docs/GLOSSARY.md` |
| Surprising behaviour (framework, DB, tests, environment) | `docs/memory/[topic].md` — routed by subject |
| Time wasted on a mistake | `docs/memory/gotchas.md` — capped at ~750 tokens, least-actionable removed when full |
| A schema change | `docs/SCHEMA.md` — updated immediately |
| An architecture change | `docs/ARCHITECTURE.md` — updated to reflect current state |
| Tests added or coverage run | `docs/TESTING_COVERAGE.md` — from actual output, never estimated |

Writes happen immediately — not deferred to session end, not optional. When the AI finds a bug mid-task, it appends to `BUGS.md` before continuing. When it makes an architectural call, it records the decision and reasoning before moving on. The priority order is fixed: `BUGS.md` first, `ARCHITECTURE_HISTORY.md` second, everything else after.

At session end the AI updates `docs/STATUS.md` with current project health (moving older progress entries to `docs/STATUS_ARCHIVE.md`, since `STATUS.md` loads every session) and routes everything learned during the session to the appropriate `docs/memory/` file.

The result is documentation that reflects what is actually true about the project right now — maintained continuously as a side effect of development work, not as a separate task someone needs to remember to do.

---

## Designed for lean context

Every working file in MAP has a size ceiling enforced by the AI's own write rules (the deliberate exceptions are the append-only logs — `ARCHITECTURE_HISTORY.md`, `BUGS_ARCHIVE.md`, `STATUS_ARCHIVE.md`, and `METRICS_HISTORY.md` — which have no size limit and are never summarised). `AGENTS.md` stays under 3,000 tokens (estimated as bytes ÷ 4) — measured in tokens, not lines, because it loads every session and one long line costs as much as many short ones. `docs/STATUS.md` caps at ~5,000 tokens (older progress entries move to `docs/STATUS_ARCHIVE.md`). `docs/memory/gotchas.md` caps at ~750 tokens and `docs/memory/shared.md` at ~1,500 (both load every session). Other memory topic files cap at ~2,500 tokens. In Claude Code, `.claude/hooks/map-token-check.sh` enforces all of these: at session start, and immediately after any edit that pushes a capped file over its cap. When a file fills up, the AI summarises or removes before adding — so files stay dense and high-signal rather than growing without bound.

Beyond size caps, the structure itself controls what gets loaded:

**Selective loading, not full context at startup.** `AGENTS.md`'s "Load when relevant" section tells the AI which files to read for which tasks. A session fixing a bug doesn't load `ARCHITECTURE.md` or `SCHEMA.md`. A session touching the database doesn't load `DOCKER.md`. Files are pulled on demand. That's why those lines use plain paths: an `@docs/...` import in Claude Code or Gemini CLI loads the file at session start no matter what the rule says. Only the session start ritual's files (`STATUS.md`, `MEMORY.md`, `BUGS.md`) and `CLAUDE.local.md` are `@`-imported.

**Index before content.** `MEMORY.md` is a one-page index — a table of topic files and entry counts. The AI reads it first to know what knowledge exists, then loads only the topic file relevant to the current task. `docs/memory/database.md` is never loaded during a UI fix.

**History separated from current state.** `ARCHITECTURE_HISTORY.md` has no size limit and is never summarised — it grows for the life of the project so no decision's reasoning is ever lost; `ARCHITECTURE.md` stays a concise snapshot of current structure. You pay for historical decision tokens only when a decision is actively being revisited.

**Write-on-discovery keeps future context accurate.** The AI writes to docs immediately when it finds something rather than waiting until session end. Accurate docs mean future sessions don't waste tokens working from stale context or asking clarifying questions they shouldn't need to ask.

The result: sessions start faster because the AI isn't loading irrelevant context, and the token cost per session stays proportional to the actual scope of the work.

---

## Installation

```bash
composer require larablocks/map-ai-laravel
```

## Usage

```bash
php artisan map:install
```

This copies the MAP scaffold into your project root, merges the required entries into `.gitignore` and `.gitattributes`, and registers MAP's markdown merge driver in `.git/config`. Files that already exist are skipped unless you pass `--force`.

```bash
php artisan map:install --force
```

## What gets installed

| File | Purpose |
|------|---------|
| `AGENTS.md` | Primary AI entry point — project name, stack, session rituals, write rules |
| `CLAUDE.md` | Claude Code entry point — imports AGENTS.md, adds Claude-specific config |
| `GEMINI.md` | Gemini CLI entry point |
| `.claude/rules/security.md` | Security rules — auto-loaded every Claude Code session |
| `.claude/rules/testing.md` | Coverage and test quality rules — auto-loaded every Claude Code session |
| `.github/copilot-instructions.md` | Copilot entry point — AGENTS.md content inlined |
| `.cursor/rules/agents.mdc` | Cursor entry point — imports AGENTS.md |
| `.claude/hooks/map-first-run-check.sh` | Claude Code `SessionStart` hook — detects an un-initialized scaffold, see below |
| `.claude/hooks/map-token-check.sh` | Claude Code `SessionStart`/`PostToolUse` hook — enforces the AGENTS.md, STATUS.md and memory-file token caps |
| `.claude/settings.json` | Wires the hooks above (skipped, not overwritten, if you already have one — see below) |
| `.map/merge.sh` | Git merge driver for MAP docs — rules first, then Claude for what's left; always stops for review when Claude was needed |
| `.claude/skills/map-resolve/SKILL.md` | Claude Code skill to resolve and review MAP doc merge conflicts with you |
| `.claude/skills/example-skill/SKILL.example.md` | Template for your own Claude Code skill — copy the folder and rename the file to `SKILL.md` |
| `docs/STATUS.md` | Project health: build, tests, blockers, milestones |
| `docs/STATUS_ARCHIVE.md` | Older STATUS.md progress entries — append-only, not loaded each session |
| `docs/METRICS_HISTORY.md` | Dated metrics log, one entry per session end — append-only |
| `docs/BUGS.md` | Open bugs (AI-maintained) |
| `docs/BUGS_ARCHIVE.md` | Fixed bugs — append-only |
| `docs/ARCHITECTURE.md` | Current system structure (AI-maintained) |
| `docs/ARCHITECTURE_HISTORY.md` | Architectural decision log — append-only |
| `docs/CODE_PATTERNS.md` | Project-specific patterns (AI-maintained) |
| `docs/SCHEMA.md` | Database schema and service contracts (AI-maintained) |
| `docs/GLOSSARY.md` | Domain terms and abbreviations |
| `docs/COMMANDS.md` | Custom project commands, categorized (AI-maintained) |
| `docs/COMPLIANCE.md` | Regulatory/compliance obligations (Claude proposes, you approve) |
| `docs/DESIGN.md` | UI/frontend conventions (Claude proposes, you approve; delete if no UI) |
| `docs/DOCKER.md` | Container and environment reference |
| `docs/FEATURE_FLAGS.md` | Feature flag registry (AI-maintained) |
| `docs/SETUP.md` | Local dev setup for new developers |
| `docs/TESTING_COVERAGE.md` | Coverage tracking — updated from actual output |
| `docs/MEMORY.example.md` | Memory index template — `MEMORY.md` is created from it on install (gitignored) |
| `docs/memory/*.example.md` | Per-topic memory templates: framework, database, testing, environment, performance, agents, shared |
| `docs/agents/agent.example.md` | Template for documenting a specific agent |
| `docs/api/api.example.md` | Template for documenting an API |
| `docs/integrations/integration.example.md` | Template for documenting an integration |
| `docs/architecture/architecture.example.md` | Template for documenting a subsystem or component |
| `docs/qa/qa.example.md` | Template for a completed-feature QA record — created only when you ask for one |

## First-run detection

`map:install` only fills in the mechanical parts of `AGENTS.md` (project name, stack, commands, date) — it never invokes an AI. The deeper scaffold (`docs/STATUS.md`'s milestone, `docs/ARCHITECTURE.md`'s system overview, etc.) is left for whatever AI tool actually opens the project first.

`AGENTS.md`'s Session start ritual (item 0) tells every supported tool — Claude Code, Gemini CLI, Copilot, Cursor — to check for those leftover placeholders and complete first-run init before doing anything else, including before responding to the developer's first message. That instruction alone works identically across all four tools since it's just markdown loaded into context.

For Claude Code specifically, `.claude/hooks/map-first-run-check.sh` (wired via `.claude/settings.json`'s `SessionStart` hook) makes the same check deterministic: it greps for the placeholder markers and, if found, injects a directive into context so the check can't be silently skipped. It goes quiet on its own once real content replaces the placeholders — there's no separate "initialized" flag to maintain. If your project already has a `.claude/settings.json`, the installer won't touch it — wire the hook in yourself from `vendor/larablocks/map-ai/stubs/.claude/settings.json`.

## After installation

The installer auto-detects your project name, stack, and common commands from `composer.json`, `package.json`, and `.env.example`. Review `AGENTS.md` after install — anything it couldn't detect will still show a `[...]` placeholder for you to fill in manually.

1. Review `AGENTS.md` — verify auto-detected values on lines 2–3 and the Commands section; fill in any remaining `[...]` placeholders
2. Nothing else to copy: `map:install` already created your personal gitignored files (`docs/MEMORY.md`, `docs/memory/*.md`) from their templates, and on a teammate's fresh clone the first AI session creates any that are missing

## Merging MAP docs

`.gitattributes` routes every file an AI agent writes to (all of `docs/`, plus `AGENTS.md`, `CLAUDE.md`, `GEMINI.md`, `.github/copilot-instructions.md` and `.claude/rules/`) to `.map/merge.sh`. The driver runs git's normal merge first. On conflict, deterministic rules resolve the usual cases: entries both branches appended, table rows, list items, `Last updated`, and duplicate `BUG-N`s. Sections of values re-measured every session (STATUS health and metrics, TESTING_COVERAGE) take the newer branch's numbers instead of stopping the merge. Whatever is left goes to Claude (`claude -p`), and each resolution is checked before it's accepted. A `BUG-N` clash git never ran the driver on (one branch changed only `BUGS.md`, the other only `BUGS_ARCHIVE.md`) is reported afterwards by the SessionStart hook (and `bash .map/merge.sh --check-bugs`); `bash .map/merge.sh --fix-bugs` renumbers it.

When Claude was needed, the merge always stops before committing so you can review `git diff` and `git add`. The `map-resolve` skill does the same review with you in a Claude Code session. The driver registration lives in `.git/config`, which isn't cloned: `map:install` registers it, and so does the SessionStart hook on each clone's first Claude Code session. See the [map-ai README](https://github.com/larablocks/map-ai#merging-map-docs) for the full rules and settings.

## .gitignore entries added

The installer merges these entries into your `.gitignore` (skipped if already present):

```gitignore
# MAP — developer-specific files (do not commit)
.claude/settings.local.json
CLAUDE.local.md
docs/MEMORY.md
docs/memory/*.md
!docs/memory/*.example.md
!docs/memory/shared.md
```

## Development

```bash
composer install
composer test
composer analyse
composer format
```

## License

MIT
