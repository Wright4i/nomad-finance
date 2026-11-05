# Module 1 — Accounts

Create and edit an account for the signed-in user. Demo and the owner do not see each other's accounts.

| Field | Rule |
| --- | --- |
| Name | Required, trimmed, 1–80 characters |
| Type | checking, savings, cash, credit, loan, fuel, other |
| Side | Implied, except other, which asks asset or liability |
| Currency | Not asked. The account is stored as dollars |
| Balance | Integer cents, zero or greater |
| Interest | Optional basis points, 0–100000 |
| Minimum due | Optional, credit and loan only, in cents |
| Due day | Optional, credit and loan only, 1–31 |
| Credit limit | Optional, credit only |
| Institution | Optional, up to 120 characters |
| Notes | Optional, up to 2000 characters |

Liability-only fields are cleared when the type does not use them. The list is square cards, several on a row. It hides archived accounts until you ask for them, assets first, then liabilities, then name. Archive is refused while an active or paused bill draws from the account. Unarchive puts the account back on the list. A minimum due stays on the account and does not create a bill.

The balance stays a number you type. [Tracking accounts](19-tracking.md) and a typed value for a house or vehicle come later. The credit picture is dropped. Interest, minimum due, due day, and credit limit stay on this screen.
