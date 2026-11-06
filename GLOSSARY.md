# Nomad finance

A personal register of accounts and bill reminders. The owner keeps their own books. Demo keeps a separate sample set.

## Language

**Account**:
A named place that holds a dollar balance you type.
_Avoid_: Wallet, ledger account

**Account type**:
One of checking, savings, cash, credit, loan, fuel, or other. A fuel account is a liability, like a credit card.
_Avoid_: Bucket, bill type

**Tracking account**:
An account counted in net worth and left out of envelope assignment. Its value is a number you type.
_Avoid_: Off-budget, investment account

**Side**:
Whether an account adds to or subtracts from net worth: asset or liability. Checking, savings, and cash are assets. Credit, loan, and fuel are liabilities. Other stores the side you choose.

**Balance**:
The amount stored for an account, as a zero-or-greater integer in cents. On an asset it is the amount on deposit. On a liability it is the amount owed. It changes only when you type a new figure.
_Avoid_: Signed balance, running balance

**Minor unit**:
A cent. Every stored amount is an integer count of cents.

**Base currency**:
Retired. Books are dollars.
_Avoid_: ISO code, exchange rate

**Exchange rate**:
Retired. Amounts are not converted.
_Avoid_: Live quote, fallback rate, FX

**Net worth**:
Assets minus liabilities, in cents. Every account is included.

**Bill**:
A reminder to pay, drawn from one account, with an amount in cents.
_Avoid_: Invoice, transaction, posting

**Trip**:
A stay at one place from an arrival date through a departure date.
_Avoid_: Envelope, booking

**Place**:
The named spot a trip sits on, with coordinates.
_Avoid_: Destination

**Trip note**:
An optional reminder kept with the place name, such as a site number.
_Avoid_: Title, second name

**Lodging**:
The price of a trip, in cents. Saving it does not change an account balance.
_Avoid_: Bill

**Amount due**:
The part of the lodging still to pay, usually on arrival. It does not change an account balance. Greater than zero, with a balance-due date, it is one lodging bill on that date.
_Avoid_: Balance, total

**Balance-due date**:
The date a trip's amount due is expected, prefilled with arrival. That date is the lodging bill's due date.
_Avoid_: Next due date, due day, horizon

**Deposit**:
Total minus amount due. The part arranged before arrival.

**Bill type**:
The kind of reminder, and it belongs to one group. Needs holds lodging, rent, electricity, water, fuel, propane, groceries, internet, cellular, vehicle, maintenance, insurance, roadside, healthcare, mailbox, laundry, mortgage, storage, and other. Wants holds restaurants, entertainment, subscriptions, shopping, travel, memberships, software, pets, and misc. Savings holds debt, emergency, repairs, investments, and liquidities. Other and misc each store a name you type. A preset type stores no extra name.
_Avoid_: Bucket

**Bucket**:
A budget bucket in one group: Needs, Wants, or Savings. The amount it holds is what is assigned to it in open months. The screen says bucket. Routes say bucket.
_Avoid_: Bill type, account type, envelope

**Locked bucket**:
One bucket for one bill type, in that type's group. Other and misc are one each. Its name and group stay fixed. Its assignment starts at the bills for the month. That amount can be typed higher, and it cannot be lower than those bills. Its maximum starts at the bills. It cannot be archived or edited. A bill files there unless the form points it at a manual bucket in the same group. A fuel bill cannot.
_Avoid_: Default bucket, Fuel Bucket, goal bucket

**Goal bucket**:
A bucket a person creates. It can hold a target, and a bill of the same group can point at it. A fuel bill cannot.
_Avoid_: Locked bucket

**Target**:
A goal on one bucket: an amount needed by a date, or an amount to hold. It compares with the bucket amount. A locked bucket's maximum is an amount to hold.
_Avoid_: Plan, budget

**Bill amount**:
The one amount on a bill, in cents. The envelope stores that same amount.
_Avoid_: Vendor amount, balance

**Assignment**:
Cash held in a bucket for one month on the Budget screen. Zero or more. A new month starts empty, and recurring bills are the exception. A locked bucket cannot be assigned less than its bills. It leaves the account balance as you last typed it.
_Avoid_: Posting, transaction

**Overspend**:
Retired. An assignment cannot be negative.
_Avoid_: Cover now, carry, debt

**Available-to-assign**:
Income still coming in this month, plus every asset account except savings, minus what the buckets already hold. A payday before today is left out. Savings accounts, credit cards, loans, fuel accounts, and tracking accounts stay out. The line under the number is what is already assigned of that income plus those accounts. Needs is 50% of that income plus those accounts, Wants 30%, and Savings 20%, half up to the cent. A closed or upcoming month does not change the number. The number can be zero, and it can be negative when the buckets hold more than that income and those accounts.
_Avoid_: To be budgeted

