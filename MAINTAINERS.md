# Maintainers

- Joseph Wright (@Wright4i)

---

# Project Guidelines

## Core Principles

- **Typed balances**: Account balances are stored as non-negative integer cents. Balances change only when edited directly, when a transfer moves money between accounts, or when a new expense log is saved. Marking a regular bill paid records the occurrence and rolls the due date forward without modifying account balances.
- **Integer minor units**: All currency amounts are stored and calculated in cents using integer arithmetic or `bcmath` where precision matters. Avoid floating-point math for money.
- **Single currency**: Books operate in USD.
- **Owner and Demo separation**: Development, manual testing, and automated tests should always use the `Demo` account or an isolated temporary SQLite database. Never read, modify, or reset the owner's data in `data/finance.sqlite` unless explicitly working on a personal database backup.

## Architecture

- Backend code lives under `src/` (PSR-4 namespace `App\`) with Slim routes in `src/Routes.php`, dependency wiring in `config/container.php`, and SQLite schema migrations in `db/migrations/`.
- Frontend code lives under `frontend/src/` using Svelte 5 runes, TypeScript, and Vite. Compiled assets output to `public/build/`.
- Keep domain logic in focused PHP service and value classes under `src/` and pure TypeScript helpers under `frontend/src/lib/` so rules can be unit tested directly.

## Coding Style

- PHP targets 8.3+ with `declare(strict_types=1)` and strict typing.
- Frontend TypeScript and Svelte code omits trailing semicolons and favors small, pure helper modules alongside `.test.ts` unit tests.
- Keep comments brief and rare. Only comment non-obvious constraints, browser quirks, or invariants that the code itself cannot express. Avoid restating function signatures or obvious control flow.
- Use plain ASCII punctuation in prose, UI copy, and comments. Avoid em-dashes, en-dashes, semicolons in prose, and unicode arrows.

## Demo Seed Coverage

The sample dataset in `src/Demo/DemoLibrary.php` should cover every account type, account side, bill type, recurrence, status, pay cycle length, vehicle kind, and trip travel mode supported by the app. When adding a new variant or feature, add a matching seeded example in `DemoLibrary.php` and verify `composer demo:reset` restores it cleanly.

## Testing

A change is ready to merge when:

1. Unit tests under `tests/` cover any new or modified business rules.
2. Browser tests under `tests/Browser/` cover any visible UI changes.
3. Frontend changes are rebuilt with `npm run build --prefix frontend` before running browser tests.
4. Both `composer test` and `composer test:browser` pass cleanly.
