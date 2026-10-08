# Medicine photos in eMAR: NZ market research

29 September 2026 · Requested by Stephan before adding medicine pictures to P06 · NZ-focused desk research (vendor pages, NZ agencies). No vendor demos or pricing quotes.

## Bottom line

**In NZ disability residential services, medicine pictures are already expected.** The main competitor, Toniq **1CHART**, shows **Toniq Pill Pictures** (about 1,800 medicine photos) so staff can identify medicines. It is used by IDEA Services (IHC), NZ's largest intellectual-disability community provider. The same image library is available to other software **through an API**. This app already has an **unused per-medicine `photo_path` column** and **NZULM codes** on each medicine. A pill-picture feature fits the existing data; it isn't a new module.

## NZ findings

| Finding | Detail | Source |
|---|---|---|
| **1CHART shows pill pictures** | Toniq's aged-care and disability charting product: "Easily identify medicines with Toniq Pill Pictures" (about 1,800 photos). It shows brand and generic names. Reported scale: over 10,000 beds and over 3.4 million medicines administered per month. | [Toniq 1CHART](https://toniq.nz/products/1chart/) |
| **IDEA Services (IHC) uses 1CHART** | NZ community provider for people with intellectual disabilities, and the first NZ community service on electronic charting. It reported **30% fewer medication errors in year one**. That result is for the whole move to e-charting, not pictures on their own. 1CHART has IHC-specific doctor and pharmacy pages. | [HINZ](https://www.hinz.org.nz/news/648536/IDEA-Services-reduces-medication-errors-with-electronic-charting.htm) · [1CHART – IHC pharmacy page](https://toniq.nz/1chart-ihc-pharmacy-page/) |
| **Toniq Dispensary** (NZ's leading pharmacy software) | "Medicine Photos" help dispensing and **print on charts**. It supports NZ dose-pack systems (**Medico, Webster, Nimrod, Nomad, Apotex, Alpaca**) and batch-prints dose packs, **signing sheets** and PRN forms. The pharmacies supplying your houses may already print pictures on what they send. | [Toniq Dispensary](https://toniq.nz/products/toniq-dispensary/) |
| **Toniq Pill Pictures API** | A licensed image service for third-party software: "secure API connection … up-to-date imagery". The page doesn't say how images follow Pharmac brand changes, or what they cost. | [Toniq Pill Pictures](https://toniq.nz/services/pill-pictures/) |
| **Pharmac brand changes** | Funded brands change through tenders, so tablets can look different. Pharmac publishes "Medicines can look different" guidance and per-medicine brand-change notices. Medsafe's committee has reviewed brand switches. **A picture must match the brand actually supplied.** | [Healthify FAQ](https://healthify.nz/medicines-a-z/m/medicine-brand-changes-faqs) · [Pharmac leaflet](https://www.pharmac.govt.nz/assets/Medicines-can-look-different-patient-leaflet.pdf) · [Pharmac notice example](https://www.pharmac.govt.nz/medicine-funding-and-supply/medicine-notices/bisacodyl-brand) · [Medsafe MARC report](https://www.medsafe.govt.nz/committees/marc/reports/175-Brand%20Switches%20in%20New%20Zealand.pdf) |
| **Free NZ text description** | Medsafe's Consumer Medicine Information template requires a **"What it looks like"** section: colour, shape and markings for each form and strength. It's text, not an image, but it's authoritative and free. | [Medsafe CMI template](https://www.medsafe.govt.nz/regulatory/Guideline/GRTPNZ/ScheduleAForm10.4.doc) · [Medsafe CMI search](https://www.medsafe.govt.nz/consumers/cmi/cmiindex.htm) |
| **NZULM / NZMT** | The standard NZ medicine naming and coding used in most NZ software. No image data found. This app already stores `nzulm_code` on each medicine, which could be the key for matching a library image to the brand. | [Health NZ – NZULM](https://www.tewhatuora.govt.nz/health-services-and-programmes/digital-health/emedicines-and-the-new-zealand-e-prescription-service/nz-universal-list-of-medicines) |
| **Other NZ care software** | MediMap (NZ aged care: NZ Formulary, e-prescribing) — no pill images found. VCare, eCase and Leecare (NZ/AU aged care and disability) — patient photo and barcode are common eMAR safety features; pill images not confirmed. | [MediMap](https://www.medimap.co.nz/features) · [VCare](https://www.vcaresoftware.com/) · [Leecare NZ](https://leecare.co.nz/) · [eCase](https://www.capterra.co.nz/software/148276/ecase) |
| **NZ pharmacy packing** | NZ pharmacies robot-pack blister packs for residential care, e.g. [Vogeltown Pharmacy](https://www.vogeltownpharmacy.co.nz/blister-packs/) and [Cretem NZ](https://www.cretem.co.nz/abou-us). | — |

**Relevant from outside NZ (brief):**

- **Australia:** packing pharmacies print colour images of both sides of each tablet inside the pack, sourced from MIMS ([Pack my Pills](https://www.packmypills.com.au/faq/)). This shows the pharmacy-supplied route works.
- **US:** pill-image datasets are keyed to US product codes and **aren't usable for NZ brands**.
- **Evidence:** I found no outcome studies showing that pictures in an eMAR, on their own, reduce administration errors.

## What this app already has

- `client_medications.photo_path` was added in `2026_03_26_000001_create_comprehensive_emar_tables.php`. **Nothing in the app reads or writes it.**
- `client_medications.nzulm_code` exists (`ClientMedication.php:50`).
- The June 2026 1CHART gap analysis (`docs/emar-1chart-gap-analysis.md` §"Pill-picture identification") already noted that 1CHART licenses Toniq Pill Pictures. It left a licensed library out of scope.
- The upload and viewer building blocks exist (`FileDropzone`, `FilePreviewDialog`, private attachment serving).
- Person photos exist (`clients.profile_photo_path`) but are on the **public** disk. Move them to private storage before showing them in medication dialogs.

## Options for this app

| Option | How it works | Pros | Cons |
|---|---|---|---|
| **A. License Toniq Pill Pictures (API)** | Match each medicine to a library image by NZULM code and brand | Parity with 1CHART. The same pictures pharmacies may already print. No staff effort. | Commercial agreement and cost unknown. Coverage limited (about 1,800 images). Need to confirm it follows Pharmac brand changes and maps to NZULM. |
| **B. Staff photo of the supplied pack at receipt** | Take a photo when stock is received (P06), using the existing `photo_path` or one photo per received pack | Always matches what's actually in the house. No licence. Works for any product. | Staff effort. Photo quality varies. Needs a prompt when a new pack or brand arrives. |
| **C. Text description** | Copied from Medsafe CMI "What it looks like", or the pharmacy label | Free and authoritative | Not a picture. Needs manual entry unless it's imported. |

## Recommendation

Use **A where available, B as the fallback, and C alongside both.**

- **Ask Toniq** about a Pill Pictures API licence: cost, coverage, NZULM/brand mapping, and how updates follow Pharmac changes. That gives parity with the main NZ competitor.
- **Build B regardless.** It uses the existing `photo_path` column, covers anything missing from the library, and handles a new brand arriving before the library updates.
- In every case:
  - show the picture's source and date ("Toniq image for Brand X" or "Photo of pack received 12 Sep");
  - show **"Pack or brand changed — check the label"** when the supplied brand or pack differs from the picture;
  - show **"No picture — check the label"** when there isn't one;
  - never block recording;
  - always say **"Check the label — the picture is a guide only."**
- **Show the person's photo first**, from private storage, in every recording dialog (P01).

**Where it goes in the plan:**

- **P06:** capture at receipt, and the brand/pack change prompt.
- **P01:** show the picture in the recording dialog.
- **P02:** picture history on the person's medication record.
- **Settings (P11):** the image source (library, photos or both) and who can take or replace photos.

## Questions for Stephan

1. Should we approach Toniq about licensing Pill Pictures?
2. Which pharmacies supply your houses, and do they use Toniq? Do their packs or signing sheets already show pictures?
3. When a new medicine or brand arrives, should a photo be required or optional?
