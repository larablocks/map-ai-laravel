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
| Time wasted on a mistake | `docs/memory/gotchas.md` — capped at 10, least-actionable removed when full |
| A schema change | `docs/SCHEMA.md` — updated immediately |
| An architecture change | `docs/ARCHITECTURE.md` — updated to reflect current state |
| Tests added or coverage run | `docs/TESTING_COVERAGE.md` — from actual output, never estimated |

Writes happen immediately — not deferred to session end, not optional. When the AI finds a bug mid-task, it appends to `BUGS.md` before continuing. When it makes an architectural call, it records the decision and reasoning before moving on. The priority order is fixed: `BUGS.md` first, `ARCHITECTURE_HISTORY.md` second, everything else after.

At session end the AI updates `docs/STATUS.md` with current project health and routes everything learned during the session to the appropriate `docs/memory/` file.

The result is documentation that reflects what is actually true about the project right now — maintained continuously as a side effect of development work, not as a separate task someone needs to remember to do.

---

## Designed for lean context

Every file in MAP has a size ceiling enforced by the AI's own write rules. `AGENTS.md` stays under 100 lines. `docs/memory/gotchas.md` caps at 10 entries. Memory topic files cap at 50. When a file fills up, the AI summarises or removes before adding — so files stay dense and high-signal rather than growing without bound.

Beyond size caps, the structure itself controls what gets loaded:

**Selective loading, not full context at startup.** `AGENTS.md`'s "Load when relevant" section tells the AI which files to read for which tasks. A session fixing a bug doesn't load `ARCHITECTURE.md` or `SCHEMA.md`. A session touching the database doesn't load `DOCKER.md`. Files are pulled on demand.

**Index before content.** `MEMORY.md` is a one-page index — a table of topic files and entry counts. The AI reads it first to know what knowledge exists, then loads only the topic file relevant to the current task. `docs/memory/database.md` is never loaded during a UI fix.

**History separated from current state.** `ARCHITECTURE_HISTORY.md` grows large over time; `ARCHITECTURE.md` stays a concise snapshot of current structure. You pay for historical decision tokens only when a decision is actively being revisited.

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

This copies the MAP scaffold into your project root and merges the required entries into `.gitignore`. Files that already exist are skipped unless you pass `--force`.

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
| `.claude/settings.json` | Wires the hook above (skipped, not overwritten, if you already have one — see below) |
| `docs/STATUS.md` | Project health: build, tests, blockers, milestones |
| `docs/BUGS.md` | Open bugs (AI-maintained) |
| `docs/BUGS_ARCHIVE.md` | Fixed bugs — append-only |
| `docs/ARCHITECTURE.md` | Current system structure (AI-maintained) |
| `docs/ARCHITECTURE_HISTORY.md` | Architectural decision log — append-only |
| `docs/CODE_PATTERNS.md` | Project-specific patterns (AI-maintained) |
| `docs/SCHEMA.md` | Database schema and service contracts (AI-maintained) |
| `docs/GLOSSARY.md` | Domain terms and abbreviations |
| `docs/DOCKER.md` | Container and environment reference |
| `docs/FEATURE_FLAGS.md` | Feature flag registry (AI-maintained) |
| `docs/SETUP.md` | Local dev setup for new developers |
| `docs/TESTING_COVERAGE.md` | Coverage tracking — updated from actual output |
| `docs/MEMORY.example.md` | Memory index template (copy to `MEMORY.md` — gitignored) |
| `docs/memory/*.example.md` | Per-topic memory templates: framework, database, testing, environment, performance, agents, shared |
| `docs/agents/agent.example.md` | Template for documenting a specific agent |
| `docs/api/api.example.md` | Template for documenting an API |
| `docs/integrations/integration.example.md` | Template for documenting an integration |
| `docs/architecture/architecture.example.md` | Template for documenting a subsystem or component |
| `docs/qa/qa.example.md` | Template for a completed-feature QA record |

## First-run detection

`map:install` only fills in the mechanical parts of `AGENTS.md` (project name, stack, commands, date) — it never invokes an AI. The deeper scaffold (`docs/STATUS.md`'s milestone, `docs/ARCHITECTURE.md`'s system overview, etc.) is left for whatever AI tool actually opens the project first.

`AGENTS.md`'s Session start ritual (item 0) tells every supported tool — Claude Code, Gemini CLI, Copilot, Cursor — to check for those leftover placeholders and complete first-run init before doing anything else, including before responding to the developer's first message. That instruction alone works identically across all four tools since it's just markdown loaded into context.

For Claude Code specifically, `.claude/hooks/map-first-run-check.sh` (wired via `.claude/settings.json`'s `SessionStart` hook) makes the same check deterministic: it greps for the placeholder markers and, if found, injects a directive into context so the check can't be silently skipped. It goes quiet on its own once real content replaces the placeholders — there's no separate "initialized" flag to maintain. If your project already has a `.claude/settings.json`, the installer won't touch it — wire the hook in yourself from `vendor/larablocks/map-ai/stubs/.claude/settings.json`.

## After installation

The installer auto-detects your project name, stack, and common commands from `composer.json`, `package.json`, and `.env.example`. Review `AGENTS.md` after install — anything it couldn't detect will still show a `[...]` placeholder for you to fill in manually.

1. Review `AGENTS.md` — verify auto-detected values on lines 2–3 and the Commands section; fill in any remaining `[...]` placeholders
2. Each developer runs the `cp` commands in `docs/SETUP.md` step 3 to initialize their personal gitignored files

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
