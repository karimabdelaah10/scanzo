# Roadmap

Three phases. The first proves the system in one real shop; the second makes it
sellable; the third is growth.

---

## Phase 1 — MVP, one real shop

The goal is a shop selling with it every day, not a feature list.

- **Tenancy**: businesses, branches, users, roles, devices, platform admin panel
- **Catalogue**: products, variants, prices, stock entry
- **Labels**: QR + barcode, PDF for label rolls and A4 sheets
- **Selling**: scan on phone or USB scanner, cart, discount, invoice, receipt
- **Returns and exchanges**: against an invoice, with the return window enforced
- **Admin panel (ours)**: add a business, activate, suspend, see usage
- **Subscriptions**: recorded in the database, activated by hand after payment

Everything online. No sync engine yet.

### Done when

A shop has run a full week on it, including a stock take and a day with returns,
without anyone opening a spreadsheet.

---

## Phase 2 — ready to sell to other businesses

Driven by what the pilot shop complains about, plus the things no second
customer will do without.

- **Offline mode**: local database, queued invoices, sync and conflict handling
- **Desktop app**: Electron or Tauri, thermal receipt printing
- **Import from Excel**: nobody types in a catalogue of hundreds of pieces by hand
- **Reports**: daily sales, best sellers, low stock, per seller, per branch
- **Stock take**: scan the shelf, see the differences, post the adjustment
- **Transfers between branches**

### Done when

A business we have never met can sign up, import its catalogue, print labels and
sell — without us doing anything by hand.

---

## Phase 3 — growth

- Online subscription payment and automatic renewal
- Customers, loyalty, store credit
- Promotions and price rules
- Egyptian e-invoicing, if the customers need it
- Public API and integrations

---

## Open questions

- **E-invoicing**: needed only once a registered business asks. It shapes the
  receipt and the invoice fields, so decide before the receipt layout is final.
- **Label size**: pick the default roll size with the pilot shop. Clothing
  usually means a sticker on the hang tag, not on the fabric.
- **Scan behaviour**: does scanning add the item straight to the cart, or show
  it first? Shipped as a per-business setting; the pilot decides the default.
