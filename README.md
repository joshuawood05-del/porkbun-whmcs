# Porkbun Registrar Module for WHMCS

Registers and manages domains through the **Porkbun v3 API** (v3.31). Built as a
standard WHMCS registrar module, so it slots in alongside your other registrars
and can be assigned per-TLD.

## What it does

| Feature | Supported | Notes |
|---|---|---|
| Register | ✅ | 1-year term (registry minimum via API) |
| Transfer in | ✅ | Needs the EPP/auth code; **not** available for `.uk` via API |
| Renew | ✅ | 1-year per call; domain must be >30 days old |
| Get/Set nameservers | ✅ | |
| DNS host records | ✅ | Add/update always; delete is opt-in (see config) |
| Domain contacts | ✅ | Registrant change triggers the usual ICANN verification email |
| Expiry/status sync | ✅ | `Sync` and `TransferSync` |
| Webhooks (real-time) | ✅ | Optional; see below. Cuts transfer-completion lag from a day to a minute |
| Domain availability lookups | ✅ | Optional; registry-accurate, 25 TLDs per API call |
| Import TLD pricing | ✅ | Cost prices into WHMCS' own Import Pricing wizard |
| Retail pricing sync | ✅ | Optional; your markup applied to Porkbun's cost, written daily |
| EPP/auth code retrieval | ❌ | Porkbun has no API for this — get it from the dashboard |
| Registrar lock toggle | ❌ | Not exposed by the API |
| ID protection toggle | ❌ | Porkbun gives free WHOIS privacy; set at registration |
| Premium domains | ❌ | The API cannot register, renew or transfer them; lookups mark them unavailable rather than sell an order that will fail |

## Install

1. Copy the `porkbun` folder into your WHMCS installation at:
   ```
   /path-to-whmcs/modules/registrars/porkbun/
   ```
   The final layout should be:
   ```
   modules/registrars/porkbun/porkbun.php
   modules/registrars/porkbun/hooks.php
   modules/registrars/porkbun/webhook.php
   modules/registrars/porkbun/pricing.php
   modules/registrars/porkbun/lib/PorkbunApi.php
   modules/registrars/porkbun/lib/PorkbunPricing.php
   modules/registrars/porkbun/lib/PorkbunPricingSync.php
   modules/registrars/porkbun/README.md
   ```
2. In WHMCS go to **Configuration → System Settings → Domain Registrars**.
3. Find **Porkbun**, click **Activate**, then **Configure**.
4. Paste your **API Key** (`pk1_…`) and **Secret API Key** (`sk1_…`).
   Create keys at <https://porkbun.com/account/api>. Saving validates them.
5. Assign Porkbun to the TLDs you want under **Configuration → System Settings →
   Domain Pricing** (the *Auto Registration* column).

## Configuration options

- **API Key / Secret API Key** — your Porkbun credentials. A sandbox key
  (`pk1_sb_…`) works against the same host, so you can test end-to-end first.
- **Default Nameserver 1–4** — applied to a newly registered domain when the
  customer doesn't choose their own. Pre-filled with Porkbun's own nameservers
  (`curitiba/fortaleza/maceio/salvador.ns.porkbun.com`), which means the domain
  uses **Porkbun-hosted DNS** and you (or the customer) manage A/CNAME/MX/TXT
  records straight from the **DNS tab** in WHMCS. Replace all four with your own
  nameservers if you host DNS elsewhere — in that case A records are managed on
  your own DNS host, not in WHMCS. Customers can still change nameservers to
  anything they like from the domain's Nameservers page at any time.
- **Push customer to registrant** (default on) — after a successful
  registration, copies the WHMCS customer's details onto the domain contacts.
  Turn off to keep your Porkbun account contact as the registrant.
- **Full DNS sync (allow deletes)** (default off) — when on, saving DNS in WHMCS
  deletes any Porkbun records not present in the WHMCS list. Off is safer: it
  only adds and updates, never deletes.

The rest of the settings are pricing, and have their own section below.

## Before it will work (Porkbun account requirements)

These are Porkbun API rules, not module choices:

- Your Porkbun account **email and phone must be verified**.
- The account runs on **prepaid credit** — top it up (auto top-up is available)
  because registrations, renewals and transfers spend account balance.
