# Referrals mockup v2 — the client demo website

**Status: revised 20 Oct 2026 as the client demo site, awaiting Stephan's
approval. Updated 21 Oct with his answers (see "Decided"). Not approved, not
built.**

Stephan decided on 20 October that the public website is a demo of the
**client's own website**, not Oblivion Care marketing (see
`../AUDIT-2026-10-19.md`, "Decision — the public website is the client's demo
site"). It shows a provider what *their* site looks like and how everything
works end to end. Every page speaks as the provider: "we" is the provider.

- The fictional demo provider is **Oblivion Living**: logos in
  `public/oblivion-living-logo.svg` (light) and `-dark.svg` (dark), hero
  photo `public/demo/hero-home.jpg`, and one brand colour, teal `#0F766E`
  (the app works out the dark-mode shades itself).
- The footer keeps one small "Powered by oblivion care" line. Nothing else on
  the site mentions Oblivion Care.
- Contact-us messages go to referral intake as general enquiries, kept out of
  referral counts.

Mockup v1 (`../mockup/`) remains the approved reference for the staff
workspace, referral profile, reports, settings and the enquiry flow.

Built with the method in the mockup rules checklist: a Vite + React preview
that imports the real components (`@` → `resources/js`) and the real tokens
(`resources/css/app.css`, `journey-auth.css`). Fictional data only; no request
ever leaves the page.

## Run

Builds: build 1 and build 2 (21 Oct, with Stephan's answers) passed (Vite
7, 2,522 modules). Checked in the browser:

- Build 1: every route at 375 px has no horizontal scroll and no console
  errors; the pages were walked at 1440 px and in dark mode; the brand-slot
  states and colour presets were switched; Make a referral (discard
  confirm, success), Contact us (errors, success), Apply (errors jump back,
  success) and staff sign-in (email → password → demo notice) were run
  through.
- Build 2: `#/sign-in` and `#/sign-in/family` at 1440 px and 375 px, light
  and dark, and "No client branding" (islands fallback, with the back
  link). Every line of brand-half text was measured against the worst photo
  pixel under it, with the scrims composited: at least 8.7:1. The family
  "Check your email" panel, the teal (not pink) tile tint, the review bar
  under pop-ups at 375 px, the pop-up font, the new alt text and apply-error
  focus (to the first field, with its error linked) all check out.
- Fixed after build 2, waiting on build 3: the top scrim (the minifier
  merged two identical gradient stops into an invalid `300px100%`, which
  dropped the whole rule; it was checked with the corrected rule injected),
  44 px tap targets on "Back to website", the site lockup and the menu
  button (`min-h-11` is 38.5 px at the 14 px root), the family chips in a
  row over the photo, and tab titles.

The secure upload's file pick was not driven in the browser.

```bash
bash ~/.claude/heavy-lock.sh "referrals-mockup-v2 build" -- npx vite build --config docs/referrals/mockup-v2/vite.config.mjs
```

Then serve `dist/` (launch config `referrals-mockup-v2`,
http://127.0.0.1:5189).

## Routes

| Route | Page |
|---|---|
| `#/home` | Home: the v2 hero in the client's brand, with the home photo |
| `#/support` (`/home`, `/community`, `/respite`) | Our support: three services with photos, then a detail section each (the sub-route scrolls to it) |
| `#/funding` | Funding help: DSS via NASC, Individualised Funding, ACC, Health NZ, private; what we can and can't do; no promises |
| `#/about` | About us: values, whānau, the team, our commitments |
| `#/careers` | Careers: three open roles, why work with us, how applying works |
| `#/careers/<slug>` | Job detail. `…/apply` opens the apply wizard straight away |
| `#/careers/status` | The application status page the applicant's emailed link opens |
| `#/referral` (`/support`, `/referral`, `/funding`, `/callback`) | Make a referral: the enquiry flow (tile pickers, then `WizardShell`), with that option chosen. `#/enquiry` still works |
| `#/contact` | Contact us: the message form, office details and photo |
| `#/sign-in` · `#/sign-in/family` | The **real** staff and family sign-in forms, in a brand half that shows the client's hero photo, logo and welcome text, with "Back to website" at the top |
| `#/upload` | Secure upload |
| `#/privacy` · `#/terms` · `#/accessibility` | Footer pages with placeholder text marked for the client to replace |

The three role slugs are `support-worker-kapiti-coast`, `team-leader-porirua`
and `night-support-lower-hutt`.

## The review bar

Never part of the client's page.

- **Route links**, grouped Site / Flows / Footer.
- **How this works** opens a notes panel under the bar for the current page
  (below).
- **Toggle dark.**
- **Brand slot**: "Oblivion Living (demo)" (default), "Another client (Kōwhai
  Living)", "Name only" and "No client branding (today's fallback)". Every
  logo spot is the real `BrandLockup`, fed through the Inertia shim.
- **Brand colour**: Oblivion Living teal (default), Kōwhai gold, or the app
  default. It runs the real `applyPalette()` from `lib/derive-palette.ts`, the
  same code Settings › Branding uses, so `--primary`, `--accent`, `--ring` and
  the charts all follow. Each client has one colour, used in both modes; the
  app's tokens work out the dark-mode shades (brand text lifts to L ≥ 0.70,
  fills clamp to L ≤ 0.50).
