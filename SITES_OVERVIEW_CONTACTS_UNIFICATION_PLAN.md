# Sites Module — Unify Overview Contact Information with the Contacts tab

**Target:** Oblivion Findings Laravel/Inertia/React app at `C:\Users\steph\Herd\oblivionfindings`.
**Scope:** Make the Overview's *Contact Information* card derive its people-rows from `site_contacts` rather than from scalar columns on the `sites` table. Backfill existing data, then drop the duplicated columns. Sites & Locations only — do **not** touch mobile.
**Audience:** Codex.

---

## Why

Right now `/sites/{id}` has two parallel and disconnected contact stores:

1. **Overview → "Contact Information" card** writes scalar columns directly on the `sites` table: `phone`, `email`, `manager_name`, `manager_phone`, `after_hours_phone`. UI: [resources/js/pages/sites/show.tsx:1870-1952](resources/js/pages/sites/show.tsx). Dialog: [resources/js/pages/sites/_overview-dialogs.tsx:37-158](resources/js/pages/sites/_overview-dialogs.tsx). Controller: `SiteController::updateContactInfo()` at [app/Http/Controllers/SiteController.php:619-639](app/Http/Controllers/SiteController.php). Route: `PATCH /sites/{site}/contact-info`.
2. **Contacts tab** writes rows to `site_contacts` with typed roles (`manager`, `team_lead`, `emergency`, `clinical`, `family`, `next_of_kin`, `maintenance`, `other`). UI: `ContactsTab()` at [resources/js/pages/sites/show.tsx:3445-3544](resources/js/pages/sites/show.tsx). Dialogs: [resources/js/pages/sites/contacts/_dialogs.tsx](resources/js/pages/sites/contacts/_dialogs.tsx). Controller: [app/Http/Controllers/SiteContactController.php](app/Http/Controllers/SiteContactController.php).

The two never talk to each other. A user can add `john` as a Manager in the Contacts tab and Overview still shows an empty *"Add manager phone"* button next to it. That is the disconnect the user is feeling and what this plan fixes.

**Direction (already chosen by the user):**

