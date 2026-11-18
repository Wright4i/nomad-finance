# Tests

A change is finished when a unit test covers the rule and, if the screen changed, a Selenium test covers what a person sees, and both `composer test` and `composer test:browser` have been run.

`composer test` is the unit suite. `composer test:browser` is the Selenium suite. `phpunit.xml` lists the directories in each suite.

## Unit

Put a rule that does not need a browser under `tests/`, outside `tests/Browser`. Money, dates, account fields, bill schedules, lockout, and "mark paid leaves the balance alone" belong here. Each test opens its own SQLite file in the system temp directory and signs in as Demo when it writes books. Add a new directory to the `unit` suite in `phpunit.xml` when you create one.

## Browser

A change a person can see gets a Selenium test in `tests/Browser`. The suite starts Chrome through ChromeDriver, serves `public/build` on its own PHP port, and sets `FINANCE_DB` to a throwaway file. It signs in as Demo on that file. `data/finance.sqlite` stays out of the suite, and the owner's rows stay out of the suite. The first run downloads a ChromeDriver build that matches the installed Chrome into `var/`. `FINANCE_NEW_USER` defaults off. The New user browser test sets `FINANCE_NEW_USER=1` so that screen still creates a user. The closed login test sets `FINANCE_NEW_USER=0`.

When an agent uses the running app, sign in as Demo. `composer demo:reset` restores Demo's sample books in the app database and does not change the owner.

## Demo seed

The seed in `src/Demo/DemoLibrary.php` is one anonymized full-timer. The signed-in name stays Demo. It holds one open account for every account type, both sides of other, and an archived checking account. Every account is in dollars. Fuel card is the gas favorite, Travel card is the shopping favorite, and Checking is the asset favorite. The Travel card, Truck loan, Trailer plan, and Fuel card bills draw from Checking. Include in Budget is on for Checking and Center console, so those balances are in the pool. It is off for Savings and Park deposit. Those two stay in net worth and out of the pool. It holds one bill for every bill type, recurrence, and status, plus autopay, and an overdue due date. Metered electric is weekly, paid on Nov 4, and next due Nov 11. Registration is yearly, every 2 years, due 2027-06-15. Fuel card keeps a daily bill, every 7 days, next due 2026-11-07, because bank autopay is on. Shower tokens stores the other name Shower tokens. Camp soap stores the misc name Camp soap. Pickup is a truck, starred, with a minimum of 6 mpg and a maximum of 12, a current odometer of 64900, an origin of 64000, and a fallback price of $5.50. Side-by-side is a utv, Quad is an atv, Golf cart is a golf cart, and Dirt bike is a dirt bike. Old truck is an archived truck. Paid fuel bills named Fill-up, on Center console and the locked Fuel bucket, hold the fill-ups: 2026-09-15 at 64400 miles for $220.00 at $5.50 a gallon, 2026-10-01 at 64800 as two charges of $60.00 and $40.00, 2026-10-15 for $50.00 with no vehicle, and 2026-11-07 at 64900 for $122.00. One of the vehicle fill-ups is inside the mpg range and one is outside it. Site week points at Road cushion. The fuel bill stays on the locked Fuel bucket. It holds one locked bucket per bill type, plus goals named Road cushion, Fun money, Next rig, and New trailer, and an archived bucket. Fuel card is a fuel liability with a credit limit. Travel card keeps a minimum bill and pays the balance by autopay. Old meal kit is archived. Road cushion has one envelope on Day job: 200.00 in the closed October period and 500.00 in the open November period. Two payors are live: Day job, a monthly $100,000 salary, and Side job, semimonthly hourly at $22 for 20.00 hours with average payroll on. The other lengths stay archived: Weekend shift, Market day, Old retainer, Weekday contract, Month average, Shop days, Odd jobs, and Warehouse shift. Month average is semimonthly with average payroll on, and keyed amounts of 800.00, 1,100.00, and 950.00. Day job's payday is 2026-11-01. Side job uses a second day of 16. Odd jobs has next payday 2026-12-15 and is archived. Shop days is hourly for 3.00 hours per weekday, so the preview uses October 2026. Assignments are zero or more. A locked bucket's assignment is its unpaid bills. Tires is a 1,200.00 goal on Day job for 2027-02-01. Repair reserve is 500.00. New trailer is a 50,000.00 balance goal. Stays run from Home base on 2026-01-01 through one night at Las Vegas RV Resort, arriving 2026-10-23 and leaving 2026-10-24, and every day in that span is on a site. OCEAN User Group is fly. The other stays are drive. Las Vegas RV Resort is unrated, paid in full, with note Site 314. Lake Tahoe KOA stores 8 half-stars and a comment. Dump and fill covers 2026-10-06 through 2026-10-07. November has no stay.

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