- A **demo notice** strip appears when a real component would leave the demo
  (signing in, "Forgot password?"), saying what the real app does next.

## How this works (the panel's notes)

- **Make a referral:** creates a referral in the staff Referrals workspace,
  in the New queue, with an acknowledgement email if turned on in Settings ›
  Referrals. The person gets a reference straight away.
- **Contact us:** arrives in referral intake as a general enquiry, in its own
  queue, not counted as a referral. General enquiries get ENQ- references
  so staff can tell them apart from referrals (which keep their own
  format). Choosing "Ask about support", "Make a referral" or "A careers
  question" suggests the better form, without blocking the message.
- **Careers application:** goes to HR › Recruitment, and the applicant gets a
  status link.
- **Secure upload:** the file is held in quarantine until it is virus-scanned,
  then attached to the referral. The access code is sent separately.
- **Sign in:** staff reach their workspace; family and whānau reach the family
  portal. Email-first, with an emailed sign-in link. The brand half shows the
  client's hero photo and logo from Settings › Branding; the islands art is
  only Oblivion's own default.
- **Content and footer pages:** demo text for the client to replace.

## What to review

1. **One header, one footer, every site page.** Header: Our support · Funding
   help · About us · Careers · Contact us, then "Sign in" and the primary
   "Make a referral". The phone menu keeps the horizon line. The full nav
   shows from 1280 px (five links and two actions don't fit at 1024 px);
   below that it's the menu button. The sign-in pages are the exception: they
   keep the approved full-screen half-and-half layout, with no site header,
   and a "Back to website" link at the top instead.
2. **Home** — the v2 hero, now the client's: the islands mark is gone (it's
   Oblivion's artwork), and the journey pins ("A first conversation" →
   "Support that fits") sit on the home photo instead.
3. **Make a referral** — the header's primary call to action. The tiles keep
   "Ask about support" for people and whānau, "Make a professional referral"
   for agencies, "Help with funding" and "Request a callback". The wizard is
   the 1100 px width; closing part-way asks first (`ConfirmDialog`,
   destructive).
4. **Contact us** — name, email or phone, how to reply (tiles), what it's
   about (tiles that point to a better form), message, a "Safe to leave a
   message" Switch, the privacy caption and the 111 note. Send it empty for
   the errors. Success: "Thanks — our intake team has your message. We reply
   within two working days", with reference ENQ-2048.
5. **Careers** — job list, job detail, and Apply in the shared `WizardShell`:
   About you → Experience → Right to work (with the police vetting note) →
   Review and send. The fields are the real `CareersApply` set (first, last
   and preferred name, email, phone, how you heard, referral, cover letter,
   CV, screening answers, privacy consent), plus experience, right to work
   and driver licence as tiles. Send it empty to see it jump back to what's
   missing. The success state points to the status link; nothing promises a
   timeframe.
