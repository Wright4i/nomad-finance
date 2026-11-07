# Module 1 — Accounts

Create and edit an account for the signed-in user. Demo and the owner do not see each other's accounts.

| Field | Rule |
| --- | --- |
| Name | Required, trimmed, 1–80 characters |
| Type | checking, savings, cash, credit, loan, fuel, other |
| Side | Implied, except other, which asks asset or liability |
| Include in Budget | Checkbox, assets only. On counts the balance in available to assign. A new checking, cash, or other asset starts on. A new savings account starts off. A liability has no checkbox and stays out |
| Currency | Not asked. The account is stored as dollars |
| Balance | Integer cents, zero or greater |
| Interest | Optional basis points, 0–100000. 500 is 5 percent a year. Credit and loan use it for interest lost. Savings uses it for interest gained |
| Minimum due | Optional, credit and loan only, in cents |
| Due day | Credit, loan, and fuel. Required for a monthly or yearly bank-autopay bill. 1–31 |
| Bank autopay | Checkbox. On generates the matching bill. Off leaves no bill |
| Bill recurrence | Daily, monthly, or yearly, with every 1 to 11 of that unit. Shown when bank autopay is on |
| Bill amount | Minimum or current balance, when bank autopay is on. Zero hides the bill. A later balance or minimum due above zero shows it again |
| Credit limit | Optional, credit only |
| Institution | Optional, up to 120 characters |
| Holder | Optional, up to 120 characters. A person or business the account belongs to. Blank leaves it empty |
| Notes | Optional, up to 2000 characters |
| Starred | At most one open account. The filled star stays on that card and on its form. The other stars stay hidden until that one is cleared. An archived account cannot be starred, and archiving clears the star |

Liability-only fields are cleared when the type does not use them. Opening an account covers the fields until that account has loaded. A new account shows the fields at once. The list is several cards on a row, only as tall as the content, and each card background is a gradient of the account type color. The institution, when there is one, sits in grey under the name. A chip shows only when that value is stored: the credit limit on credit and fuel, the annual percent, the minimum due on a liability, bank autopay as Autopay full or Autopay min, and the holder. Each chip has its own color. A dollar mark sits on an asset included in available to assign. An asset left out has no mark. A liability has no mark. It hides archived accounts until you ask for them. The starred account comes first, then account type A to Z, then name A to Z. Archive is refused while an active or paused bill draws from the account. Unarchive puts the account back on the list. A minimum due stays on the account. It becomes the bill amount only when bank autopay is on and the bill amount is minimum.

The balance stays a number you type. [Tracking accounts](19-tracking.md) and a typed value for a house or vehicle come later. The credit picture is dropped. Interest, minimum due, due day, and credit limit stay on this screen.
