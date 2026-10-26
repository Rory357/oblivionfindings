# Writing on screen — one way to write things everywhere (approved by Stephan 2026-10-22)

Every module writes dates, times, money, names, buttons and labels the same way, so the
app reads as one product. These rules apply to pages, pop-ups, emails, PDFs and exports
shown to people. Write in plain NZ English for a support worker on shift or a volunteer
board member.

## W1 — Breadcrumbs start at Home

Home › module › … › this page. A one-crumb trail is hidden (see `PAGE_HEADER_STYLE_GUIDE.md`).

## W2 — Te reo Māori with macrons

whānau, Māori, Mōrena, and place and people names as they are spelt (Kōwhai, Tōtara,
Rāwiri). Never drop a macron.

## W3 — One date format

- "27 Oct 2026". Add the day when it helps ("Tue 27 Oct 2026"). Within the current year
  the year may be left off ("Tue 27 Oct").
- Always format through `lib/datetime.ts` (`formatDate`, `formatDateOnly`). Never put an
  ISO string (2026-10-27), a slash date (27/10/2026) or a long month (27 October 2026)
  on screen.
- A period is "Oct 2025 – Sep 2026".
- Dates are worked out at the NZ wall clock, never UTC midnight.

## W4 — One time format

- "9:30 am", "3:00 pm". A range is "10:00 am – 1:00 pm" (en dash with spaces). A whole
  day is "all day", never "00:00–23:59".
- A date followed by a time takes a comma: "Tue 27 Oct, 9:30 am" (`formatDateTime`).
- Audit times that need seconds: "27 Oct 2026, 9:30:12 am".
- Durations and countdowns are not clock times: "1 h 30 min", "27:41 left", "0:18".
- Never a 24-hour clock on screen.

## W5 — Money

"$84.60", "$1,240.00". Never "NZD" on screen; say "incl. GST" or "excl. GST" when it
matters. A total equals its lines.

## W6 — Header lines and labels have no full stop

The line under a page or pop-up title is a phrase, not a sentence. Labels, buttons, tab
names and chips never end in a full stop.

## W7 — Sentence case

Only the first word and names take a capital: "Add vehicle", "Fire alarm", "Control room",
"First aid certificate". Proper names keep theirs (people, places, products, acts:
"Holidays Act 2003", "KiwiSaver"). Acronyms stay capitals (NHI, IRD, GST).

## W8 — One verb for each kind of action

- **"Add …"** opens the form for a new record: "Add client", "Add shift", "Add bill".
- **"New …"** only for a message or a conversation: "New message".
- **"Create …"** only when something is built from other things: "Create report",
  "Create evidence pack", "Create payment run", "Create version 3".
- **"Report incident"** for incidents, everywhere, as in the top bar.
- Other actions name what happens: "Record dose", "Approve", "Send invoice", "Mark as paid".
  Never "Submit", "OK" or "Yes".
- "Delete" when it is gone for good. "Remove" when it stays in history.

## Other words that keep the app consistent

- **One number, one place.** A number appears once on a page: in the header or in the
  body, never both. A tab that counts something carries that count; the header doesn't
  repeat it.
- **Missing is not zero.** When data can't be read or the viewer can't see it, say "Not
  available". Never show a green "0" that reads as a clean record.
- **An empty value is "—"**, never "---", "N/A" or blank.
- **Plurals agree:** "1 vendor", "2 vendors".
- **Names, not codes.** Show a person's or record's name, with its reference as a muted
  suffix (name · NHI, name · ticket number). Never "Resident #42", a raw key
  (`out_of_service`, `two_monthly`), a class name or an exception name.
- **No developer words on screen:** job names, model names, "unwired", "SIMULATED" unless
  it is a real, labelled test state, environment variable names, `php artisan` commands.
- **No assurance the data doesn't back up.** A "Compliant", "Certified" or "Notified"
  badge is worked out from evidence, never fixed text.
- **Safety, privacy, consent and legal wording is kept word for word.** Redesigns change the
  frame around it, never the text.
- **One name for one thing.** If two screens show the same status, use the same label in
  both (not "Waiting for decision" on one and "Waiting for approval" on the other).
