# Scanzo

A multi-tenant point-of-sale system, sold as a subscription to independent
shops. Every item carries a printed QR label; the seller scans it with a phone
camera or a USB scanner, and the system builds the invoice, handles returns and
keeps stock per branch.

Read **[docs/](docs/README.md)** before changing anything structural:

- `docs/decisions.md` — 15 decisions with the reasoning. **Read this first.**
  Most "why is it done this odd way" questions are answered there, and
  reversing one of them by accident is the expensive mistake in this codebase.
- `docs/schema.md` — the tables, and the six rules the application must enforce
- `docs/roadmap.md` — what ships in which phase, and the open questions
- `docs/onboarding.md` — what taking on a customer involves

## Layout

```
.
├─ core/            the Laravel application
│  ├─ app/Actions/      business operations, one class each
│  ├─ app/Services/     StockLedger, SubscriptionLimits
│  ├─ app/Support/      Money, InvoiceNumber, SkuGenerator, BusinessContext
│  └─ resources/js/     till/ (Vue SPA), shared/ (api + session)
├─ .docker/         Dockerfile, nginx, php, supervisor
├─ scripts/         entrypoint and setup
└─ docs/
```

## Running it

```bash
docker compose up -d
docker exec -it scanzo-app bash -c '/bin/prepare_env.sh'   # migrate + seed + storage:link
```

`prepare_env.sh` runs `php artisan storage:link`. It is **required**: shop logos
are uploaded to `storage/app/public/brands/{business_id}/` and served from
`/storage/…`, which only works through that symlink. If you set up without
`prepare_env.sh`, run `docker exec scanzo-app php artisan storage:link` once.
`core/` is bind-mounted, so `storage/` lives on the host and survives a rebuild.

| | |
|---|---|
| App | <http://127.0.0.1:49100> |
| Till | `/till` (Vue) · `/till-harness` (no build step, for checking the API alone) |
| Tests | `docker exec -it scanzo-app php artisan test` |
| Front end, while working | `docker exec -it scanzo-app npm run dev` — leave it running; edits appear immediately |
| Front end, to verify or ship | `npm --prefix core run build` |

Demo accounts, all with the password `password`:
`owner@` / `manager@` / `seller@demo-shop.local`, and `admin@scanzo.local`.

**While developing, run `npm run dev` and leave it running** — Vite picks up
every edit over HMR, so nothing needs rebuilding by hand. Port 5173 is
published on the `app` service for this. Quit it with Ctrl+C and not by
closing the terminal, or it leaves `public/hot` behind; the note under
environment gotchas explains what that does.

**Build the front end with `docker exec scanzo-app npm run build`.**

Use that exact command and nothing else. The alternatives all have a way to
fail here: `package.json` is in `core/`, not at the root, so a bare
`npm run build` depends on where the shell happens to be and
`npm --prefix core run build` breaks the moment the shell is already inside
`core/`. The host's Node is 19, which Vite 7 refuses; the container's is
20.15.1, which works. And a compound `cd core && …` is not a command this
session is allowed to run.

Building while the dev server is running is safe: Laravel ignores
`public/build` as long as `public/hot` exists.

## Running the tests

**Run the suite before reporting a piece of work finished:**

```
docker exec scanzo-app php artisan test
```

No `-it`. There is no TTY here, and `docker exec -it` fails with "the input
device is not a TTY" before it reaches PHP.

`php -l` proves a file parses. It does not prove the code runs: a declared
return type that a branch violates, a query with two conditions on one column,
a middleware that never fires — none of those are syntax errors, and all three
shipped in this project because the suite was not run for ten pieces of work in
a row. Running it takes about seven seconds.

If a test fails for a reason outside the change, say which test and why rather
than adjusting the test to pass.

## How to report back

Every run here is a fresh session with no memory of the last one, so end a
piece of work with these three lists, always in this order:

1. **What was done** — the change, briefly, file by file where it matters.
2. **What was not done, or done differently from the brief** — anything
   skipped, blocked, or decided another way, and why.
3. **Risks and anything needing a decision** — what might be wrong, what was
   noticed in passing and deliberately left alone, and the questions worth
   answering before the next step.

The third list is the valuable one. Report a command that was refused rather
than working around it, and say plainly what was never run.

## Conventions that are not obvious

- **Every tenant model uses `BelongsToBusiness`.** It scopes reads and stamps
  `business_id` on create. A model without it is a data leak.
- **`exists:` validation rules run unscoped**, so they must be written
  `Rule::exists('devices', 'id')->where('business_id', $this->user()->business_id)`.
  A plain `exists:devices,id` lets another tenant's id through.
