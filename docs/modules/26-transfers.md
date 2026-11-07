# Module 26 — Transfers

Status: now

A transfer moves two balances. Posting stays retired. A bill still does not move a balance by itself. See [a transfer moves balances](../adr/0027-a-transfer-moves-balances.md).

Pay a paid-in-full card. The amount starts at the card balance. The screen lists bills that are past, unpaid, and drawn from that card, with the bucket and the amount. Selected amounts cannot exceed the payment. Confirm marks those bills paid, lowers those buckets, and the rest of the payment is unassigned cash. If unassigned cash is short, the gap stays on screen and confirm stays off. Both balances decrease and do not go below zero.

Transfer between asset accounts. Confirm shows the source, the destination, and the amount in the account type colors. An in-budget asset moved to another in-budget asset changes the two balances and leaves available and the buckets alone. A transfer that leaves Include in Budget takes unassigned cash first. When that is short, confirm lists the buckets that would be reduced and waits for that list to be accepted. A locked bucket is not pulled below its bills. If the money still does not cover, confirm stays off.
