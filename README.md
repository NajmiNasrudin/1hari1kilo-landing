# Cheat Code 1 Hari 1KG — Landing Page

Single-page sales site for the ebook, built for cPanel shared hosting.
No build step. Every page is a single self-contained HTML file with inline
CSS and JS. The only external request is Google Fonts.

## Files

- `index.html` — main landing page (Variant A: mechanism-led hero)
- `index-b.html` — A/B variant (Variant B: situational hero). Identical to
  `index.html` below the hero. Set to `noindex, nofollow` — it's a test
  page, not meant to be indexed by search engines.
- `terma.html` — Terms & refund policy
- `terima-kasih.html` — post-payment thank-you / download page
- `.htaccess` — Apache config for cPanel
- `coach-cem.jpg` — Coach Cem's photo, used in the "Siapa Coach Cem" section
  of `index.html` and `index-b.html` (560×841, ~29KB — already resized and
  compressed for web)
- `ebook-cover.jpg` — e-book cover mockup, shown in the final pricing/CTA
  section of `index.html` and `index-b.html` (400×570, ~58KB — already
  resized and compressed for web)
- `og-image.jpg` — social share preview image (1200×630) referenced by the
  `og:image`/`twitter:image` tags in `index.html` and `index-b.html`, so a
  link preview shows up when the page is shared on Facebook, WhatsApp,
  Twitter/X, etc.
- `checkout.html` — checkout page (buyer info form + order bump for the
  video add-on + order summary). Every "Dapatkan E-book" button on
  `index.html`/`index-b.html` links here.
- `create-purchase.php` — server-side endpoint that `checkout.html` calls
  to create a Chip Collect purchase and get a `checkout_url` to redirect
  the customer to. Requires PHP + the `curl` extension (standard on
  cPanel shared hosting). The Chip secret key is never sent to the
  browser — it only ever exists inside this server-side request.
- `chip-config.example.php` — template for the Chip credentials file.
  **Not** the real credentials — see "Payment gateway & email setup" below.
- `brevo-config.example.php` — template for the Brevo (transactional
  email) credentials file. **Not** the real credentials.
- `chip-callback.php` — server-to-server webhook Chip calls when a
  purchase is paid. Verifies the request is genuinely from Chip, then
  emails the customer their download link(s) via Brevo. See "Automatic delivery
  email" below.

**Note on page weight:** the original 60KB budget was for the HTML document
itself, before any product photos existed. With both images added, total
page weight (HTML + both photos) is roughly 108KB — still light by general
web standards, and both images use `loading="lazy"` so the initial/critical
render only pulls in the ~20KB HTML + fonts. If acik wants to get back
under a strict 60KB *total*, the ebook cover (`ebook-cover.jpg`, the bigger
of the two) is the one to shrink or drop first.

## ⚠️ Where does the ebook PDF go?

**Never upload the PDF into `public_html`.** Anything in `public_html` is
publicly reachable by guessing/finding the URL, even if it's not linked
from anywhere — there is no real access control on a plain file sitting in
a shared-hosting web root.

The PDF and the video add-on both live on Google Drive (see "Automatic
delivery email" below for the actual links), shared as "Anyone with the
link can view" — not uploaded to this server at all. The
`terima-kasih.html` page also links directly to the Drive files as a
backup in case the email doesn't arrive.

**Note:** a public "anyone with the link" Google Drive file isn't
access-controlled — anyone who gets hold of the link (forwarded,
screenshotted, leaked) can access it. That's an accepted tradeoff for
simplicity here, not a hard security boundary. If that becomes a problem
later (e.g. the link gets shared publicly), the fix is to swap it for a
signed/expiring download link from a proper file host, and only that one
link (in `chip-callback.php` and `terima-kasih.html`) needs to change.

## ✉️ Automatic delivery email — how it works

When a customer completes payment, Chip calls `chip-callback.php`
server-to-server (a "success callback") with the paid purchase details.
That script:

1. **Verifies the request is genuinely from Chip** — checks the
   `X-Signature` header against Chip's public key (fetched live from
   their API) using RSA/SHA-256. Anyone could otherwise POST a fake
   "paid" event to this URL, so this check is not optional.