- **Stock is only ever changed by writing a `stock_movements` row**, through
  `StockLedger`. Never update a quantity column; there isn't one.
- **Money is integer piastres** (`App\Support\Money`, and `cart.js` on the
  front end). Not floats, and not bcmath — that extension is not in the image.
- **UUID primary keys must be ordered** (`HasUuids`, never `Str::uuid()`):
  random ones fragment InnoDB's clustered index.
- **Invoice lines snapshot their own price, name and SKU.** Never read today's
  catalogue when printing an old receipt.
- **Errors use `App\Exceptions\DomainException`**, which renders as
  `{message, code, context}`. The front end reacts to `code`, so new failure
  modes need a factory method rather than a bare `abort()`.
- **Authorization is gates** (`manage-catalogue`, `manage-stock`, `view-cost`,
  `manage-business`) checked with `Gate::denies()` plus a DomainException, so
  the error shape stays uniform. `$this->authorize()` does not exist: Laravel
  12's base controller has no traits.
- **Lazy loading is off outside production.** Use `loadMissing()`.
- **Anything stored must have a reader; anything written must have a caller.**
  This codebase has produced the same bug four times: a hardcoded
  `payment_method` the UI never asked for, a `currency` setting saved and never
  read, a `labels.size` the label screen ignored, and a correct
  `Subscription::isActive()` that nothing calls. Each one is worse than the
  missing feature would have been, because the owner changes a setting, sees it
  save, and believes it took effect. When adding a field, a setting or a
  predicate, name the code that consumes it in the same change — or say plainly
  that nothing does yet.
- **Deleting a branch destroys its stock history.** `stock_movements.branch_id`
  cascades, `branches` has no soft deletes, and stock is only ever a ledger —
  there is no quantity column to fall back on. `BranchController::destroy`
  must refuse a branch that has movements, not only one that has invoices.
  Disabling a branch with `is_active` is the safe path and the one the UI
  offers.

## Colour, type and brand (decision 19)

- **Tokens live in `core/public/css/tokens.css` and nowhere else.** It is a plain
  static file linked by the till, dashboard and admin views; `app.css` only
  consumes it. Edit it, then **bump `scanzo.assets_version`** in
  `config/scanzo.php` (same for `public/js/brand-boot.js` and `admin-shell.js`).
- **Never declare `--c-danger` / `--c-warn` / `--c-success` (or their `-bg`)
  outside `:root`.** `DesignTokensTest` checks.
- **Text on a brand colour is derived by `App\Support\Brand\Contrast`, never
  picked or stored.** Use `var(--accent-fg)` / `var(--side-fg)`, not `#fff`.
- Motion is CSS only, ≤200ms in the till, and off under `prefers-reduced-motion`.
  No animation or smooth-scroll library.

## The front end

**Two entry points, not one.** `till/` and `dashboard/` build separately and
are served from separate routes. They are not merged, because the till has to
run offline later with a local database and a sync queue, and the dashboard
does not. Merging them would drag catalogue editing, stock receiving, labels
and reports into the bundle that must be made offline-safe, for no benefit —
nobody receives stock with the connection down.

**Each one is laid out for the device it is used on.** A phone layout stretched
across a desktop, or a desktop layout squeezed onto a phone, is not acceptable
in either app.

- **Till on a phone** — one screen at a time, the camera viewfinder is the
  hero, thumb-sized targets, and the flow is sell → cart → receipt.
- **Till on a desktop at the counter** — keyboard first, driven by a USB
  scanner rather than a camera. The scan box and the cart belong side by side
  on one screen with the total always visible, not as a sequence of screens.
  This is a different interaction model, not a wider phone.
- **Dashboard on a desktop** — persistent side navigation and real tables.
- **Dashboard on a phone** — the same screens, with navigation that collapses
  and tables that stop being tables.

**Logic never forks by device, only layout.** State and I/O live in `shared/`
and `till/cart.js`; the views arrange them. If a device difference is pushing
you to duplicate a function, the split is in the wrong place.

**Navigation is role-aware.** Hide what a role cannot do rather than showing it
and refusing on click:

| | seller | branch manager | owner |
|---|---|---|---|
| sell (`/till`) | ✓ | ✓ | ✓ |
| products · stock · labels | | ✓ | ✓ |
| invoices · reports | | ✓ | ✓ |
| settings · team · branches · devices | | | ✓ |

A section only joins the navigation once its screen exists. Navigation that
leads to an empty screen is worse than navigation that is still short.

## Environment gotchas, each of which has cost an hour

