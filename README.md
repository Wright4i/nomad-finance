# Nomad finance

A self-hosted register for accounts, trips, bill reminders, and exchange rates. The owner keeps their books. Demo keeps a separate sample set. A balance is a figure you can type. A posting moves it when you confirm. A bill reminder does not.

The database file is `data/finance.sqlite` (created on first launch, gitignored). This folder is in iCloud Drive. iCloud can corrupt a live SQLite file. Copy `data/finance.sqlite` somewhere outside iCloud when you want a backup, and avoid opening the app on two machines that sync this folder.

## Run it

```bash
composer install
npm install --prefix frontend
composer start
```

In a second terminal:

```bash
npm run dev --prefix frontend
```

Open http://127.0.0.1:5173. The first visit asks for your name, a password (12 characters or more), and a base currency. That creates the owner. When that base currency is USD, the owner's fallback rates start from the snapshot in `data/usd-rates.json`. A live quote for currencies on open accounts refreshes at most once a day. Demo is created at the same time, with the same fallback set. Later visits ask for a name and a password. Use demo signs in to the sample books. The demo password is `demo-books-open`.

Another login is off unless `FINANCE_NEW_USER=1`. With that unset, the login page offers Unlock and Use demo, and `POST /api/users` refuses a new user. The owner and Demo still sign in. A database with no owner still shows setup.

PHP listens on http://127.0.0.1:8080. Vite proxies `/api` to it.

Restore Demo's sample accounts, bills, rates, and buckets without changing the owner:

```bash
composer demo:reset
```

## Production

```bash
npm run build --prefix frontend
composer start
```

Open http://127.0.0.1:8080. Slim serves the built UI from `public/build`. Leave `FINANCE_NEW_USER` unset on a public host so visitors cannot create another login. Set `FINANCE_NEW_USER=1` only when you want the New user button.

`GET /api/homepage/today` is for a trusted dashboard widget. It stays off until you set `FINANCE_HOMEPAGE_TOKEN`. Each request must send that value in the `X-Homepage-Token` header. The response uses the owner's books for today's calendar notes and current stay. `FINANCE_HOMEPAGE_TZ` picks the calendar day; it defaults to `America/Los_Angeles`.

## Tests

```bash
composer test
composer test:browser
```

`composer test` is PHPUnit. `composer test:browser` is Selenium against the built UI. When to add each is in `docs/agents/testing.md`. Build the UI with `npm run build --prefix frontend` before the browser suite when `frontend/` changed. The first browser run downloads ChromeDriver into `var/`.

## Docs

- `GLOSSARY.md` — the words the app uses
- `docs/adr/` — the hard choices
- `docs/modules/README.md` — the module to build next, then each module's rules
- `docs/agents/` — where issues, triage labels, domain docs, and the testing rule live
