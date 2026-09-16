=== SendWay for WooCommerce ===
Contributors: fecntechnologies
Tags: woocommerce, shipping, delivery, kenya, courier
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Deliver anywhere in Kenya from your Nairobi shop. Live rates at checkout, a pickup-town picker for your customers, and parcels booked for you.

== Description ==

SendWay collects parcels from your shop in Nairobi and delivers them to pickup
points in towns across Kenya. This plugin puts that inside WooCommerce.

Your customer picks the town they will collect from, sees the real delivery
price for it, and pays once. When the order is ready, the parcel is booked
automatically — no copying addresses into another system.

= What it does =

* **Real rates at checkout.** Priced on the destination town and the weight of the cart, not a flat guess.
* **A town picker your customers understand.** They choose where they will collect, grouped by county.
* **Parcels booked automatically.** When an order reaches the status you choose, the parcel is created.
* **Tracking everywhere they look.** On the order page, in their account, and in the completed-order email.
* **Delivery updates without polling.** SendWay tells your shop when a parcel is collected, has arrived, or is picked up — and completes the order for you.
* **Pay-on-delivery reconciled.** When SendWay collects cash for you, the order note records what was collected, the fee, and what was credited to your wallet.

= Try it before it is real =

A test API key runs the whole flow — rates, checkout, booking — without a
parcel ever being collected or anything charged. Test orders are clearly
marked in admin so nobody confuses one for a real delivery.

== External services ==

This plugin connects to the SendWay API at `https://api.shopinn.co.ke` to price
and book parcels. It is required for the plugin to do anything.

Sent to SendWay when a quote is requested: the destination town, the weight of
the cart, and your shop's collection coordinates.

Sent when a parcel is booked: the recipient's name, phone number, email address
if you collect one, the destination town, the item description, the weight, and
the order total as a declared value.

SendWay receives no data from visitors who do not check out, and nothing is
sent to any other service.

Terms: https://sendway.co.ke/terms
Privacy: https://sendway.co.ke/privacy

== Installation ==

1. Install and activate the plugin.
2. Create a SendWay account at sendway.co.ke, then open **Developers** and create an API key.
3. In WooCommerce go to **Settings → Shipping → SendWay**, paste the key, and set your collection address.
4. Add **SendWay** as a shipping method in the shipping zone covering Kenya.
5. Copy the webhook URL shown on the settings screen, register it with SendWay, and paste the signing secret back in.

Start with a test key. Switch to a live key once you have placed a practice
order and are happy with it.

== Frequently Asked Questions ==

= Do I need a SendWay account? =

Yes. The plugin is a front end for the SendWay network — it needs an account to
price and book against. Test keys are self-service; live keys need your account
approved.

= What happens if SendWay is unreachable at checkout? =

No SendWay rate is offered and your other shipping methods carry on as normal.
Checkout is never blocked.

= Can a customer be charged one price and the parcel cost me another? =

The price shown comes from the same quote the booking uses, so they match. If
you add a handling fee it is added on top of the SendWay rate.

= Will one order ever be booked twice? =

No. Booking is keyed on the order id, so a retried status change or a second
press of the manual action replays the first result instead of creating another
parcel.

== Changelog ==

= 1.0.0 =
* First release: live rates, pickup-town picker, automatic booking, signed webhooks, and tracking on the order, in the account and in emails.
