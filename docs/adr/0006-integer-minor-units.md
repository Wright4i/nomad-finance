# Integer minor units

Superseded in part by [0021](0021-one-currency-dollars.md). Amounts are integer cents. Exchange rates are not used.

Money is an integer count of minor units. Interest is basis points of a percent (`1999` is 19.99%). SQLite never stores a float for an amount. The API sends integers. The browser formats them with a dollar sign.
