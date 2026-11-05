# Nomad finance

A personal register of accounts, bill reminders, and exchange rates. The owner keeps their own books. Demo keeps a separate sample set.

## Language

**Account**:
A named place that holds one currency and a balance you type.
_Avoid_: Wallet, ledger account

**Account type**:
One of checking, savings, cash, credit, loan, or other.
_Avoid_: Bucket, bill type

**Tracking account**:
An account counted in net worth and left out of envelope assignment. Its value is a number you type.
_Avoid_: Off-budget, investment account

**Side**:
Whether an account adds to or subtracts from net worth: asset or liability. Checking, savings, and cash are assets. Credit and loan are liabilities. Other stores the side you choose.

**Balance**:
The amount stored for an account, as a zero-or-greater integer in minor units. On an asset it is the amount on deposit. On a liability it is the amount owed. You can type a new figure. A posting moves the stored figure.
_Avoid_: Signed balance, running balance

**Minor unit**:
The smallest unit of a currency (cents, whole yen, fils). Every stored amount is an integer count of minor units.

**Base currency**:
The single ISO currency used for net worth. Chosen at setup, editable in settings. USD is the prefilled value.

**Exchange rate**:
How many units of the base currency equal 1 unit of another currency. A fallback is stored on the books and can be edited. A live quote for a currency on an open account replaces the fallback when that quote was fetched in the last day.

**Net worth**:
Converted assets minus converted liabilities, in the base currency. An account with no usable rate is left out and named on the dashboard.

**Bill**:
A reminder to pay, drawn from one account, with an amount in that account's currency.
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
The planned price of a trip, in the draw-from account's currency.
_Avoid_: Bill

**Amount due**:
The part of the lodging still to be charged, usually on arrival.
_Avoid_: Balance, total

**Balance-due date**:
The date a trip's amount due is expected, prefilled with arrival.
_Avoid_: Next due date, due day, horizon

**Deposit**:
Total minus amount due. The part arranged before arrival.

**Bill type**:
The kind of reminder, and it belongs to one group. Needs holds lodging, rent, electricity, water, fuel, propane, groceries, internet, cellular, vehicle, maintenance, insurance, roadside, healthcare, mailbox, laundry, mortgage, storage, and other. Wants holds restaurants, entertainment, subscriptions, shopping, travel, memberships, software, pets, and misc. Savings holds debt, emergency, repairs, investments, and liquidities. Other and misc each store a name you type. A preset type stores no extra name.
_Avoid_: Bucket

**Bucket**:
A budget bucket in one group: Needs, Wants, or Savings. The amount it holds is what is assigned to it in open months. The screen says bucket. The stored name stays category.
_Avoid_: Bill type, account type, envelope

**Default bucket**:
The system bucket for one group: Needs Default, Wants Default, or Savings Default. Its name, group, and overspend stay fixed. The only edit is one amount to hold.
_Avoid_: Goal bucket, bill type

**Fuel Bucket**:
The system bucket in Needs that holds fuel bills and logged fuel postings. Nothing else is filed there. Its only edit is one amount to hold.
_Avoid_: Goal bucket, fuel label

**Goal bucket**:
A bucket a person creates. It can hold a target, and a bill of the same group can point at it. A fuel bill cannot. It does not log fuel.
_Avoid_: Default bucket, Fuel Bucket

**Target**:
A goal on one bucket: an amount needed by a date, or an amount to hold. It compares with the bucket amount. A default bucket and Fuel Bucket hold an amount only.
_Avoid_: Plan, budget

**Bill amount**:
The one amount on a bill, in the currency chosen on that bill. The envelope stores it converted to the base currency.
_Avoid_: Vendor amount, balance

**Assignment**:
Cash held in a bucket for one month on the envelopes screen. Bills fill part of it. Cash typed above or below that part stays. A negative amount is overspending. It leaves the account balance as you last typed it.
_Avoid_: Posting, transaction

**Overspend**:
How a bucket covers a negative assignment. Cover now pulls it from available-to-assign while the month is open. Carry keeps it in the bucket and brings it into the next month.
_Avoid_: Debt, posting

**Available-to-assign**:
Checking, savings, and cash converted into the base currency, minus cash held in open assignments. A positive assignment counts. Cover now counts a negative assignment as that amount pulled out. Carry does not. Cash with no rate is named and left out. The number can be negative.
_Avoid_: To be budgeted

**Envelope**:
An assignment of cash to a bucket for the month on screen.
_Avoid_: Bucket, posting

**Plan**:
Retired from the books. The gauges stay on the calendar.
_Avoid_: Budget, envelope

**Percent cap**:
Retired from the books.
_Avoid_: Limit, credit limit

**Posting**:
A record of money moving, in the account's currency. It moves the stored balance when you confirm it. A fuel label is not a posting.
_Avoid_: Transaction, bill, fuel label

