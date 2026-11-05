# One currency: dollars

Supersedes [0002](0002-per-account-currency.md), [0010](0010-usd-rate-snapshot.md), and [0011](0011-live-quote-with-fallback.md).

Books are dollars. Amounts stay integer cents. The screen shows a dollar sign and does not say USD. Accounts and bills do not ask for a currency. Net worth is assets minus liabilities, and nothing is left out for a missing rate. Stored minor units are not converted when the old currency columns are set to USD. A checked-in rate file is not a runtime source.
