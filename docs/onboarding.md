# Taking on a customer

There is nothing to install at the shop.

Scanzo is hosted: the data lives on our server, and the shop reaches it over
the internet. What the seller's phone and the shop's computer keep locally is a
copy for working through an outage, not a server — see
[decision 8](decisions.md#8-offline-selling-designed-for-now-and-built-in-phase-two)
and [decision 14](decisions.md#14-everything-a-shop-touches-is-a-json-api-only-the-platform-panel-is-not).

So a visit is onboarding, not setup. No Docker, no terminal, no server in the
back room.

## Why no server at the shop

Besides the obvious costs — visiting every shop for every fix, backups nobody
takes, support with no sight of the logs — there is one thing that settles it.

**The camera only works over HTTPS or on localhost.** A seller's phone opening
`http://192.168.1.5` on the shop's wifi is neither, so the browser refuses the
camera and scanning, the whole point of the product, does not work. Fixing that
per shop means certificates and a local domain.

Loading the till from our HTTPS site has none of that, and the phone keeps
working offline afterwards through the service worker and its local database.

## The visit

**Before you go**

1. Create the business, its first branch, the owner's account and the first
   till. One command does all of it:

   ```bash
   php artisan scanzo:onboard
   ```

   It asks for the shop name, the owner's name, their email and a password. The
   platform panel calls the same action, so nothing here goes stale.

2. Ask for their stock list in advance, in any shape, and get it imported
   before the visit. Entering a few hundred pieces in front of the customer is
   how a demo turns into an afternoon.

**At the shop**

3. Sign in as the owner and add the rest: branches, the sellers, and a till for
   each device that will sell. Every phone and computer that sells is its own
   till, because the till code is what keeps invoice numbers from colliding
   when two of them sell with no connection.
4. Print the labels and stick them on the hang tags, not the fabric.
5. On each seller's phone: open the site, sign in, then **Add to Home Screen**.
   It then opens like an app, full screen, and keeps working if the wifi drops.
6. On the shop's computer, if they want one: install the desktop app and sign
   in. That is a normal installer, and it is what gives them receipt printing
   straight to a thermal printer and a USB scanner.

**Before you leave**

7. Sell one real item in front of them, then return it. Those two things are
   what they will do all day, and watching a seller do it is the only way to
   find out whether the screen makes sense to them.
8. Write down who to call, and set the trial's end date in their calendar.

## What they need

| | |
|---|---|
| A phone per seller | Any Android or iPhone with a working camera |
| Internet | Normal shop wifi or mobile data. An outage stops new products and reports, not selling |
| A label printer | A Bluetooth label printer is the cheap, practical one. Otherwise sticker sheets on any printer, or a print shop |
| A computer | Optional. Worth it for thermal receipts and a USB scanner |

## What usually goes wrong

- **A phone that was never signed in while online** has nothing cached and
  cannot sell. Sign every device in during the visit, not afterwards.
- **A till that has not reached the server for longer than the subscription's
  grace period stops selling.** That is deliberate, and it is worth saying out
  loud during onboarding rather than discovering together.
- **Labels on the fabric** come off or get sold with the garment. The hang tag
  is the place.
- **Two sellers sharing one phone** is fine, but the sales are then recorded
  against whoever is signed in. If they care who sold what, each seller needs
  their own sign-in.
