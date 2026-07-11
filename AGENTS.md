# HP Dev Config - agent entry point (read this first)

You are in HP-Dev-Config, part of the HolisticPeople platform. The source of truth is
`HolisticPeople/HP-Codex-Skills@dev` (HP-Roadmap v5.0).

`hp` = `python3 <skills-home>/hp-codex-machine-setup/scripts/hp.py`
(`<skills-home>` is `~/.codex/skills` on Codex or `~/.claude/skills` on Claude Code).

## START (first steps in a new thread)
  1. `hp sync`                 # check your skills are current + install for this runtime
  2. `hp status`               # what THIS repo owns / consumes + its open-loop docs
  3. `hp comply`               # HP-Roadmap v5.0-compliant? if it reports gaps, run
                               #   `hp onboard-plugin --clone . --apply` to fix them in ONE pass
                               #   (entry file + seeded hp-contract.json); PROPOSE to your user,
                               #   confirm owns/consumes, then COMMIT (self-heals on first touch)
  4. `hp roadmap "<topic>"`    # before any cross-plugin work: read the canonical plan

## YOUR LANE  (owner lane: `dev-environment`)
OWNS:
  - staging environment recovery after Kinsta prod-to-staging pushes (WooCommerce MCP credentials + MU plugin restore, Codex runner recovery, Inspector worker recovery)
  - named plugin enable/disable configurations applied on demand (Tools > Dev Configuration, dev_config_plugin_settings option)
  - dev-only site toggles: search-engine noindex, debug.log cleanup, FluentSMTP email-simulation mode
MUST NOT OWN:
  - cross-plugin source mutation without registered contract
  - production site mutation (all actions are environment-gated to safe dev/staging)
CONSUMES (advisory - read other plugins only via their public, versioned,
fail-soft contracts; never their internals; no hard coupling):
  - FluentSMTP settings option (fluentmail-settings), fail-soft when absent
  - HP-Core staging runner settings (Codex runner recovery)
  - HP-Inspector worker cron surface (recovery pass)

## READ FIRST
  - HP-Codex-Skills/skills/hp-roadmap/references/roadmaps/hp-dev-phase-current-state-index-2026-06.md

## WHEN YOU FINISH SOMETHING DURABLE
  - Land it as ONE commit: the central plan doc + this repo's pointer (AGENTS.md, docs/plan/parking-lot.md).
  - Keep repo parking lots classified with HP-Roadmap taxonomy: `active_next`,
    `future_candidate`, `idea_parking`, `blocked_dependency`,
    `implemented_archive`, `superseded_archive`, or `rejected_archive`.
  - Owning lanes close their own PRs/branches - HP-Roadmap does not close them for you.

## THE WHOLE MAP (every plugin + what it exposes)
  `HP-Codex-Skills/skills/hp-roadmap/references/hp-plugin-architecture-catalog.md`
  What changed / is it better:  `hp whatsnew`  |  `HP-Codex-Skills/MIGRATION-2026-06.md`

## HOW THE COMPLIANCE + MANIFEST SYSTEM WORKS (architecture, flows, onboarding)
  `HP-Codex-Skills/skills/hp-roadmap/references/hp-roadmap-v5-system-guide.md`

<!-- Generated from the HP Plugin Architecture Registry. Edit the registry entry,
     then regenerate with skills/hp-roadmap/scripts/generate_entry_file.py. -->