- `site_contacts` becomes the single source of truth for **people-y** rows (Site Lead, Manager Phone, After Hours).
- `sites.phone` and `sites.email` stay as truly **site-level** fields (the house's general landline/inbox — not a person).
- Backfill existing scalar data into `site_contacts`, then drop the three people-y columns.
- No live production data — we are free to backfill destructively.

---

## Outcome (acceptance criteria)

Once this plan is shipped, the following must be true:

- [ ] `sites` table no longer has `manager_name`, `manager_phone`, `after_hours_phone` columns. `phone` and `email` remain.
- [ ] Every existing site that had a non-null `manager_name`/`manager_phone` now has a corresponding `site_contacts` row with `type='manager'` (created by the backfill migration).
- [ ] Every existing site that had a non-null `after_hours_phone` now has a corresponding `site_contacts` row with `type='emergency'` and `name='After-hours contact'` (unless already present).
- [ ] The Overview *Contact Information* card on `/sites/{id}` renders 5 rows:
  - **Phone** (scalar — site.phone)
  - **Email** (scalar — site.email)
  - **Site Lead** (derived — first `site_contacts` with `type='team_lead'`, ordered `is_primary DESC, id ASC`)
  - **Manager** (derived — first `site_contacts` with `type='manager'`, same ordering)
  - **After Hours** (derived — first `site_contacts` with `type='emergency'`, same ordering)
- [ ] Each derived row, when populated, displays the contact's **name** as the primary value and the **phone** as the actionable link (`tel:`). If the contact has only an email, fall back to email (`mailto:`).
- [ ] Each derived row, when empty, shows an **"Add {role} →"** CTA that opens `AddContactDialog` pre-filled with the matching `type` and the type tile locked. After save the user lands back on the Overview tab with the row populated.
- [ ] The "Edit" button on the Contact Information card opens a slimmed-down dialog that **only** edits `phone` and `email`. Rename the dialog to `EditSiteLineDialog`.
- [ ] `PATCH /sites/{site}/contact-info` validation only accepts `phone` and `email`. The three removed fields produce a 422 if sent.
- [ ] Grep for `manager_name`, `manager_phone`, `after_hours_phone` returns zero hits in `app/`, `resources/`, `routes/`, `database/factories/`, `database/seeders/` (except the migration that drops them). Tests are allowed to reference them only inside the migration test itself.
- [ ] `php artisan test --filter=Sites` and `npx tsc --noEmit` and `npm run build` all green.
- [ ] Overview *Contact Information* card looks coherent in both light and dark theme (manual visual QA on `oblivionfindings.test`).
- [ ] Readiness score does not regress — the Overview no longer prompts users to fill in fields that exist in Contacts.

---

## Ground rules (carry-over from previous Sites plans)

1. **Module URL is `/sites`.** NZ context — NZD, NZ English (`organisation`), Ministry of Health/NASC framing, no NDIS.
2. **Theme tokens only.** No hard-coded purple/indigo/`#XXXXXX` in JSX/TSX. Use `primary`, `primary-foreground`, `muted`, `border`, `card`, `accent`, `status-success`, `status-warning`, `status-critical`, `status-info`.
3. **Sites module UI pattern:** rounded card grid + dialog-driven CRUD; Send-Kudos-style 3-column tile pickers. Canonical example: [resources/js/pages/sites/contacts/_dialogs.tsx](resources/js/pages/sites/contacts/_dialogs.tsx) `ContactTypePicker`.
4. **Dark theme verified.**
5. **Memory directive:** "fix errors/warnings found during verification, don't dismiss as pre-existing." Applies to everything in this plan.

After each task, run:

```powershell
php artisan test --filter=Sites
npx tsc --noEmit
npm run build
```

---

## Step 1 — Database migration (backfill + drop)

Create `database/migrations/2026_05_16_000001_drop_legacy_site_contact_scalars.php`.

**up()** runs as a single transaction:

1. **Backfill `manager_name` + `manager_phone` → `site_contacts`** with `type='manager'`. For every site where at least one of those two columns is non-null **and** there is **no existing** `site_contacts` row with `type='manager'` for that site:

   ```php
   DB::table('sites')
       ->whereNotNull('manager_name')->orWhereNotNull('manager_phone')
       ->orderBy('id')
       ->chunkById(200, function ($sites) {
           foreach ($sites as $site) {
               $hasManager = DB::table('site_contacts')
                   ->where('site_id', $site->id)
                   ->where('type', 'manager')
                   ->exists();
               if ($hasManager) continue;

               DB::table('site_contacts')->insert([
                   'site_id'    => $site->id,
                   'type'       => 'manager',
                   'name'       => $site->manager_name ?: 'Manager',
                   'role'       => null,
                   'phone'      => $site->manager_phone,
                   'email'      => null,
                   'is_primary' => false,
                   'notes'      => null,
                   'created_at' => now(),
                   'updated_at' => now(),
               ]);
           }
       });
   ```

2. **Backfill `after_hours_phone` → `site_contacts`** with `type='emergency'`, same idempotency check (skip if site already has any `type='emergency'` contact):

   ```php
   DB::table('sites')
       ->whereNotNull('after_hours_phone')
       ->orderBy('id')
       ->chunkById(200, function ($sites) {
           foreach ($sites as $site) {
               $hasEmergency = DB::table('site_contacts')
                   ->where('site_id', $site->id)
                   ->where('type', 'emergency')
                   ->exists();
               if ($hasEmergency) continue;

               DB::table('site_contacts')->insert([
                   'site_id'    => $site->id,
                   'type'       => 'emergency',
                   'name'       => 'After-hours contact',
                   'role'       => 'After hours',
                   'phone'      => $site->after_hours_phone,
                   'email'      => null,
                   'is_primary' => false,
                   'notes'      => null,
                   'created_at' => now(),
                   'updated_at' => now(),
               ]);
           }
       });
   ```

3. **Drop the three columns** from `sites`:

   ```php
   Schema::table('sites', function (Blueprint $table) {
       $table->dropColumn(['manager_name', 'manager_phone', 'after_hours_phone']);
   });
   ```

**down()** re-adds the three nullable string columns. Do **not** attempt to reverse-backfill from `site_contacts` — accept that rolling back loses data. Note this in a docblock at the top of the migration.

> ⚠️ Wrap up() in `DB::transaction(...)` so a partial failure doesn't half-migrate. Use `Schema::disableForeignKeyConstraints()` only if needed (it shouldn't be for this migration).

### Acceptance for Step 1

- `php artisan migrate` runs cleanly on a fresh DB seeded with sites that have the legacy fields filled.
- After migration, the previously-set scalar values appear as `site_contacts` rows of the right `type`.
- Sites that already had matching contacts (e.g. an existing `type='manager'` contact) are **not** double-inserted.
- Sites that had all three scalar fields null get no new contact rows.
- `php artisan migrate:rollback` puts the columns back (empty).

