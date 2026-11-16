# Task: verify and fix phase 1

Read `CLAUDE.md` and `docs/decisions.md` before starting.

Everything described below is written but **nothing has been run**. The test
suite has never been executed, the till has never been opened in a browser, and
the label printer page has never rendered. Your job is to find out what is
broken and fix it — not to add features.

Work in this order, because each step unblocks the next.

---

## 1. Permissions

```bash
sudo ./scripts/fix_host_permissions.sh     # from the repo root, NOT in the container
```

`core/` is bind-mounted, so host permissions decide what php-fpm can read. The
script also installs default ACLs so this does not recur. If `setfacl` is
missing it says so; install `acl` and run it again.

## 2. The packages label printing needs

```bash
docker exec -it scanzo-app composer require bacon/bacon-qr-code picqer/php-barcode-generator
```

Both are pure PHP. `app/Support/Labels/CodeImage.php` supports both the v2 and
v3 APIs of the picqer package, so whichever resolves should work — confirm
which one landed and, if only one API is real, delete the dead branch.

## 3. The test suite

```bash
docker exec -it scanzo-app php artisan test
```

77 feature tests across 10 files. Expect failures: they were written without
ever being run.

When one fails, decide which is wrong — the test or the code — and say which
you chose and why. Do not weaken a test to make it pass. The tests encode the
rules in `docs/schema.md`; if a rule turns out to be wrong, change the rule in
the doc too.

The tests run against the `mysql_test` service, not sqlite. If the connection
fails, check that service is up.

## 4. Seed and sign in

```bash
docker exec -it scanzo-app php artisan db:seed
```

Then confirm `manager@demo-shop.local` / `password` can sign in at
<http://127.0.0.1:49100/till>. A login that fails with "These details do not
match our records" almost always means the seeder has not run.

## 5. Build the front end

```bash
cd core && npm run build
```

`@vitejs/plugin-vue` must be v6 for Vite 7.

## 6. Walk the till

Open `/till` and go through this, fixing whatever breaks:

1. Sign in; pick a branch and a till. It should remember both next time.
2. Type `10001` in the code box — `SKU-` is a fixed prefix, digits only.
3. Add to the cart, set a discount, save. The invoice number should be
   `A1-000002` or later (the seeder wrote `A1-000001`).
4. Check the stock went down: `/till-harness` has a button that lists the
   branch's codes with quantities.
5. Try to sell more than the shelf holds. The specific line should be marked,
   not the whole sale generically failed.
6. From the selling screen press **مرتجع**, enter an invoice number, pick a
   line, refund cash. Check `returned_quantity` went up and the piece came
   back to stock.
7. Mark a returned piece **تالفة**: the money should come back but the stock
   should not.
8. Do an exchange: return a line, press **استبدال بقطعة تانية**, scan a
   replacement, confirm. Both documents should be linked, and the screen should
   say whether the customer pays or receives the difference.

The camera will not work unless the page is on `localhost` or HTTPS — that is
expected and the screen says so. Use manual entry for this walkthrough.

`/till-harness` is a no-build page that drives the API directly; use it to tell
an API fault from a front-end one.

## 7. Commit

```bash
git add -A && git commit
```

---

## Rules

- **Do not change a decision in `docs/decisions.md` without saying so.** If one
  of them is wrong, say which and why rather than quietly doing it differently.
  They cover the tenant scoping, the stock ledger, ordered UUIDs, price
  snapshots, and returns being in the first version.
- New failure modes get a factory method on `App\Exceptions\DomainException`,
  not a bare `abort()`. The front end switches on `code`.
- If you touch the schema, update `docs/schema.md` in the same commit.

## Report back

Three lists: what was broken, what you fixed, and what is still not working.
