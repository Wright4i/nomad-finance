# Integer minor units

Money is an integer count of minor units plus an ISO currency code. Interest is basis points of a percent (`1999` is 19.99%). Exchange rates are decimal text, calculated with BCMath, and rounded half up to the target currency's minor units. SQLite never stores a float for an amount or a rate. The API sends integers and currency codes. The browser formats them.
