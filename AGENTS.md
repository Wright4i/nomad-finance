# Nomad finance

Personal finance register: typed account balances and bill reminders. Vocabulary lives in `GLOSSARY.md`. Hard choices live in `docs/adr/`. Screen and field rules live in `docs/modules/`. Build the module marked next in `docs/modules/README.md`, and leave later and blocked modules unbuilt.

## Tests

A change is finished when a unit test covers the rule and, if the screen changed, a Selenium test covers what a person sees, and both `composer test` and `composer test:browser` have been run. See `docs/agents/testing.md`.

## Owner books

Demo is the user for tests and agent sessions. The owner is the user created at setup. Sign in as Demo. `composer demo:reset` restores Demo's sample accounts, bills, and categories. Read or change the owner's accounts, bills, settings, or password only when the owner explicitly permits that work in the conversation.

## Demo seed

A module is unfinished until the demo seed contains every type, side, recurrence, and status that module can store, under a generic name such as Checking 1 or Recurring Monthly Bill. `composer demo:reset` restores that set. See `docs/agents/testing.md`.

## Agent skills

### Issue tracker

Issues are markdown files under `.scratch/<feature>/`. See `docs/agents/issue-tracker.md`.

### Triage labels

Status lines use `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, and `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context. See `docs/agents/domain.md`.