**Split**:
One posting named across more than one bucket. Each line is a bucket and an amount, and the lines sum to the posting. Tax and tip are buckets like any other.
_Avoid_: Transfer

**Logged fuel**:
A posting on Fuel Bucket. It can record an odometer and a price per gallon. A posting on any other bucket does not.
_Avoid_: Fuel label, fuel bill

**Vehicle**:
A named odometer: a kind, and a minimum and maximum miles per gallon. At most one vehicle is starred. The fuel label uses that one.
_Avoid_: Account, trip

**Odometer**:
The miles on one vehicle at a fuel posting.
_Avoid_: Trip distance

**Price per gallon**:
The price of one gallon of fuel, in the account's minor units.
_Avoid_: Exchange rate, fallback fuel price

**Fallback fuel price**:
One price per gallon stored on the user, in base-currency minor units, for a fuel label. Empty means the label shows miles only, and no cost.
_Avoid_: Price per gallon, exchange rate

**Stretch**:
The miles from the previous odometer reading on the same vehicle to this one, and the gallons on this fill-up.

**Fuel label**:
A cost on a stay from the driving miles since the previous stay, the starred vehicle's pooled in-range miles per gallon (or the midpoint of its range), and the fallback fuel price. It is not a posting, and it assigns nothing. The first stay has no cost. No starred vehicle means miles and no cost.
_Avoid_: Stretch, bill

**Planned payment**:
Retired from the books.
_Avoid_: Minimum due, paycheck

**Balance mark**:
Retired from the books.
_Avoid_: Target

**Interest**:
The optional annual rate stored on an account, in basis points. It is an account fact.
_Avoid_: Paycheck, interest charge

**Interest charge**:
Retired from the books. The annual rate on the account stays.
_Avoid_: Fee

**Interest yield**:
Retired from the books.
_Avoid_: Paycheck

**Minimum due**:
An optional amount on a credit or loan account, in that account's currency. It does not create a bill.
_Avoid_: Planned payment, bill

**Due day**:
An optional day of the month on a credit or loan account, from 1 to 31.
_Avoid_: Next due date, balance-due date

**Credit limit**:
An optional limit on a credit account.
_Avoid_: Percent cap

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
What one period of a pay cycle brings in. A salary on a regular length is the annual rate split across that length. An hourly paycheck is the rate times the hours. Weekdays mode on a semimonthly cycle splits that gross across the two paydays, half up, with the odd minor unit on the earlier day. A monthly weekday paycheck is the weekday count times the hours per weekday times the rate. It is a preview. An irregular salary is not a paycheck.
_Avoid_: Assignment, posting

**Rolling average**:
An optional window of 3, 4, 5, or 6 months on an hourly pay cycle, off by default. It is that cycle's confirmed inflows over the previous N calendar months, divided by N, half up, in the base currency. A month with no inflow counts as zero. An inflow with no usable rate is left out and named. It does not replace the paycheck and does not change available-to-assign.
_Avoid_: Paycheck

**Second day**:
The other day of the month a semimonthly pay cycle uses. The payday supplies the first day.

**Open period**:
The span of the pay cycle that contains today. It starts on a payday and ends the day before the next one. An irregular cycle with no next payday stays open. Envelopes calls that span the current month and the following span the next month.
_Avoid_: Horizon

**Horizon**:
The 30- or 60-day calendar window saved on the user: today through the next 29 days, or today through the next 59 days. The default is 60. It is not an assignment period.
_Avoid_: Pay period, open period

**Draw-from account**:
The account a bill is paid from. Required. Its currency is the bill amount's currency.

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
Hiding an account or a bucket without deleting it. Unarchive puts the name back on the list. Archiving an account is refused while an active or paused bill draws from it. Archiving a bucket is refused while its amount is not zero.

**Mark paid**:
Recording a bill's occurrence on the due date it had. A repeating bill then rolls its next due date forward. That paid occurrence stays on the original due date and counts in the paid total. The next occurrence is unpaid. The account balance stays as you last typed it. A one-off bill becomes paid and leaves the due list. Unpay removes that latest paid occurrence. A repeating bill moves back one interval and stays on the books. A one-off returns to the due list. An occurrence offers Mark paid or Unpay, not both.

**Setup**:
The first-run screen that creates the owner: name, password, base currency, a starred vehicle, and the fallback fuel price. An empty fuel price means miles only.

**Owner**:
The person who created the books at setup. Their accounts, bills, and rates stay theirs.
_Avoid_: Admin, the real user

**Demo**:
The sample user created with the owner. Tests and agents use Demo. Resetting Demo puts the sample accounts, bills, rates, buckets, and pay cycles back.
_Avoid_: Guest, test user
