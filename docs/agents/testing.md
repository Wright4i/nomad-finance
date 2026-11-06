# Tests

A change is finished when a unit test covers the rule and, if the screen changed, a Selenium test covers what a person sees, and both `composer test` and `composer test:browser` have been run.

`composer test` is the unit suite. `composer test:browser` is the Selenium suite. `phpunit.xml` lists the directories in each suite.

## Unit

Put a rule that does not need a browser under `tests/`, outside `tests/Browser`. Money, dates, account fields, bill schedules, lockout, and "mark paid leaves the balance alone" belong here. Each test opens its own SQLite file in the system temp directory and signs in as Demo when it writes books. Add a new directory to the `unit` suite in `phpunit.xml` when you create one.

## Browser

A change a person can see gets a Selenium test in `tests/Browser`. The suite starts Chrome through ChromeDriver, serves `public/build` on its own PHP port, and sets `FINANCE_DB` to a throwaway file. It signs in as Demo on that file. `data/finance.sqlite` stays out of the suite, and the owner's rows stay out of the suite. The first run downloads a ChromeDriver build that matches the installed Chrome into `var/`. `FINANCE_NEW_USER` defaults off. The New user browser test sets `FINANCE_NEW_USER=1` so that screen still creates a user. The closed login test sets `FINANCE_NEW_USER=0`.

When an agent uses the running app, sign in as Demo. `composer demo:reset` restores Demo's sample books in the app database and does not change the owner.

## Demo seed

The seed in `src/Demo/DemoLibrary.php` is one full-timer in a Class C. It holds one account for every account type, both sides of other, and an archived account. Every account is in dollars. Camp jar and Glovebox are cash in the pool. Rig fund is savings, in net worth, and out of the pool. Everyday checking, Wallet, and Solar kit are in the pool. Emergency fund stays out of the pool. It holds one bill for every bill type, recurrence, and status, plus autopay, and an overdue due date. Metered electric is weekly, paid on Nov 4, and next due Nov 11. Shower tokens stores the other name Shower tokens. Camp soap stores the misc name Camp soap. Bunkhouse is an rv, starred, with a minimum of 6 mpg and a maximum of 12, a current odometer of 10500, an origin of 9800, and a fallback price of $4.00. Side-by-side is a utv, Quad is an atv, Golf cart is a golf cart, and Dirt bike is a dirt bike. Paid fuel bills named Fill-up, on Wallet and the locked Fuel bucket, hold the fill-ups: 2026-09-15 at 9980 miles for $10.00 at $4.00 a gallon, 2026-10-01 at 10000 as two charges of $6.00 and $4.00, 2026-10-15 for $5.00 with no vehicle, 2026-11-01 at 10100 for $20.00, 2026-11-08 at 10300 for $10.00, and 2026-11-18 at 10500 for $10.00. One of the vehicle fill-ups is inside the mpg range and one is outside it. Site week points at Road cushion. The fuel bill stays on the locked Fuel bucket. It holds one locked bucket per bill type, plus one goal in each group — Road cushion, Fun money, and Next rig — and an archived bucket. Fuel card is a fuel liability with a credit limit. Travel card keeps a minimum bill. Solar loan keeps a current-balance bill. Old meal kit is archived. Road cushion holds 500.00 from the open period. Three payors are live: Camp host, Remote salary, and Photo gigs. The other lengths stay archived: Market booth, Design retainer, Editing days, Client average, Contract days, and Warehouse job. Client average is semimonthly with average payroll on, and keyed amounts of 800.00, 1,100.00, and 950.00. Each payday is 2026-11-01. Design retainer uses a second day of 15. Photo gigs has next payday 2026-12-15. Remote salary keeps a closed period for 2026-10-01 through 2026-10-31 with Road cushion at 200.00, and an open period for 2026-11-01 through 2026-11-30 with Road cushion at 500.00. Assignments are zero or more. A locked bucket's assignment is its unpaid bills. Tires is a 1,200.00 goal in Savings on Remote salary for 2027-02-01. Repair reserve is 500.00 in Savings. Stays run from Devils Garden on 2026-09-06 through La Posa South on 2027-01-08, and every day in that span is inside a stay. Lost Dutchman is unrated with the balance due on 2026-11-22. Cottonwood stores a rating. Dump and fill covers 2026-11-05 through 2026-11-06.

When a module adds a type, side, recurrence, or status, add a seeded row for it in the same pass as the module. A variant with no seeded row is unfinished.

When `frontend/` changed, build before the browser suite:

```bash
npm run build --prefix frontend
```

## Finish a change

1. Add or update the unit test that fails if the rule regresses.
2. When the screen changed, add or update the Selenium test for what a person sees.
3. Run `composer test`.
4. Run `composer test:browser`.
5. Both commands exit 0.