**Envelope**:
An assignment of cash to a bucket for the month on screen. Remaining is that assignment.
_Avoid_: Bucket, posting

**Plan**:
Retired from the books. The gauges stay on the calendar.
_Avoid_: Envelope

**Percent cap**:
Retired from the books.
_Avoid_: Limit, credit limit

**Posting**:
Retired. A balance changes only when you type it.
_Avoid_: Transaction, charge

**Split**:
Retired. A posting is not split across buckets.
_Avoid_: Line item

**Logged fuel**:
An unpaid one-time fuel bill named Fuel, filed on the locked Fuel bucket. It can record a vehicle, an odometer, and a price per gallon. It does not change an account balance.
_Avoid_: Fuel label, posting

**Want log**:
An unpaid one-time wants bill, filed on that wants type's locked bucket. It does not change an account balance.
_Avoid_: Logged fuel, posting

**Need log**:
An unpaid one-time needs bill, filed on that needs type's locked bucket. Fuel is not one of those types. It does not change an account balance.
_Avoid_: Logged fuel, want log

**Saving log**:
An unpaid one-time savings bill, filed on that savings type's locked bucket. It does not change an account balance.
_Avoid_: Want log, posting

**Vehicle**:
A named odometer: a kind, a current odometer, and a minimum and maximum miles per gallon. At most one vehicle is starred. The fuel label uses that one.
_Avoid_: Account, trip

**Current odometer**:
The miles on a vehicle now. Zero is allowed only when it is typed. A fuel log cannot record a lower reading, and the current odometer cannot be set below the highest fuel-bill reading.
_Avoid_: Odometer, origin

**Odometer**:
The miles on one vehicle at a fuel bill.
_Avoid_: Trip distance, current odometer

**Price per gallon**:
The price of one gallon of fuel, in cents.
_Avoid_: Exchange rate, fallback fuel price

**Fallback fuel price**:
One price per gallon stored on a vehicle, in cents. A new fuel log and a trip fuel label use that vehicle's newest priced fill-up, then this price. Empty means that vehicle has no fallback.
_Avoid_: Price per gallon, exchange rate

**Fill-up**:
Every fuel bill on one vehicle with the same odometer. Miles are that reading minus the previous different reading. Gallons are the charges that have a price, added as amount divided by price. Miles per gallon is miles divided by gallons. A charge with no price hides miles per gallon for that fill-up.
_Avoid_: Stretch, fuel label

**Stretch**:
One fill-up: its miles and its gallons.

**Fuel label**:
A cost on a stay from the driving miles since the previous stay, the starred vehicle's pooled in-range miles per gallon (or the midpoint of its range), and that vehicle's newest priced fill-up or its fallback fuel price. It assigns nothing and does not change a balance. The first stay has no cost. No starred vehicle means miles and no cost.
_Avoid_: Stretch, bill

**Planned payment**:
Retired from the books.
_Avoid_: Minimum due, paycheck

**Balance mark**:
Retired from the books.
_Avoid_: Target

**Interest**:
The optional annual rate stored on an account, in basis points. One hundred basis points is one percent, so 500 is 5 percent a year. It is an account fact.
_Avoid_: Paycheck, interest charge

**Interest lost**:
The monthly cost of interest on credit and loan accounts. Each balance times its interest divided by 12, half up to the cent, then added. A blank rate, a zero rate, or a balance that is not positive adds nothing. Fuel and other stay out.
_Avoid_: Interest charge

**Interest gained**:
The monthly gain from interest on savings accounts. The monthly rate is one plus the annual rate, to the power of one twelfth, minus one, applied to the balance, half up to the cent, then added. A blank rate, a zero rate, or a balance that is not positive adds nothing. Checking and cash stay out.
_Avoid_: Interest yield

**Interest charge**:
Retired from the books. The annual rate on the account stays.
_Avoid_: Fee

**Interest yield**:
Retired from the books.
_Avoid_: Paycheck

**Minimum due**:
An optional amount on a credit, loan, or fuel account. Choosing minimum on that account keeps one monthly bill for this amount.
_Avoid_: Planned payment, balance

**Due day**:
A day of the month, from 1 to 31, on a credit, loan, or fuel account. Required when that account keeps a bill for minimum or current balance.
_Avoid_: Next due date, balance-due date

**Credit limit**:
An optional limit on a credit or fuel account.
_Avoid_: Percent cap

**Liability bill**:
The one monthly bill a credit, loan, or fuel account keeps when autopay is minimum or the current balance. A card or loan bill is type debt on the locked Debt bucket. A fuel-account bill is type fuel on the locked Fuel bucket. None, a zero minimum, or a zero balance hides it. Mark paid does not change the account balance or the next amount.
_Avoid_: Logged fuel, autopay

**Utilization**:
Retired from the books.
_Avoid_: Available credit

**Original amount**:
Retired from the books.
_Avoid_: Balance, credit limit

