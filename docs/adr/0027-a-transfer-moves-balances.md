# A transfer moves balances

Supersedes [0022](0022-balance-is-only-what-you-type.md).

A transfer is the one action that writes two account balances without typing either one. Posting stays retired. A bill still does not move a balance by itself. Mark paid on the ordinary bill path leaves the balance as it was. Paying a paid-in-full card from the transfer screen is the path that both marks the selected bills paid and moves the money: the source account and the card each decrease by the payment, and neither balance goes below zero.

An in-budget asset moved to another in-budget asset changes those two balances and leaves available-to-assign and the buckets alone. A transfer that leaves Include in Budget takes unassigned cash first. Bucket money is taken only for the bills selected when paying a card, or when an asset transfer leaves the pool and unassigned cash is short, and only after the person accepts that list. A locked bucket is not pulled below its bills.
