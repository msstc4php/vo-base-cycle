# Internal knowledge base

Engineer notes for `msstc4php/vo-base-cycle`. Team-readable, not user-facing documentation.

| File | What it answers |
|---|---|
| [architecture.md](architecture.md) | The single class, what it delegates to the core, the deptrac layer |
| [integrations.md](integrations.md) | How Cycle builds and chains typecast handlers; version floors and why |
| [conventions.md](conventions.md) | Judgement calls: claim rules, write-side validation, error wrapping, test layout |
| [known-issues.md](known-issues.md) | Cycle quirks that cost time: proxies vs `final`, silent rule drops, lazy handler build |

Related: the core's `.claude/docs/` and `docs/plans/2026-11-11-001-feat-framework-bridges-plan.md`.
