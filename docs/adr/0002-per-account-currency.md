# One currency per account

The dated-rate sentence is superseded by [0011](0011-live-quote-with-fallback.md).

Each account has one ISO currency. A bill must name the account it is drawn from, and the bill amount is stored in that currency. An optional vendor amount can record the biller's currency. Net worth converts account balances into the base currency with rates you type (base units per 1 foreign unit, dated). The latest rate on or before today for the current base currency wins. A missing rate leaves that account in its own currency, out of the total, and named on the dashboard. Changing the base currency keeps old rates and stops using them until rates exist for the new base.