- The API **cannot place your account's very first registration**. Do one
  registration manually in the dashboard once, then automation works.
- Domains must be **opted in to "API Access"** in Porkbun for manage/renew calls.
  Domains registered or transferred through the API are opted in automatically.

## Webhooks (optional, recommended)

Without webhooks WHMCS only learns about registrar-side changes when the domain
sync cron runs, so a transfer that completes overnight can leave the customer
looking at *Pending Transfer* for most of a day. With webhooks Porkbun pushes
the change and WHMCS picks it up in about a minute.

**Before you start**, Porkbun will only deliver to an `https://` URL on **port
443** whose hostname resolves to a **public** address. Private, loopback,
link-local and CGNAT targets are refused, and this is re-checked immediately
before *every* delivery, not just at registration. Many WHMCS installs also
block direct access to `/modules/` — confirm the URL loads in a browser (it
should answer `405 POST only`) before registering it.

1. Register the endpoint and get its signing secret:
   ```
   cd /path-to-whmcs/modules/registrars/porkbun
   php webhook.php --register https://billing.example.com/modules/registrars/porkbun/webhook.php
   ```
   Re-running this is safe: it reuses an endpoint already pointing at that URL,
   and re-enables it if Porkbun had disabled it.
2. Paste the printed secret into **Webhook Signing Secret** on the registrar
   config page and save. An empty field disables the receiver.
3. Confirm the round trip with `php webhook.php --test`, then look for
   *Webhook Test* in **Configuration → System Logs → Module Log**.

Other commands: `--list` (endpoints, subscriptions and status) and
`--disable <id>` (pause deliveries).

### What it does with each event

The endpoint subscribes to `*`, so new Porkbun event types arrive automatically;
anything without a handler is logged and acknowledged.

| Event | Action |
|---|---|
| `domain.transfer.completed` | Clears *Pending Transfer* to *Active* and writes the new expiry |
| `domain.renewed` | Updates the expiry — including for renewals WHMCS did not initiate, such as Porkbun auto-renew |
| `domain.registered` | Updates the expiry; if no WHMCS record matches, raises an alert (a domain on the account nobody is billed for) |
| `domain.expiring` | Compares against WHMCS and flags a disagreement. Does not act — WHMCS sends its own renewal notices |
| `dns.record.*`, everything else | Logged only |

Three things worth knowing about how it behaves:

- **It verifies, then re-checks.** Every handler that changes WHMCS re-reads the
  domain from the API first. Only the event envelope is documented as stable, so
  nothing that touches a customer record rests on a payload field.
- **It never touches billing.** A renewal Porkbun performed may have no matching
  WHMCS invoice. The expiry date is corrected and an entry is written to the
  activity log; whether to invoice stays a human decision.
- **Deliveries are deduplicated** on the event id (Porkbun may send one twice,
  and a manual resend reuses the id) in `mod_porkbun_webhook_events`, created
  automatically and pruned after 30 days. A delivery that fails mid-processing
  releases its claim so Porkbun's retry can pick it up.

**Keep the domain sync cron enabled.** Webhooks give you speed, not certainty —
an endpoint that fails 20 deliveries in a row is disabled at Porkbun's end, and
the cron is what notices and reconciles.

## Pricing

Porkbun has no reseller tier, so the prices it quotes are what **your** account
pays. Everything a customer is charged is that cost plus a markup, and there are
two ways to get there.

### One-off: WHMCS' Import Pricing wizard

**Configuration → System Settings → Domain Pricing → Import Pricing**, pick
Porkbun. The module hands over Porkbun's cost prices and the wizard is where you
set the margin. Good for the initial setup, and for a look at what a TLD costs.

Handshake TLDs are left out (see below), and prices come across in the currency
set by **Pricing currency**.

### Ongoing: the retail pricing sync

The wizard is a manual job, and Porkbun's prices move when the registries move
theirs. The sync applies your markup rules to Porkbun's *current* cost and
writes the result straight into Domain Pricing, so a registry price rise reaches
your price list before it reaches your margin.

Preview it — this changes nothing:

```
cd /path-to-whmcs/modules/registrars/porkbun
php pricing.php
```

