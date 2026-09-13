# Shared header keyboard recovery

11 September 2026. W27 keyboard acceptance follow-up found while verifying W11 Operations guidance. This is not whole-module UI acceptance.

## Browser-confirmed defects

The existing shared `PageHeaderRail` used tab roles but lacked arrow/Home/End navigation (recorded in prior W11 browser evidence). During the current owned desktop session, the technician opened Find with Tab then Enter; Escape closed the dialog but focus returned to the page container instead of Find. The settled accessibility snapshot explicitly showed the container as the focused element.

## Implementation

The existing shared header now has one tab entry point, arrow-key wrapping, Home/End, and manual activation. Moving focus does not invoke the page's selection callback, preserving existing navigation and unsaved-work decisions. External selection changes and removal of a focused item maintain a valid tab entry point. The existing view palette restores focus to Find on cancellation, or to the chosen tab on selection. Custom page-owned Find handlers remain page-owned. No visual classes, design tokens or design source documents were changed for these corrections.

Changed files: `resources/js/components/page/page-header.tsx` and `resources/js/components/page/page-header-rail.test.tsx`.

## Verification

- `w27-rail-tests.txt`: 15 tests passed in 3.88 seconds across the new shared rail tests and existing Operations component tests. Covers focus wrapping, Home/End, no selection on arrows, cancellation by Escape and Close, filter/Enter selection, and dynamic item/selection changes.
- `w27-rail-eslint.txt`: focused ESLint completed with exit 0 and no warnings.
- `w27-rail-build.txt`: exit 0 in 3m20s; final manifest `f2834ff231cc6ce5521e7838ac2dfd1c62a089bb29203bf4bd1e27fd1b2ce0dc`, entry `app-CE5taJm1.js`.
- Actual desktop re-verification on owned runtime `fa19d4c835ae4072`, PHP PID 26484, in-app tab 27: ArrowLeft moved Operations focus to API without changing the selected Operations view; Home focused Teams; ArrowLeft wrapped back, then Tab/Enter opened Find. Escape closed the dialog and the settled accessibility tree showed Find as the focused button. Filtering for Queues and pressing Enter navigated to `tab=queues`, selected Queues and focused its tab. End focused Operations while Queues remained selected; Enter then navigated to Operations and kept focus on its selected tab. No resize was performed.

The reviewed asset refresh (`w27-rail-refresh-preview.json` / `w27-rail-refresh-applied.json`) changed only assets. Final fingerprint `a88ba388b60546be2f70b104eb7f46b3eb5c97be75854649488f941cb61762eb` was used for teardown. Owned tab 27 closed, user tab 3 preserved, cleanup67635 exit 0 and independent `w11-interruption-browser-postflight.json` confirms exact schema/root absence. No runtime remains active. Close-button cancellation and dynamic removal were verified by component tests; they were not separately repeated in this browser journey. Whole W27 remains incomplete.
