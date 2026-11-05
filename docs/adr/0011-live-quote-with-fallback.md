# A live quote with a fallback

Superseded by [0021](0021-one-currency-dollars.md).

Totals use a live quote for currencies on open accounts. The app asks the open ExchangeRate-API feed at most once a day for the signed-in user's base currency, and it stores a quote only for those accounts. A quote younger than a day replaces the fallback. The fallback is one editable number per currency, starting from `data/usd-rates.json` when the base is USD. A failed poll waits a day. A missing quote and a missing fallback leave that account out of the total.