---

## Step 2 — Site model: relations + cleanup

[app/Models/Site.php](app/Models/Site.php)

1. **Remove** `manager_name`, `manager_phone`, `after_hours_phone` from `$fillable` (currently lines 26–28).
2. **Add** four typed relations below the existing `contacts()` relation (line 78–81):

   ```php
   public function managerContact(): \Illuminate\Database\Eloquent\Relations\HasOne
   {
       return $this->hasOne(SiteContact::class)
           ->where('type', 'manager')
           ->orderByDesc('is_primary')
           ->orderBy('id');
   }

   public function siteLeadContact(): \Illuminate\Database\Eloquent\Relations\HasOne
   {
       return $this->hasOne(SiteContact::class)
           ->where('type', 'team_lead')
           ->orderByDesc('is_primary')
           ->orderBy('id');
   }

   public function afterHoursContact(): \Illuminate\Database\Eloquent\Relations\HasOne
   {
       return $this->hasOne(SiteContact::class)
           ->where('type', 'emergency')
           ->orderByDesc('is_primary')
           ->orderBy('id');
   }

   public function primarySiteContact(): \Illuminate\Database\Eloquent\Relations\HasOne
   {
       return $this->hasOne(SiteContact::class)
           ->where('is_primary', true)
           ->orderBy('id');
   }
   ```

   Use `hasOne` with ordering — only the first row matters for the Overview.

3. **Leave `primaryContact()` (`belongsTo User`) alone** — it's a different concept (which staff user owns this site) and is consumed by other modules. Do not conflate it with `primarySiteContact()` above.

### Acceptance for Step 2

- `Site::find($id)->managerContact` returns the highest-priority manager contact or null.
- `Site::with(['managerContact', 'siteLeadContact', 'afterHoursContact', 'primarySiteContact'])->find($id)` eager-loads correctly in one query each.
- `tinker` round-trips look right.

---

## Step 3 — SiteController::show — pass derived contacts to the view

[app/Http/Controllers/SiteController.php](app/Http/Controllers/SiteController.php) — `show()` method.

1. Eager-load the four new relations alongside whatever is already loaded:

   ```php
   $site->load([
       'contacts',                 // already loaded — keep
       'managerContact',
       'siteLeadContact',
       'afterHoursContact',
       'primarySiteContact',
       // ...other existing eager loads
   ]);
   ```