2. Confirms the purchase `status` is `"paid"`.
3. Checks whether the video add-on (RM77 line item) was purchased.
4. Emails the customer (their `client.email` from checkout) via
   **Brevo's transactional email API** — the e-book link always, the
   video folder link only if they bought the add-on. (PHP's built-in
   `mail()` was tried first — it silently failed on this host, since
   Spaceship routes outbound mail through its own "Spacemail" product
   rather than the standard sendmail path `mail()` expects. Brevo
   sidesteps that entirely and is more reliable anyway.)
5. Records the purchase ID in `sent-purchases.php` (auto-created on the
   server, gitignored, not part of the repo) so a retried webhook from
   Chip never sends the same customer a duplicate email.

**Download links** are hardcoded near the top of `chip-callback.php` as
`$EBOOK_LINK` and `$VIDEO_BUMP_LINK`. Update those two lines (and the
matching links in `terima-kasih.html`) if the files ever move.

**Brevo setup:** credentials live in `brevo-config.php` (gitignored, same
pattern as `chip-config.php` — see "Payment gateway setup" below, the
same rules apply: create it directly on the server, never commit it).
Get the API key from Brevo dashboard → Settings → SMTP & API → the
**"API Keys"** tab specifically (not "SMTP" — that's a different
credential and won't work here; it must start with `xkeysib-`). The
sender email (`noreply@coachcem.com` by default) must be a verified
sender in Brevo before emails will send — check Brevo's dashboard if
emails stop going out. Brevo's free tier covers 300 emails/day, which is
almost certainly enough for this product.

## ⚠️ Payment gateway & email setup (Chip + Brevo) — do this before checkout.html works

`checkout.html` calls `create-purchase.php`, which needs a Chip Collect
secret key and brand ID to create purchases. Those credentials live in
**`chip-config.php`**, a file that is deliberately **not** part of this
repo and never will be — it's listed in `.gitignore`. This repo is
public on GitHub; anything committed to it (even briefly, even if deleted
in a later commit) is permanently visible in git history and gets
scraped by bots within minutes. A payment gateway secret key must never
touch git.

Instead, create `chip-config.php` **directly on the server** via cPanel
File Manager, in the same folder as `create-purchase.php`
(`coachcem.com/1hari1/`):

1. cPanel → **File Manager** → navigate to `coachcem.com/1hari1/`
2. **+ File** → name it `chip-config.php` → Create
3. Edit it and paste (with your real values):
   ```php
   <?php
   return array(
       'secret_key' => 'YOUR_CHIP_SECRET_KEY',
       'brand_id'   => 'YOUR_CHIP_BRAND_ID',
   );
   ```
4. Save. This file is never touched by `.cpanel.yml` deploys (it copies
   the tracked repo files only), so it persists across every future
   redeploy without needing to be re-created.

Repeat the exact same steps for **`brevo-config.php`** in the same
folder (content template in `brevo-config.example.php`) — the email
delivery system needs both files to exist before `chip-callback.php`
will work.

Since a Chip secret key and a Brevo API key were both shared in this
conversation to get the integration built and tested, treat them as
session-scoped secrets in the transcript — they're stored server-side
only, never committed to git, but if you want extra peace of mind, you
can regenerate either one afterward (Chip dashboard / Brevo dashboard)
and update the matching config file with the new value.

## Deploying to cPanel (File Manager)

1. Log in to cPanel → **File Manager**.
2. Open `public_html` (this is your site's web root — if you're deploying
   to a subdomain or addon domain, open that domain's document root
   instead).
3. Click **Upload**, and upload these files individually (do not upload
   as a zip unless you then use File Manager's **Extract** and move the
   contents up one level — files must end up directly inside
   `public_html`, not inside a subfolder):
   - `index.html`
   - `index-b.html`
   - `terma.html`
   - `terima-kasih.html`
   - `coach-cem.jpg`
   - `ebook-cover.jpg`
   - `og-image.jpg`
   - `checkout.html`
   - `create-purchase.php`
   - `chip-callback.php`
   - `.htaccess` (File Manager may hide dotfiles by default — enable
     **Settings → Show Hidden Files (dotfiles)** in the top-right of File
     Manager before uploading/checking this one)
4. Do **not** upload the ebook PDF here. See the warning above.
5. Once uploaded, visit `https://yourdomain.my/` and confirm the page
   loads correctly, and both `terma.html` and `terima-kasih.html` do too.
6. If you're running the A/B test, point half your ad traffic at
   `/index-b.html` and half at `/` (or use your ad platform's own split
   testing / URL rotation to alternate between the two).

## Placeholders to replace before going live

Search each file for these and replace with real values:

| Placeholder | Found in | Replace with |
|---|---|---|
| ~~`RM47`~~ / ~~`#BELI`~~ | — | **Done.** All "Dapatkan E-book" buttons now link to `checkout.html`, which creates a real Chip Collect purchase via `create-purchase.php`. See "Payment gateway setup" above — `chip-config.php` still needs to exist on the server for this to actually work. |
| `60123456789` | `index.html`, `index-b.html`, `terma.html`, `terima-kasih.html`, `checkout.html` (WhatsApp `wa.me` links) | Real WhatsApp support number, e.g. `601XXXXXXXX` |
| ~~`domain.my`~~ / ~~`og-image.jpg`~~ | — | **Done.** `index.html`/`index-b.html` canonical, `og:*` and `twitter:*` tags now point to `https://coachcem.com/1hari1/`, and `og-image.jpg` (1200×630, generated from the brand system + ebook cover) is deployed alongside the site. If the domain or `/1hari1` path ever changes, update these tags to match. |
| `TESTIMONI` block | `index.html` (commented out, near the bottom) | Real customer testimonials only — uncomment and fill in once you have genuine quotes. Do not fabricate names, quotes, buyer counts, or before/after results. |
| GA4 tracking code | `<head>` comment block in `index.html` and `terima-kasih.html` | Real GA4 measurement ID + snippet, if you want analytics |
| Refund policy text | `terma.html`, section 4 | Your actual refund policy (duration, conditions, process) |
| `[Nama Syarikat / Pemilik Perniagaan]` | `terma.html`, section 1 | Registered business/owner name |
| `[Nombor SSM]` | `terma.html`, section 1 | SSM registration number |
| `[emel@domain.my]` | `terma.html`, section 6 | Real support email |
| ~~PDF download link~~ | — | **Done.** `terima-kasih.html` and `chip-callback.php` both point at the real Google Drive links. See "Automatic delivery email" above if these ever need to change. |

## What was fixed / built

- Built `index.html` from scratch (folder was empty — no prior file to
  iterate on).
- Eligibility/warning section sits above the final CTA/pricing band, at
  full text size, unmodified in tone.
- No fabricated testimonials, buyer counts, ratings, or urgency timers.

**Note:** the hero originally had an animated BIA (InBody) readout
simulation as its signature visual, explained by a "why the scale
misleads you" thesis section further down the page. Both were later
removed as the page's positioning shifted toward BMI-requirement use
cases (screening tests, interviews, medical card applications) instead
— `index.html`/`index-b.html` no longer contain that JS/CSS at all, and
the page has no JavaScript-driven functionality of its own anymore
(only `checkout.html` does, for the payment flow).

## Acceptance checklist

- [x] Valid HTML, all tags balanced
- [x] Renders at 360px / 768px / 1280px (mobile-first CSS, single
      `max-width: 760px` column, no fixed widths below that)
- [x] All interactive elements (links, buttons, `<details>`) are native
      focusable elements with a visible `:focus` outline (ink on light
      bands, volt yellow on dark bands)
- [x] Body text contrast passes WCAG AA on every band (verified: slate
      `#5A6570` on paper `#EDEFF0` ≈ 5.15:1; mist `#A7B0B8` on ink
      `#0B0B0C` ≈ 8.9:1; ink on volt CTA buttons ≈ 13:1)
- [x] `prefers-reduced-motion` honoured (global transition/animation kill
      switch)
- [x] Works with JavaScript disabled — `index.html`/`index-b.html` have no
      JS at all now; FAQ uses native `<details>/<summary>` (needs no JS)
- [x] No console errors
- [x] `index.html` is ~16KB, well under the 60KB budget