- **`core/` is bind-mounted**, so host file permissions decide what php-fpm can
  read. On "Permission denied", run `sudo ./scripts/fix_host_permissions.sh`
  from the host. It installs default ACLs so it does not recur.
- **The entrypoint does not cache config on `local`.** It used to, and an edit
  to `.env` then had no effect until `artisan optimize:clear` — a setting
  plainly right in the file and plainly wrong in the app.
- **Tests run against the `mysql_test` service, not sqlite** (see
  `phpunit.xml`). The schema targets MySQL 8 and sqlite accepts things MySQL
  rejects.
- **`@vitejs/plugin-vue` must be v6** for Vite 7. v5 refuses to resolve.
- **A stale `core/public/hot` renders every page blank.** `npm run dev` writes
  it, and while it exists `@vite` ignores the built assets and points at the
  dev server instead. Kill the dev server without a clean exit and the file
  stays behind: no error in the console, no error in the log, just an empty
  page. `rm core/public/hot` restores the built assets. The dev server also
  needs port 5173 published on the `app` service, or the browser can never
  reach it.
- Label printing needs `bacon/bacon-qr-code` and
  `picqer/php-barcode-generator` (the browser print page), and
  `tecnickcom/tcpdf` (the PDF); each page says so if its package is missing.
  The PDF is the interface to every label printer: download it, or share it
  to the printer's own app. Not Web Bluetooth.
- **Three browser features need HTTPS (or `localhost`), and a plain-HTTP
  production site loses all three:** the camera (`getUserMedia`),
  `crypto.randomUUID`, and `navigator.share` with a file. The first two break
  selling; the third only hides the label share button. Put SSL in front of
  any deployment before the first customer.

## State

Phase 1's back end is complete and its tests pass (262 passed, 1 skipped: the
xlsx import test, which runs once the xlsx library is installed). Built and
running:

- **Till**: sell (camera and USB scanner), line discount and invoice discount,
  payment method, returns, exchange, the day's invoices, name search.
- **Dashboard**: catalogue, categories, catalogue import, stock (receive,
  count, movements), labels, invoices with receipt and **void**, **reports**,
  and the settings, team, branches and devices screens.
- **Platform panel** at `/admin` (Blade, `admin` guard, its own session cookie).
- A deactivated user is refused on every request (`EnsureAccountIsActive`).

**Shifts (till drawer).** One open shift per device, enforced by a unique
`shifts.open_device_id` (set while open, NULL once closed). Expected cash is
`DrawerCash`: float + cash sales − cash invoices voided − cash refunds −
exchange credit applied to a cash invoice, by device and time window, cash only.
It is stored at close with the signed difference (`counted − expected`) and
never recomputed, and an open shift's resource never carries it (blind count).
An open shift from an earlier shop day is closed by the next `OpenShift`
without a count (`close_kind=auto`, no difference), dated at the till's last
sale. A seller closes only their own shift; managers and the owner any in reach.

**Discounts, as they now work.** The seller's cap is on the combined line +
invoice discount against the gross total before either (plus each line's own cap). The invoice discount is spread over the lines
*when the sale is made* (`Money::allocate`) and stored per line in
`invoice_items.invoice_discount_share`. What was paid for a line is
`line_total − invoice_discount_share`; returns and exchanges refund from that
stored figure and never recompute it. One setting, `settings.discounts`
(`sellers_allowed`, `max_percent`), governs both line and invoice discounts:
sellers need it switched on and are capped; managers and owners are never
capped. The old key was `line_discounts`, and a migration moved it.
"Best sellers by value" is after both discounts.

Known gaps, in the order they matter:

- **Shifts: the dashboard screen is not built.** The API (`GET /api/shifts`
  with branch/user/date/status/`problems` filters) and the till's open and
  close screens are. Also unbuilt: the till's shift screens have not been
  walked through in a browser, and `npm run build` was not run after them.
- **Selling does not require an open shift**, so cash taken outside a shift is
  in no drawer's expected figure. Deliberate for now; the till nudges.
- Returns made *before* the invoice discount was split refunded the line
  without it. Those rows were not rewritten (history), so an old, partly
  returned, discounted invoice can have been over-refunded.
- The dashboard screens, label printing, the till's day list, the USB scanner
  listener (`till/wedgeScanner.js`) and the name search have been built but
  never walked through in a browser by a person; the tests cover the API only.
- The platform panel has no admin management screens and no reports. A
  subscription's `ends_at` is displayed and reported (`expiring`/`expired`) but
  enforced nowhere, on purpose: an ended subscription keeps selling.
- No partial or mixed payments: one `payment_method` per invoice.

Then phase 2: offline mode and the desktop app.