2. The Inertia payload will automatically include these as nested fields on `site` (because they're standard Eloquent relations). Confirm the props shape by `dd($site->toArray())` once and verify the JSON looks like:

   ```jsonc
   {
     "id": 9004,
     "phone": null,
     "email": null,
     "contacts": [ /* full list */ ],
     "manager_contact":      { "id": 12, "name": "john", "phone": "0291299338", ... } | null,
     "site_lead_contact":    { "id": 13, "name": "Stephan", ... } | null,
     "after_hours_contact":  null,
     "primary_site_contact": { ... } | null
   }
   ```

   (Inertia/Laravel serialises relation names from camelCase to snake_case by default. If your project uses a different casing strategy, match the existing convention — check how `primary_contact_user_id` vs `primaryContact` is exposed today.)

3. No new controller method needed — this is purely additive to `show()`.

### Acceptance for Step 3

- `/sites/{id}` page props contain `manager_contact`, `site_lead_contact`, `after_hours_contact`, `primary_site_contact` keys (each either a contact object or null).
- Query count for the show page does not increase by more than 4 (one per new relation). If it does, fix N+1.

---

## Step 4 — SiteController::updateContactInfo — narrow scope to phone + email only

[app/Http/Controllers/SiteController.php:619-639](app/Http/Controllers/SiteController.php)

Replace the validation rules with just:

```php
$validated = $request->validate([
    'phone' => ['nullable', 'string', 'max:30'],
    'email' => ['nullable', 'email', 'max:255'],
]);

$site->update($validated);
```

Drop the manager_name / manager_phone / after_hours_phone branches entirely. If the dialog ever submits one of those keys, validation strips it (no need to reject explicitly).

The route `PATCH /sites/{site}/contact-info` keeps its URL.

### Acceptance for Step 4

- POSTing `manager_name=foo` to this endpoint is silently ignored (or 422 if you prefer — choose explicit rejection only if tests already assert it).
- POSTing valid `phone`+`email` saves both fields.

---

## Step 5 — UI: rebuild the Contact Information card

[resources/js/pages/sites/show.tsx:1870-1952](resources/js/pages/sites/show.tsx)

Replace the existing block with the structure below. Reuse `ContactRow` for all 5 rows — the component already handles empty state and `canFix`/`onFix`.

```tsx
{/* Contact Information */}
<Card className="overflow-hidden border-border/60 shadow-sm transition-shadow hover:shadow-md">
    <CardHeader className="flex flex-row items-center justify-between space-y-0 border-b border-border/60 bg-gradient-to-br from-primary/5 to-transparent">
        <CardTitle className="flex items-center gap-2 text-base">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <Phone className="h-4 w-4" />
            </span>
            Contact Information
        </CardTitle>
        {can_edit && (
            <Button
                variant="outline"
                size="sm"
                className="h-8 gap-1.5 text-xs"
                onClick={() => setContactInfoOpen(true)}
            >
                <Pencil className="h-3 w-3" />
                Edit site line
            </Button>
        )}
    </CardHeader>
    <CardContent className="divide-y divide-border/40 p-0 text-sm">
        {/* Site-level scalar fields */}
        <ContactRow
            icon={Phone}
            label="Phone"
            value={site.phone}
            href={site.phone ? `tel:${site.phone}` : undefined}
            canFix={can_edit}
            onFix={() => setContactInfoOpen(true)}
        />
        <ContactRow
            icon={Mail}
            label="Email"
            value={site.email}
            href={site.email ? `mailto:${site.email}` : undefined}
            canFix={can_edit}
            onFix={() => setContactInfoOpen(true)}
        />

        {/* Derived from site_contacts — read-only here, edit lives in Contacts tab */}
        <DerivedContactRow
            icon={User}
            label="Site Lead"
            contact={site.site_lead_contact}
            emptyCta={can_edit ? 'Add site lead' : undefined}
            onAdd={() => openAddContactWithType('team_lead')}
            onEdit={() => openEditContact(site.site_lead_contact?.id)}
        />
        <DerivedContactRow
            icon={Phone}
            label="Manager"
            contact={site.manager_contact}
            emptyCta={can_edit ? 'Add manager' : undefined}
            onAdd={() => openAddContactWithType('manager')}
            onEdit={() => openEditContact(site.manager_contact?.id)}
        />
        <DerivedContactRow
            icon={Clock}
            label="After Hours"
            contact={site.after_hours_contact}
            emptyCta={can_edit ? 'Add after-hours contact' : undefined}
            onAdd={() => openAddContactWithType('emergency')}
            onEdit={() => openEditContact(site.after_hours_contact?.id)}
        />
    </CardContent>
</Card>
```

### Add a new `DerivedContactRow` component

Define it next to `ContactRow` in the same file (or extract both to a shared module if the file is already large). It renders:

- **Populated state:** icon + label + contact name as the value; secondary line with the phone (as a `tel:` link) and/or email (`mailto:` link). A small "Edit in Contacts" pencil button on hover that calls `onEdit`.
- **Empty state:** icon + label + a muted "Not set" + an inline `<button>` showing `emptyCta` text (e.g. "Add manager →") that calls `onAdd`.

Match the visual rhythm of the existing `ContactRow` exactly. Use theme tokens only.

### Wire the helpers

In the same component scope where `setContactInfoOpen` is declared, add:

```ts
const [addContactType, setAddContactType] = useState<SiteContactType | null>(null);
const [editContactId, setEditContactId] = useState<number | null>(null);

const openAddContactWithType = (type: SiteContactType) => setAddContactType(type);
const openEditContact = (id: number | undefined) => id && setEditContactId(id);
```

Render `AddContactDialog` and `EditContactDialog` near the bottom of the page (or wherever existing dialogs are mounted) controlled by these pieces of state. The `AddContactDialog` already accepts a `type` prop — pass `addContactType` to it and, when non-null, **lock the type picker** so the user can't change the role away from the slot they came from. (If the existing dialog doesn't support locking, add a `lockType?: boolean` prop that disables the `ContactTypePicker`.)

After a successful save (Inertia `onSuccess`), close the dialog and reset state to `null`. Inertia's partial reload will refresh the derived rows automatically.

### Acceptance for Step 5

- Overview *Contact Information* card has 5 rows in the order: Phone, Email, Site Lead, Manager, After Hours.
- Empty Manager row shows "Add manager →" CTA; clicking opens `AddContactDialog` with the **Manager** type tile selected and locked.
- Saving a Manager contact from that dialog closes it and the Manager row immediately renders the new contact's name + phone.
- A populated row shows the contact name; clicking the phone number opens the dialer.
- Hovering a populated derived row reveals a pencil that opens `EditContactDialog` for that contact.
- Light and dark theme both look correct.

