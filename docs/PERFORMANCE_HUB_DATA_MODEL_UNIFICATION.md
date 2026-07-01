# Performance & Goals — Data-Model Unification: Scoping & Recommendation

**Status:** Decision document. **No schema changes have been made.** This exists so
you can decide the P2 "unify the stacks" item from the redesign audit with the full
picture. Grounded in the actual code (2026-06-29 snapshot of branch
`claude/hardcore-wing-51706d`).

## TL;DR recommendation

**Do _not_ do the blanket unification the audit sketched.** A grounded read shows the
"duplication" is mostly **legitimate domain separation**, not sprawl to collapse:

- The two **review** tables serve different populations and lifecycles (frequent
  tenant-scoped *employee* reviews vs. annual board-approved *CEO* reviews with
  resolution linkage). Forcing them into one table loses the governance workflow and
  gains nothing.
- The four **"goal"** tables are four different concepts that happen to share a noun.

There is exactly **one** consolidation worth doing, and it's small, safe, and
additive: give HR performance reviews **structured review-goal rows** instead of the
current `goals` JSON blob. Everything else should stay as-is. Details + a
ready-to-run migration/backfill/rollback for that one change are below.

---

## 1. The two review stacks — why they are NOT the same thing

| | `hr_performance_reviews` (HR) | `performance_reviews` (Governance) |
|---|---|---|
| Subject | Any employee | The CEO (board-run) |
| Scope | `tenant_id` scoped | Board-only, no tenant |
| Cadence | Frequent (annual/6-mo/probation) | Annual/quarterly board cycle |
| Lifecycle | draft→in_progress→completed→signed_off | drafting→self_review→peer_review→board_review→completed |
| Sign-off | employee + manager booleans | **board resolution** (`approval_resolution_id` → `resolutions`) |
| Children | `goals` **JSON blob** | `performance_goals` + `performance_kpis` + `performance_feedback` (real tables, weighted scoring, automated KPI sync) |
| Service | none | `PerformanceReviewService` (scorecards, weighted score, KPI sync) |
| Routes | `/hr/performance/*` (`hr.performance.*`) | `/governance/performance/*` (`governance.performance.*`) |

**Verdict:** these are two different business processes. The audit's "make
`hr_performance_reviews` canonical and fold governance in as a `review_type`" would:
- drag board-only CEO reviews into a tenant-scoped employee table,
- orphan the `approval_resolution_id` → `resolutions` board-approval chain,
- and force the rich KPI/feedback child tables onto every probation review.

That's a **conflation, not a cleanup.** Keep them separate. (The board `approve()` /
`submitSelfAssessment()` transitions the audit flagged as unreachable are already
routed on this branch — commit `d2628e89` — so the governance lifecycle is complete
without any merge.)

**Optional tiny bridge (only if a CEO is also a tenant employee):** a nullable
`governance_review_id` on `hr_performance_reviews` to cross-reference the board review.
Additive, reversible, no backfill. Recommend **defer** unless the product needs it.

---

## 2. The four "goals" tables — four concepts, one noun

| Table | Concept | Domain | Keep? |
|---|---|---|---|
| `hr_goals` (+ `hr_key_results`, `hr_goal_updates`) | OKR objectives w/ measurable key results + check-in history | HR, tenant | ✅ keep |
| `hr_development_goals` | Individual **competency-level** growth plans (current→target level) | HR, tenant | ✅ keep |
| `performance_goals` | CEO review goals per governance **pillar**, weighted, board-assessed | Governance | ✅ keep |
| `strategic_goals` (+ `strategic_initiatives`) | 3/5-year org **strategy** w/ budgeted initiatives | Governance | ✅ keep |

- `performance_goals` and `strategic_goals` are board artifacts — out of scope for an
  HR merge entirely.
- `hr_goals` vs `hr_development_goals` **is** the only pair worth examining. But they
  model different lifecycles (numeric OKR progress vs. proficiency-level advancement),
  have different UIs (Goals tab vs. Development tab + My-HR self-service), and are
  **already bridged** by the nullable `hr_development_goals.hr_goal_id` FK (a dev plan
  can roll up into an objective). Collapsing them into one polymorphic table would add
  conditional complexity to every read for no user-visible gain.