```
Preview (nothing written) — prices in GBP, converted from USD at 0.79
Terms: 1 year only (years 2-10 switched off)

TLD              ACTION     COST (reg/renew/xfer)    RETAIL (reg/renew/xfer)  NOTE
-----------------------------------------------------------------------------------
.com             update     8.75 / 8.75 / 8.75       11.99 / 11.99 / 11.99
.co.uk           update     3.41 / 4.47 / 0.00       5.99 / 6.99 / -
.app             create     6.91 / 11.79 / 11.79     9.99 / 15.99 / 15.99
.net             skip       - / - / -                - / - / -                assigned to the enom registrar in WHMCS
```

Then `php pricing.php --apply` to write it. Other options: `--tld=.com,.dev`
to limit the run, `--all` to list the skipped TLDs too.

Once the preview looks right, tick **Sync retail pricing daily** and the same
run happens on the daily cron, logging a summary to the activity log and every
row to the module log.

> `hooks.php` is what schedules that daily run, and WHMCS only looks for it when
> the registrar is activated or saved. After installing or updating the module,
> open **Domain Registrars → Porkbun** and click **Save Changes** even if you
> changed nothing.

### Markup settings

- **Markup %** (default 25) — added to cost. A $11.08 .com becomes $13.85.
- **Fixed uplift** — a flat amount added after the percentage.
- **Minimum margin** — a floor on the margin per year. Worth setting. Porkbun's
  first-year registration prices are promotional and often tiny — .xyz is $2.04
  to register and $14.21 to renew — and 25% of $2.04 is 51 cents, which will not
  cover the support ticket that follows the sale.
- **Round prices to** — exact, next whole number, next `.95` or next `.99`.
  Always upwards, so rounding never eats the margin.
- **Per-TLD markup rules** — exceptions, one per line. The most specific line
  wins outright; it is not merged with the global settings.

  ```
  .com            40%             # 40% on everything .com
  .co.uk          60% +0.50       # 60%, then 50p on top
  .io:renew       20% min 8.00    # renewals only, never under 8.00 margin
  .dev            flat 14.99      # fixed retail price, cost ignored
  .xyz:transfer   skip            # leave that price alone in WHMCS
  *:transfer      15% round .95   # every TLD, transfers only
  ```

  Operations are `register`, `renew` and `transfer`. `#` starts a comment.
  A line that cannot be parsed blocks the save with the line number, so the
  cron never runs a rule you did not mean.

### Scope and currency

- **Which TLDs to price** (default: only TLDs already assigned to Porkbun) —
  or a list you supply, or all ~640 TLDs Porkbun sells. Whichever you pick, a
  TLD already pointed at a different registrar is skipped and reported, never
  taken over.
- **Pricing currency** (default USD) — leave as USD to pass Porkbun's own
  figures to WHMCS and let it convert into your other currencies; that needs
  USD to exist under **Configuration → System Settings → Currencies**. Set your
  own currency instead (e.g. `GBP`) and the module converts first, using the
  exchange rates already in WHMCS.
- **USD conversion rate** — only read when the pricing currency is not USD.
  Fill this in if USD is not one of your configured currencies at all.
- **Registration terms** (default 1 year only) — the API registers and renews
  the registry minimum, one year, per call. A customer who buys three years gets
  one year and a note in the module log. The default switches years 2–10 off in
  WHMCS for the TLDs in scope so that cannot be sold; choose *leave other terms
  as they are* if you would rather handle the extra years by hand.
- **Include Handshake TLDs** (default off) — about 270 of Porkbun's ~910
  extensions are Handshake blockchain names (`.0z`, `.420247`, `.aotearoa`),
  which resolve only behind a Handshake resolver. They are left out of both
  pricing paths unless you tick this.

### Two things the sync deliberately does not do

- **It never touches anything but pricing.** ID protection, DNS management and
  email forwarding are admin decisions; a price sync has no business changing
  them. Only when it introduces a TLD does it also set the registrar to Porkbun
  and mark an EPP code as required for transfers.