---

## Step 6 — UI: slim down EditContactInfoDialog → EditSiteLineDialog

[resources/js/pages/sites/_overview-dialogs.tsx:37-158](resources/js/pages/sites/_overview-dialogs.tsx)

1. **Rename** `EditContactInfoDialog` → `EditSiteLineDialog`. Update the import in [resources/js/pages/sites/show.tsx](resources/js/pages/sites/show.tsx).
2. **Strip** the form down to just `phone` and `email`. Remove the `manager_name`, `manager_phone`, `after_hours_phone` fields and the comment on line 117.
3. Update `DialogTitle` from "Edit Contact Information" to "Edit site line".
4. Keep the placeholder helpers (`09 555 0100`, `house@example.org.nz`) — they're useful.

```tsx
type SiteLineValues = { phone: string; email: string };

export function EditSiteLineDialog({
    siteId, isOpen, onClose, initial,
}: { siteId: number; isOpen: boolean; onClose: () => void; initial: Partial<SiteLineValues> }) {
    // ...same Dialog wrapper, replace body with just the two fields...
}
```

### Acceptance for Step 6

- The "Edit site line" button on Overview opens a dialog with exactly two fields (Phone, Email).
- Saving updates `sites.phone` and `sites.email` via `PATCH /sites/{site}/contact-info` and the Overview rows refresh.
- No references to `manager_name` / `manager_phone` / `after_hours_phone` remain in [_overview-dialogs.tsx](resources/js/pages/sites/_overview-dialogs.tsx).

---

## Step 7 — AddContactDialog: support locked type

[resources/js/pages/sites/contacts/_dialogs.tsx:328-401](resources/js/pages/sites/contacts/_dialogs.tsx)

Check whether `AddContactDialog` already accepts an initial `type` prop. If not, add it. Then add a `lockType?: boolean` prop:

- When `lockType === true`, render the type tile picker in a disabled/locked state (visually muted, no click handlers, with a small "From Overview" hint badge), OR replace the picker with a read-only summary of the chosen type.
- When `lockType === false` (default), behave as today.

Pass `lockType` from `show.tsx` when the dialog was opened via one of the Overview empty-state CTAs. Direct opens from the Contacts tab should not pass it.

### Acceptance for Step 7

- Opening "Add manager →" from Overview shows the manager tile pre-selected and the user cannot change it.
- Opening "New contact" from the Contacts tab shows the picker fully interactive.

---

## Step 8 — Sweep the codebase for leftover references

Codex must grep the repo and remove or rewrite any remaining references to the dropped columns:

```powershell
# Should return zero hits after this plan is complete (except inside the new migration file).
grep -rIn "manager_name\|manager_phone\|after_hours_phone" app/ resources/ routes/ database/factories/ database/seeders/ tests/
```

Likely hit-sites to check first:

- `database/factories/SiteFactory.php` — drop those keys from `definition()` or replace with `SiteContact::factory()` calls in a state/seeder.
- `database/seeders/*Sites*Seeder.php` — same.
- Any Pest/PHPUnit test that asserts on these columns — rewrite to assert on the derived contact row.
- The Sites readiness service (`app/Services/Sites/SiteReadinessService.php`) — if it counts manager_phone / after_hours_phone toward readiness, change the check to count `site_contacts` of the corresponding type.
- Any export/report query.

### Acceptance for Step 8

- The grep above returns zero hits outside the new migration.
- `php artisan test` is green.

---

## Step 9 — Tests

Add the following to keep the contract alive.

1. **`tests/Feature/Sites/SiteOverviewContactsUnificationTest.php`**

   ```php
   // 1. Migration backfill
   it('backfills manager_name/manager_phone into a site_contacts row', function () {
       // Insert a site with the legacy columns BEFORE the migration runs
       // (use a raw DB::table insert against a fresh test DB, or freeze
       // the schema and re-run the migration in isolation).
       // Assert: after migrate, a SiteContact with type='manager',
       // name=$site->manager_name, phone=$site->manager_phone exists.
   });

   it('backfills after_hours_phone into an emergency site_contact', function () { /* ... */ });

   it('does not double-insert when a matching contact already exists', function () { /* ... */ });

   // 2. Model relations
   it('returns the highest-priority manager via managerContact()', function () { /* ... */ });
   it('respects is_primary DESC ordering on managerContact()', function () { /* ... */ });

   // 3. Controller
   it('updateContactInfo only accepts phone and email', function () {
       $this->patch(route('sites.contact-info.update', $site), [
           'phone' => '09 555 0100',
           'email' => 'house@example.org.nz',
           'manager_name' => 'should be ignored',
       ]);
       expect($site->fresh()->phone)->toBe('09 555 0100');
       // No regression: manager_name column doesn't exist anymore.
   });

   // 4. Show payload
   it('exposes derived contact relations on the show page payload', function () {
       $site = Site::factory()->create();
       SiteContact::factory()->for($site)->create(['type' => 'manager', 'name' => 'Alice']);
       $this->get(route('sites.show', $site))->assertInertia(fn ($page) =>
           $page->has('site.manager_contact', fn ($c) => $c->where('name', 'Alice')->etc())
       );
   });
   ```

