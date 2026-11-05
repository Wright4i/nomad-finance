# A dated USD snapshot, not a live feed

Superseded by [0011](0011-live-quote-with-fallback.md). The snapshot in `data/usd-rates.json` remains the starting fallback.

Exchange rates stay numbers stored on the books. Setup copies `data/usd-rates.json` when the owner's base currency is USD, and Demo copies that file except EUR. The file is a snapshot of USD per 1 foreign unit, effective 2026-11-03, and it is expected to go stale. Editing a row is how a rate changes. A feed that fetches new rates is still not part of the app.
