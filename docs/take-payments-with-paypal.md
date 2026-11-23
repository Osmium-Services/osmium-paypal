---
title: Take payments with PayPal
tags: paypal, payments, checkout, webhook, sandbox, live
---

**PayPal** lets customers pay with PayPal at checkout. Cards are offered by PayPal as guest checkout when your PayPal account allows it.

## Set it up

1. In the PayPal developer dashboard, create an app and copy its **Client ID** and **Secret**, for both sandbox and live.
2. Open **PayPal Settings** and enter the **Sandbox Client ID**, **Sandbox Secret**, **Live Client ID** and **Live Secret**.
3. Add the webhook. The settings page shows the **Webhook endpoint** address to add in the dashboard, with the events `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED` and `PAYMENT.CAPTURE.REFUNDED`. Paste the **Webhook ID** it gives you.
4. Tick **Enable PayPal**. Turning it off later means orders in flight are no longer reconciled.
5. Set **PayPal Mode** to **Sandbox** while testing, then **Live**.
6. Press **Save Settings**.
7. In **Settings > Payments**, choose PayPal as the **Active Checkout Provider**.

## Other options

- **Accepted payment types** are shown as payment badges on the site.
- **Brand Name** is shown to the customer on PayPal's approval screen. It defaults to "Shop".