- **It leaves a 0.00 transfer price alone.** Porkbun quotes `0.00` for transfers
  it does not price, `.uk` among them — which the API cannot transfer at all.
  That is not the same as a free transfer, so whatever WHMCS already has is kept
  rather than publishing a zero.

## Domain availability lookups (optional)

The module can answer the domain search itself, asking the registries through
Porkbun rather than relying on WHOIS. Turn it on under **Configuration → System
Settings → Domain Pricing → Lookup Provider** by choosing Porkbun.

- Lookups go out **25 domains per API call**, which has its own budget — 200
  domains a minute — against 10 checks per 10 seconds for one-at-a-time checks.
  A search across 30 TLDs is two calls, not 30.
- **Premium domains are shown as unavailable.** Porkbun's API refuses to
  register, renew or transfer them, so quoting a price would sell an order that
  cannot be fulfilled. Registration and transfer refuse them too, before the
  customer is charged. Sell those from the Porkbun dashboard instead.
- A domain the registry did not answer for is retried once and then **left out
  of the results**, because "no answer" is not "taken". A TLD Porkbun cannot
  check at all is reported as unsupported.
- Prices in the search results still come from your WHMCS TLD pricing — which is
  what the sync above keeps current.

## Things worth knowing

- **1-year terms only.** The API registers/renews the registry-minimum term
  (usually 1 year) per call. If a customer orders multiple years, the domain is
  registered for 1 year and a note is written to the module log. See
  *Registration terms* under Pricing for how to stop WHMCS offering the years
  the module cannot deliver.
- **Wrong-price protection.** Porkbun rejects any register/renew/transfer whose
  cost doesn't match its current price, so a pricing mismatch fails loudly
  rather than charging the wrong amount.
- **Phone numbers have two fields.** The contact form shows *Phone Country
  Code* (digits only, e.g. `44`) separately from *Phone*, because that is how
  Porkbun stores and validates them — it rejects a contact update outright with
  `a valid phoneCountryCode (1-999) is required` if the code is missing. Enter
  the national number in *Phone*. If a registry rejects the number itself, try
  it without the national trunk prefix (`2071234567` rather than `02071234567`);
  most registries want it that way, though a few, Italy among them, do not.
- **Retries can't double-charge.** Registrations, renewals and transfers send
  an `Idempotency-Key`. If WHMCS retries after a timeout, Porkbun replays the
  original response for 24 hours instead of charging the account again. One
  consequence worth knowing: two deliberate renewals of the same domain on the
  same day collapse into a single charge.
- **Errors explain themselves.** Porkbun returns a machine-readable code and a
  remediation hint with each failure, and both are passed through to the WHMCS
  admin — so a failure reads "Insufficient funds. Top up account credit at
  porkbun.com. [INSUFFICIENT_FUNDS · request 01a0…]" rather than just "error".
  Quote that request ID to Porkbun support.
- **Rate limits.** Roughly 1 registration/renewal attempt per 10 seconds per
  account (Porkbun default, adjustable per key). Fine for typical volumes.
  Renewal prices come from the TLD price list rather than a per-domain lookup,
  so a bulk renewal run makes one pricing call instead of one per domain. For
  the same reason renewals have no up-front premium check the way registrations
  and transfers do — Porkbun refuses a premium renewal by name, and catching it
  here would cost a rate-limited lookup per domain.
- **DNS TTLs are kept.** The WHMCS DNS tab has no TTL column, so editing a
  record keeps the TTL it already had at Porkbun instead of resetting it.
- **`.uk` transfers** can't be initiated through the API — do those in the
  dashboard. Registration and management of `.uk` still work.
- **EPP codes.** When a customer wants to transfer away, fetch the auth code
  from Porkbun: *Domain Management → the domain → Get Authorization Code*.

## Logs & troubleshooting

Every API call is written to **Configuration → System Logs → Module Log**
(API key and secret are redacted). If an action fails, the Porkbun error message
is passed straight through to the WHMCS admin, which is usually enough to see
what needs fixing (unverified account, insufficient credit, domain not opted in
to API access, etc.).

## Compatibility

- WHMCS 8.x, PHP 7.2–8.3, cURL enabled (all standard on a normal WHMCS host).
- No Composer dependencies; the module is self-contained.

## Licence

MIT — do what you like with it.
