# Module 3 — Exchange rates

Status: now

A rate converts an account balance into the signed-in user's base currency for net worth. The base is the user's current base currency. The screen does not ask for it. The screen is under Settings, at `/settings/rates`. `/rates` opens the same screen. It is not a top-level menu item. Demo and the owner do not see each other's rates.

| Field | Rule |
| --- | --- |
| Currency | Required ISO code, and not the base currency. The dropdown lists every code in `data/currencies.json` as `USD (US Dollar)` |
| Fallback | Greater than zero, up to 8 decimal places. Base units per 1 unit of the other currency. One row per user, base, and currency. Saving again replaces that fallback |

There is no effective date. The list shows the fallback and, for a currency on an open account, the live quote. A pulsing mark means that quote was fetched in the last day, whether this view used the cache or just polled. Totals use the live quote when it is younger than a day, and the fallback otherwise. A currency with neither is left in its own currency, out of the total, and named on the dashboard. Changing the base currency keeps old fallbacks and stops using them until fallbacks or a live quote exist for the new base.

The app asks `https://open.er-api.com/v6/latest/{base}` at most once a day per user and base currency. It stores a quote only for currencies on open accounts. A failed poll waits a day and leaves the fallback in place. `data/usd-rates.json` is the starting fallback for a USD base: USD per 1 unit, taken from that feed at 2026-11-04T00:02:31Z. Setup copies it when the new owner's base currency is USD. Demo copies the same set. KPW, SVC, and UYW have no quote in the file. The fallback will drift. Edit it under Settings.
