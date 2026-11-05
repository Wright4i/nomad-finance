# Typed balances

Accounts store a balance you type. Marking a bill paid records the date and rolls the next due date. It leaves the balance unchanged. A posting uses the account's currency. [A confirmed posting moves the stored balance](0016-posting-moves-balance.md). Typing a new balance still replaces the stored figure and does not replay older postings. The arrival date of a trip does not move a balance on its own.
