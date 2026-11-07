# Module 26 — Transfers

Status: now

A transfer moves two balances. Posting stays retired. A bill still does not move a balance by itself. See [a transfer moves balances](../adr/0027-a-transfer-moves-balances.md).

Pay a paid-in-full card. The card shows as an account card, balance first. The amount starts at that balance and carries a dollar mark. Past unpaid bills drawn from the card are buckets. Clicking a bucket assigns that bill's full amount. The card is a bucket of water. The water is red while the assigned bills are short of the balance, and green when they reach it. Selected amounts cannot exceed the payment. Confirm is a modal. It draws the money from the source account to the card in the account type colors, and names the bills that will be marked paid. Nothing is marked paid and neither balance moves until that modal is confirmed. The rest of the payment is unassigned cash. If unassigned cash is short, the gap stays on screen and confirm stays off. Both balances decrease and do not go below zero.

Transfer between asset accounts. Choosing an account shows that account the way the Accounts list does. The amount carries a dollar mark. Confirm is a modal. It draws the source, the destination, and the amount in the account type colors. Balances stay put until that modal is confirmed. An in-budget asset moved to another in-budget asset changes the two balances and leaves available and the buckets alone. A transfer that leaves Include in Budget takes unassigned cash first. When that is short, the modal lists the buckets that would be reduced and waits for that list to be accepted. A locked bucket is not pulled below its bills. If the money still does not cover, confirm stays off.
