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
- `checkout.html` — checkout page (buyer info form + order bump for the
  video add-on + order summary). Every "Dapatkan E-book" button on
  `index.html`/`index-b.html` links here.
- `create-purchase.php` — server-side endpoint that `checkout.html` calls
  to create a Chip Collect purchase and get a `checkout_url` to redirect
  the customer to. Requires PHP + the `curl` extension (standard on
  cPanel shared hosting). The Chip secret key is never sent to the
  browser — it only ever exists inside this server-side request.
- `chip-config.example.php` — template for the Chip credentials file.
  **Not** the real credentials — see "Payment gateway setup" below.

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

Instead, deliver the PDF through your payment gateway's own delivery
system (most Malaysian gateways — e.g. SenangPay, ToyyibPay, Payhip-style
checkouts — can attach a digital file to the product and email it, or show
a one-time/expiring download link on their own hosted success page), or
host the file in a private/non-web-accessible location and generate signed
or expiring links. The `terima-kasih.html` download button and the "resend
via WhatsApp" flow both assume the real PDF link lives outside
`public_html`.

## ⚠️ Payment gateway setup (Chip) — do this before checkout.html works

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

Since a Chip secret key was shared in this conversation to get the
integration built and tested, treat that key as a session-scoped secret
in the transcript — it's stored server-side only, never committed to
git, but if you want extra peace of mind, you can regenerate it in the
Chip dashboard afterward and update `chip-config.php` with the new one.

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
   - `checkout.html`
   - `create-purchase.php`
   - `.htaccess` (File Manager may hide dotfiles by default — enable
     **Settings → Show Hidden Files (dotfiles)** in the top-right of File
     Manager before uploading/checking this one)
4. Do **not** upload the ebook PDF here. See the warning above.
5. Once uploaded, visit `https://yourdomain.my/` and confirm the page
   loads, the hero readout animates on scroll, and both `terma.html` and
   `terima-kasih.html` load correctly.
6. If you're running the A/B test, point half your ad traffic at
   `/index-b.html` and half at `/` (or use your ad platform's own split
   testing / URL rotation to alternate between the two).

## Placeholders to replace before going live

Search each file for these and replace with real values:

| Placeholder | Found in | Replace with |
|---|---|---|
| ~~`RM47`~~ / ~~`#BELI`~~ | — | **Done.** All "Dapatkan E-book" buttons now link to `checkout.html`, which creates a real Chip Collect purchase via `create-purchase.php`. See "Payment gateway setup" above — `chip-config.php` still needs to exist on the server for this to actually work. |
| `60123456789` | `index.html`, `index-b.html`, `terma.html`, `terima-kasih.html`, `checkout.html` (WhatsApp `wa.me` links) | Real WhatsApp support number, e.g. `601XXXXXXXX` |
| `domain.my` | `<link rel="canonical">` and `og:*` tags in all 4 HTML files | Real live domain |
| `og-image.jpg` | `og:image` meta tag in `index.html` | Real 1200×630px social share image, uploaded to the same host |
| `TESTIMONI` block | `index.html` (commented out, near the bottom) | Real customer testimonials only — uncomment and fill in once you have genuine quotes. Do not fabricate names, quotes, buyer counts, or before/after results. |
| GA4 tracking code | `<head>` comment block in `index.html` and `terima-kasih.html` | Real GA4 measurement ID + snippet, if you want analytics |
| Refund policy text | `terma.html`, section 4 | Your actual refund policy (duration, conditions, process) |
| `[Nama Syarikat / Pemilik Perniagaan]` | `terma.html`, section 1 | Registered business/owner name |
| `[Nombor SSM]` | `terma.html`, section 1 | SSM registration number |
| `[emel@domain.my]` | `terma.html`, section 6 | Real support email |
| PDF download link | `terima-kasih.html` (the "Muat Turun PDF Sekarang" button `href`) | Real delivery link from your payment gateway or private file host — **not** a `public_html` path |

## What was fixed / built

- Built `index.html` from scratch (folder was empty — no prior file to
  iterate on).
- The animated BIA readout (hero) ticks weight 82.4 → 80.4kg on scroll
  into view, with water dropping sharply, muscle dropping slightly, and
  fat barely moving. It defaults to the **end state** in markup, so the
  page reads correctly with JavaScript disabled; JS resets to the start
  state on load and animates forward on scroll-into-view. It respects
  `prefers-reduced-motion` by skipping the animation entirely and showing
  the end state.
- Eligibility/warning section sits above the final CTA/pricing band, at
  full text size, unmodified in tone.
- No fabricated testimonials, buyer counts, ratings, or urgency timers.

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
      switch + BIA script early-returns)
- [x] Works with JavaScript disabled — BIA readout shows its end state by
      default in the HTML/CSS; FAQ uses native `<details>/<summary>`
      (needs no JS)
- [x] No console errors
- [x] `index.html` is ~19KB, well under the 60KB budget