2. Update **`tests/Feature/Sites/SiteOperationalReadinessTest.php`** if any assertion previously read `manager_phone` / `after_hours_phone`. The new readiness rule should count the corresponding `site_contacts` rows.

3. **Playwright/Dusk** (optional but recommended): add a smoke test that:
   - Visits `/sites/{id}` (empty site).
   - Clicks "Add manager →" on Overview.
   - Verifies the manager tile is pre-selected and the picker disabled.
   - Submits a manager contact.
   - Verifies the Manager row on Overview now shows the name.

### Acceptance for Step 9

- All new Pest tests pass.
- `php artisan test --filter=Sites` green.

---

## Files touched (summary)

**New files**

- `database/migrations/2026_05_16_000001_drop_legacy_site_contact_scalars.php`
- `tests/Feature/Sites/SiteOverviewContactsUnificationTest.php`

**Modified files**

- [app/Models/Site.php](app/Models/Site.php) — remove fillable entries, add 4 typed relations.
- [app/Http/Controllers/SiteController.php](app/Http/Controllers/SiteController.php) — eager-load new relations in `show()`, narrow `updateContactInfo()` validation.
- [resources/js/pages/sites/show.tsx](resources/js/pages/sites/show.tsx) — rebuild the Contact Information card with derived rows, add `DerivedContactRow`, wire dialog state.
- [resources/js/pages/sites/_overview-dialogs.tsx](resources/js/pages/sites/_overview-dialogs.tsx) — rename `EditContactInfoDialog` → `EditSiteLineDialog`, strip to phone+email only.
- [resources/js/pages/sites/contacts/_dialogs.tsx](resources/js/pages/sites/contacts/_dialogs.tsx) — add `lockType` support to `AddContactDialog`.
- `database/factories/SiteFactory.php` — drop legacy keys; add a state for `withManagerContact()` etc.
- `database/seeders/` (whichever seeds sites) — same.
- [app/Services/Sites/SiteReadinessService.php](app/Services/Sites/SiteReadinessService.php) — switch any "has manager phone" / "has after hours" readiness check to count `site_contacts`.

---

## Verification (manual, on `oblivionfindings.test`)

1. `php artisan migrate:fresh --seed` — confirm no errors.
2. Visit `/sites/9004` (Harbour Respite). Confirm:
   - Phone and Email rows still editable via the "Edit site line" button.
   - Site Lead row shows Stephan Van Vuuren (team_lead) from the existing seed.
   - Manager row shows john + 0291299338.
   - After Hours row shows "Not set" with "Add after-hours contact →" CTA.
3. Click "Add after-hours contact →". Confirm the dialog opens with the **Emergency** tile pre-selected and locked.
4. Fill in a name + phone, save. Confirm the row updates without a page reload.
5. Delete the Manager contact from the Contacts tab. Confirm the Overview Manager row reverts to the empty state with the "Add manager →" CTA.
6. Repeat with theme toggled to dark.
7. Open the site in an incognito tab as a viewer-only user. Confirm CTAs are hidden and the rows are pure read-only.

---

## Out of scope (do **not** touch in this PR)

- `sites.primary_contact_user_id` — that's a FK to `users` for staff assignment, separate concern.
- Mobile app.
- The Contacts tab itself (the rounded card grid) — it stays as-is; we only consume its data.
- Adding new contact types (don't rename `team_lead` → `site_lead` in the schema; just label it "Site Lead" on the Overview).
- Geofence / Location card.

---

## Open questions for the user (resolve before starting)

None — the user has already chosen:

- **Direction:** Derive from Contacts (single source).
- **Migration approach:** Best outcome (= backfill into `site_contacts`, then drop the columns), since there is no live data to protect.