- The audit pointed at `GOALS_REDESIGN_PROMPT.md` for the collapse spec — **that file
  does not exist in the repo.** There is no concrete target to build to.

**Verdict:** keep all four. The HR pair stays two tables, bridged by the existing FK.

---

## 3. The one change worth doing — structured HR review goals (opt-in)

Today `hr_performance_reviews.goals` is a **JSON array of strings**. That means review
goals can't be queried, can't carry status/rating, and can't link to the OKR objectives
in `hr_goals`. Replacing the blob with a child table is the single high-value,
low-risk improvement — additive, reversible, with a mechanical backfill.

### Migration (new table, keep the JSON column during transition)
```php
Schema::create('hr_review_goals', function (Blueprint $t) {
    $t->id();
    $t->foreignId('performance_review_id')->constrained('hr_performance_reviews')->cascadeOnDelete();
    $t->unsignedBigInteger('tenant_id')->index();
    $t->string('description');
    $t->foreignId('hr_goal_id')->nullable()->constrained('hr_goals')->nullOnDelete(); // optional link to an OKR
    $t->string('status')->default('open');       // open | met | partially_met | missed
    $t->tinyInteger('rating')->nullable();       // 1-5, optional
    $t->integer('sort_order')->default(0);
    $t->timestamps();
    $t->index(['performance_review_id', 'status']);
});
```
Do **not** drop `hr_performance_reviews.goals` in this migration — leave it until the
backfill + UI cutover are verified in a later, separate migration.

### Backfill (idempotent, guarded)
```php
// database/migrations/..._backfill_hr_review_goals.php  (up)
HrPerformanceReview::whereNotNull('goals')->chunkById(200, function ($reviews) {
    foreach ($reviews as $r) {
        if (HrReviewGoal::where('performance_review_id', $r->id)->exists()) continue; // idempotent
        foreach ((array) $r->goals as $i => $g) {
            $text = is_array($g) ? ($g['description'] ?? json_encode($g)) : (string) $g;
            if (trim($text) === '') continue;
            HrReviewGoal::create([
                'performance_review_id' => $r->id,
                'tenant_id' => $r->tenant_id,
                'description' => mb_substr($text, 0, 500),
                'status' => 'open',
                'sort_order' => $i,
            ]);
        }
    }
});
```

### Rollback
- Migration `down()` drops `hr_review_goals` (child rows cascade). The original
  `goals` JSON is untouched, so the app reverts to reading the blob with zero data
  loss. Fully reversible.

### App changes required (follow-up, not in the migration)
1. `HrPerformanceReview::reviewGoals()` HasMany; write to it in the review wizard/update.
2. `PerformanceHubController` + `show-review.tsx` read the child rows (fall back to the
   JSON blob when a review has no child rows yet — safe during transition).
3. Only after the UI reads child rows everywhere: a final migration drops the `goals`
   column.

**Estimated blast radius:** `hr_performance_reviews` writers (review wizard, governance
route), `show-review.tsx`, `reviews` aggregator. All tenant-scoped, no governance
impact. ~1 migration + 1 backfill + ~4 file edits + a test.

---

## 4. Decision checklist

- [ ] **A. Do nothing** — keep JSON goals + all tables separate. (Fine. The audit's
      unification is not recommended; everything works today.)
- [ ] **B. Add `hr_review_goals`** (§3) — the one safe, valuable change. Additive +
      reversible. Recommend this if you want queryable/linkable review goals.
- [ ] **C. Add the nullable `governance_review_id` bridge** (§1) — only if a CEO is
      also tracked as a tenant employee. Recommend defer.
- [ ] **D. Blanket unify reviews / collapse goals** (the audit's original P2) —
      **not recommended**; conflates board governance with employee HR. Documented
      here so the decision is deliberate, not accidental.

If you pick **B** (and/or C), I'll implement it end-to-end on this branch with tests.
Otherwise the Performance & Development hub is feature-complete as shipped.