6. **Sign in** — the real `pages/auth/login.tsx` and `pages/portal/login.tsx`
   forms, rendered with fabricated props. The brand half (both staff and
   family) shows the client's hero photo under a scrim, their logo, the
   welcome text and "Powered by oblivion care", with "Back to website" at
   the top (on phones, where the brand half is hidden, the link sits at the
   top of the form). Over a photo the family headline reads "Kia ora,
   whānau. / Good to see you." (the "Separate islands" line belongs to the
   islands art). "No client branding" shows Oblivion's own islands default.
   Family: "Email me a sign-in link" shows the real "Check your email"
   panel. Staff: email, then password, then a demo notice. The journey
   animation only exists on the islands art, so it doesn't play over a
   photo.
7. **Secure upload** — now inside the site shell. Try code 000000 for the
   wrong-code message.
8. **Dark mode and the brand controls** on every page, at 1440 px and 375 px.

## Design notes

- **Brand slot, never a hard-coded logo**, and brand colour only through
  `--primary` and the tokens derived from it. The only colour values in the
  preview's code are the Settings › Branding fixtures in `data.ts` (Branding
  stores a hex), plus the logo SVGs.
- **Signature intent:** icons sit in ring lenses; step lists and progress are
  the Journey pathway (solid line, filled ends, rings between; done = filled,
  now = ring with halo, next = hollow; no dashes); the tile pickers' check is
  a ring that closes; success on the site's own pages is the brand ring
  closing. The islands appear only on the sign-in brand half, and only as
  Oblivion's own default when there is no client branding (Stephan, 21 Oct).
- **Sign-in scrim:** two ink scrims keep AA on any photo: a top band behind
  "Back to website" and the logo (at least 72 % ink), and one that travels
  with the welcome block (at least 90 % ink behind every line), so the
  taller family block never slides onto bare photo.
- **Slate:** tokens only; nested sections inside cards use `bg-muted`, never
  card-on-card. Tile pickers have no fill of their own; selected uses the
  brand-fill tint.
- **Safety colours stay fixed:** the 111 note is the warning status pair, not
  the brand.
- **Photos:** `public/demo/*.jpg` (hero-home, support-home,
  support-community, support-respite, careers-team, about-whanau,
  contact-house). Each sits in an aspect-ratio box on `bg-muted`; while a file
  is missing the box keeps its shape and shows the alt text. The alt text
  describes each photo as delivered on 20 Oct; update it if a photo changes.
- **Fonts:** `public/fonts/instrument-sans/` is a copy of the app's variable
  Instrument Sans (SIL OFL, licence included). It serves both the Journey
  family and plain "Instrument Sans" (the app loads that from
  fonts.bunny.net), so pop-ups, which portal outside the site wrapper, match
  the app.
- **Colour mixing:** brand-with-neutral mixes (the selected tile, tile hover
  border, horizon line) use `oklab`; in `oklch` the white card's hue 0
  dragged the teal tint to pink.

## How the mockup renders real components

`inertia-shim.ts` stands in for `@inertiajs/react` (everything else is the
real package):

- `usePage` serves fictional props: `branding`, `errors`, `flash`, `locale`
  and `availableLocales` (English and Te reo Māori, so the sign-in language
  switch shows; translations aren't loaded, so switching only changes the
  pressed state).
