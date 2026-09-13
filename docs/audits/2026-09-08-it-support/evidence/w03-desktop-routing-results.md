# W03 desktop routing verification — 9 September 2026

In progress; this is not whole-package acceptance. Codex in-app browser, current checkout, primary desktop 1440 × 900. The user excluded mobile verification. DOM confirmed the current second build `app-eTyglvnK.js` before setup testing.

## Actual completed steps

The restricted synthetic technician 230 created `IT Support W03 synthetic desk` through the normal Teams form, with itself as manager and member (manager role), and synthetic cover 233 as member. Only these two currently eligible agents appeared. Saved team ID is 1; no real employment or operational ownership is represented.

The queue wizard showed distinct identity, accountability and routing steps. Its manager came from the selected team and the cover picker excluded that manager, offering only synthetic cover 233. Site choices contained only approved synthetic A/B (9403/9404), excluding C.

Browser testing exposed a native activation defect: Continue on the accountability step reused a button node that became `type=submit` during the click, and saved the queue before routing rules were reviewed. It also exposed identity errors persisting after correction and focus remaining on Continue. The unintended saved row was the labelled synthetic queue only. Through its normal Edit action, it was immediately configured as fallback for only Sites 9403/9404 with manager 230, cover 233 and no default technician. The saved card had no readiness gaps. Read-only SQL reconciliation confirms team 1 and queue 1 with exactly that scope (`w03-w01-browser-records-review.json`).

## Fix and focused checks

`resources/js/pages/it/setup/index.tsx` now gives Continue and Save separate React keys, cancels Continue's native default action, and treats implicit form submission on an earlier step as step navigation. Required identity validation clears the previous identity errors and focuses the missing field with `aria-invalid` state. Existing revision/recovery behaviour remains.

The added tests cover explicit-save-only after Continue, distinct native button nodes, identity correction/error focus and implicit early form submission. The full queue file passed **10 tests, 5.87s**, exit 0 (`w03-queue-native-submit-tests.txt`), and scoped ESLint passed. The fix has not yet been rebuilt or retested in the browser; W05 source integration must finish before the next coordinated build.

## Still required

Retest native Continue and keyboard validation on current built assets, queue stale-version review/adoption, requester affected Site A/B and impact/urgency, routed ownership and reasoned manual override/release. No real routing owner activation is claimed. DP01 policy is delegated, but existing real candidate accounts still lack canonical staff profiles and approved Sites.
