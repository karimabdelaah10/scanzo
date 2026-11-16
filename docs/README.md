# Scanzo — documentation

Scanzo is a multi-tenant point-of-sale system sold as a subscription to
independent businesses (clothing shops first, but nothing in the model is
specific to clothing).

Each item in the catalogue carries a printed QR label. A seller scans it with a
phone camera or a USB scanner, the item drops into the cart, and the sale is
closed as an invoice. Stock is kept per branch, and returns and exchanges are
part of the day-to-day flow.

## Who uses it

| Audience | Where | What they do |
|---|---|---|
| Platform admin (us) | admin panel | add businesses, activate and suspend them, follow subscriptions and usage |
| Business owner | business dashboard | catalogue, stock, pricing, reports, settings, users |
| Branch manager | business dashboard | stock and invoices for their branch, discounts, returns |
| Seller | phone / desktop app | scan, sell, return |

## Contents

- [onboarding.md](onboarding.md) — what taking on a customer actually involves
- [schema.md](schema.md) — tables, columns and relationships
- [decisions.md](decisions.md) — the architectural decisions and why they were made
- [roadmap.md](roadmap.md) — what ships in which phase

## Vocabulary

A **business** (`businesses`, النشاط التجاري) is the tenant: one subscriber. It
owns **branches**, **users**, **devices** and a catalogue. A **product** is a
model ("linen shirt"); a **variant** is the thing that actually has a price, a
barcode and a stock level ("linen shirt, L, blue"). Labels, scanning and stock
all work at the variant level.