- `Head` renders nothing (the real one needs Inertia's head manager); the
  preview sets each tab title from its route.
- `Link` maps app URLs to hash routes (`/login`, `/portal/login`, `/privacy`,
  `/terms`); anything else raises the demo notice instead of navigating.
- `useForm` and `router` keep state locally and hand each submit to the page
  (`setMockSubmitHandler`); nothing is sent.

`auth-layout-shim.tsx` is a mockup copy of
`layouts/auth/auth-journey-layout.tsx`, and `vite.config.mjs` aliases the real
layout to it, so the real login pages render their real forms inside it. It
adds the hero-photo brand half and "Back to website"; the rest is the approved
layout, line for line. The photo comes from a mockup-only `branding.heroUrl`
prop.

No real component was changed. Workarounds:

- The real careers pages use the legacy `PageHero`, native `<select>`s and a
  bare file input, so the demo reuses their **field set** and
  `lib/job-posting-constants.ts` (`employmentTypeLabels`,
  `sourceChannelOptions`) inside the site shell, with `Select`,
  `FileDropzone` and tile pickers. `employmentTypeLabels` is Title Case
  ("Part Time"); the page sentence-cases it. "Career page" shows as "Our
  website".
- `WizardShell` defaults to 980 px; the demo passes the 1100 px wizard width.
- `WizardSuccessPane` still draws the green tick (the signature's brand ring
  would replace it in the component, not per page).
- `ConfirmDialog` uses `frontline` for its 480 px width and 44 px targets on
  phones.
- Staff SSO routing (`ssoRouting`) isn't passed: its hand-off leaves the page
  for the provider, which a static preview can't follow.
- The review bar sits under pop-ups (z-index 40) so it never covers a
  wizard's header on a phone.

Found in real components during the browser check (not changed here):

- `Dialog` (so `WizardShell` and `ConfirmDialog`) paints `bg-background`,
  the grey page ground, so pop-ups look grey until the Slate tokens land
  (Slate's ground is near-white).
- `Textarea` also uses `bg-background`, so it looks grey on a white card
  beside `Input`, and it shows no red border when `aria-invalid` (the error
  text below it still shows).
- `LocaleSwitch` wraps "Te reo Māori" onto two lines at 375 px beside a
  client logo on the sign-in form half.
- `WizardShell` marks every step before the current one as done (green
  tick) even when its fields are empty, because steps are skippable.

## Real-app changes the build will need

- **Default provider name.** `ReferralSettings::defaults()` and the public
  `Header` / `Footer` fall back to "Oblivion Care". On a client's site the
  fallback must be the organisation's own name, never ours. ("No client
  branding (today's fallback)" in the brand slot shows the problem.)
- **A working Contact us** that posts to referral intake as a general
  enquiry: its own queue, its own ENQ- reference (referrals keep theirs),
  excluded from referral counts and reports, with the safe-contact fields
  (preferred channel, safe to leave a message).
- **Careers inside the public site shell**: `/careers`, the job page, apply
  and the status page move from `PageHero` pages to the site header and
  footer, and apply becomes the `WizardShell` flow. New fields (experience,
  right to work, driver licence) need a home in Recruitment; the police
  vetting note is copy only.
- **Sign-in brand half:** login brand half shows the client's hero photo
  from Settings › Branding; the islands art is only Oblivion's own default.
  That needs a hero-photo field in Settings › Branding (shared with the
  website home hero), `AuthJourneyLayout` taking the photo with the two
  scrims, the family headline over a photo, and a "Back to website" link at
  the top of both sign-in pages (to the site's home page; at the top of the
  form on phones). The logo already follows the brand slot (`BrandLockup`).
  Two Oblivion leftovers on those pages: the staff email placeholder
  `you@oblivioncare.co.nz`, and the brand-half label fallback "Oblivion
  Care".
- **Public nav breakpoint**: the real `Header` needs Careers and Contact us,
  "Sign in" and "Make a referral", with the full nav from 1280 px.

## Decided (Stephan, 21 Oct)

- **Sign-in brand half:** the client's own hero photo, logo and welcome
  text, under a scrim that keeps AA, for both staff and family sign-in. The
  NZ islands art is only Oblivion's own default.
- **"Back to website"** link at the top of both sign-in pages.
- **References:** Contact-us messages use the ENQ- prefix (ENQ-2048 in the
  demo); referrals keep their own format.
- **Dark-mode brand colour is worked out automatically.** Clients set one
  colour; the app lightens it for dark mode to keep contrast (what
  `applyPalette()` and the app's derived tokens already do). The demo's
  colour presets now use one colour in both modes.
- **Privacy and Terms** (and Accessibility) keep clearly marked placeholder
  text for the client to replace.

## Decisions requested

- Approve the client demo website (these routes and flows).

## Lead review of build 1 (21 October, 06:55)

Checked at 1440 px: Home, Careers, Contact us and Sign in render in the Oblivion Living brand with all seven photos (lazy-loaded) and no console errors.

Two questions for Stephan. Both come from the real sign-in page, so they would be app changes:
- The sign-in brand half still shows the large Journey islands artwork, which is Oblivion Care's own mark, on the client's site. Should a client's sign-in show their hero photo or a plain brand-colour panel instead?
- The staff email placeholder reads `you@oblivioncare.co.nz`. It should be neutral, e.g. "Your work email", or come from the client's domain.

(Mockup builder, 21 Oct: the first question is answered under "Decided" and built in the mockup. The placeholder is listed under "Real-app changes"; the mockup shows the real page's placeholder unchanged.)