**Payor**:
A named source of pay. Each payor has one pay cycle.
_Avoid_: Employer, payer, income

**Pay cycle**:
A payor's period: a payday plus a length you choose. Weekly, biweekly, semimonthly, monthly, and irregular are the lengths.
_Avoid_: Budget month, statement cycle

**Pay kind**:
How a paycheck is figured: salary, fixed, or hourly.

**Pay rate**:
The number a pay kind uses, in the base currency. A salary rate is per year. A fixed rate is the paycheck. An hourly rate is per hour.
_Avoid_: Exchange rate

**Hours**:
The hours in one period of an hourly pay cycle. Typed mode stores them on the cycle.

**Hours mode**:
Typed or weekdays, on an hourly pay cycle. Typed is the default. Weekdays counts Monday through Friday in the calendar month before the payday's month. On a semimonthly cycle that count is times 8. On a monthly cycle it is a monthly weekday.
_Avoid_: Pay kind

**Monthly weekday**:
An hourly monthly pay cycle. Its hours are the weekdays in the month before the payday, times the hours per weekday.
_Avoid_: Weekdays, day of the month

**Paycheck**:
What one period of a pay cycle brings in. A salary on a regular length is the annual rate split across that length. An hourly paycheck is the rate times the hours. Weekdays mode on a semimonthly cycle splits that gross across the two paydays, half up, with the odd minor unit on the earlier day. A monthly weekday paycheck is the weekday count times the hours per weekday times the rate. It is a preview. An irregular salary is not a paycheck. On the income list it is shown beside the open period.
_Avoid_: Assignment, posting

**Monthly amount**:
The check scaled to a month, half up to the cent. Weekly multiplies by 52/12, biweekly by 26/12, semimonthly by 2, and monthly stays the check. An irregular cycle has no monthly amount. On the income list the chip is labeled Monthly.
_Avoid_: Paycheck

**Average payroll**:
A checkbox on a pay cycle, off by default. When it is on and a payday has no payroll entry, that payday shows the average of keyed amounts dated before it, half up.
_Avoid_: Rolling average, paycheck

**Payroll entry**:
One dollar amount on one payday of a pay cycle. A keyed amount always wins. An empty amount means that date is not keyed. A keyed irregular date does not change the stored next payday.
_Avoid_: Paycheck, posting

**Rolling average**:
Retired. The 3-to-6 month window is gone.
_Avoid_: Average payroll

**Second day**:
The other day of the month a semimonthly pay cycle uses. The payday supplies the first day.

**Open period**:
The span of the pay cycle that contains today. It starts on a payday and ends the day before the next one. An irregular cycle with no next payday stays open. Budget calls that span the current month and the following span the next month.
_Avoid_: Horizon

**Horizon**:
The 30- or 60-day calendar window saved on the user: today through the next 29 days, or today through the next 59 days. The default is 60. It is not an assignment period.
_Avoid_: Pay period, open period

**Calendar entry**:
A note on a span of days, from a start date through an end date.
_Avoid_: Event, appointment, reminder, stay

**Draw-from account**:
The account a bill is paid from. Required.

**Vendor amount**:
Optional record of what the biller charged, in the biller's currency. It is reference only.

**Recurrence**:
How a bill repeats: once, weekly, monthly, or yearly.

**Anchor date**:
The due date used to keep a monthly or yearly day-of-month. Short months clamp, then the original day returns.

**Next due date**:
The upcoming calendar date for a bill. Marking paid moves it forward one interval.

**Autopay**:
A flag that a bill is paid automatically by the bank. The app does not mark it paid by itself.

**Archive**:
Hiding an account, a goal bucket, or a paused bill without deleting it. Unarchive puts it back: a bill returns to paused. A locked bucket cannot be archived. A liability bill cannot be archived. An archived bill does not fill a bucket and does not block account archive. Archiving an account is refused while an active or paused bill draws from it. Archiving a goal bucket sends its bills back to the locked bucket for each bill's type.

**Mark paid**:
Recording a bill's occurrence on the due date it had. A repeating bill then rolls its next due date forward. That paid occurrence stays on the original due date and counts in the paid total. The next occurrence is unpaid. The account balance stays as you last typed it. A one-off bill becomes paid and leaves the due list. Unpay removes that latest paid occurrence. A repeating bill moves back one interval and stays on the books. A one-off returns to the due list. An occurrence offers Mark paid or Unpay, not both.

**Setup**:
The first-run screen that creates the owner: name, password, a starred vehicle, and that vehicle's fallback fuel price. An empty fuel price means miles only.

**Owner**:
The person who created the books at setup. Their accounts and bills stay theirs.
_Avoid_: Admin, the real user

**Demo**:
The sample user created with the owner. Tests and agents use Demo. Resetting Demo puts the sample accounts, bills, rates, buckets, pay cycles, and calendar entries back.
_Avoid_: Guest, test user
