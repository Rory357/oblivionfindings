# Focused rule and date authority

The following are exact later user messages in this Asset Profile chat. They are the authority for the bounded changes; publication approval is not their source.

1. File-preview guide addition and implementation: “ok can you please add that file preveiw to rory design rules aswell and look for any gaps. if any gaps please add to the implentation and start implementation you implement please”.
2. Implementation scope, in reply to the explicit shared-viewer versus full-profile question: “Full Asset Profile, including documents”.
3. Remaining implementation: “ok please get this to complete”.
4. Date conformance correction with the purchase/warranty native-input screenshot: “these dates does not follow design rules”.

The design-rule diff is narrow: DESIGN.md adds the file-viewer contract and one conformance item; POPUP_STYLE_GUIDE.md adds the file-preview section and one checklist item. FILE_PREVIEW_STYLE_GUIDE.md is the detailed new contract. All additions identify Stephan's approval and do not claim Rory personally reviewed them. No calendar/date/mobile rule text was rewritten. Inspect `git diff ba5bff2e8 -- DESIGN.md design_styles/POPUP_STYLE_GUIDE.md design_styles/FILE_PREVIEW_STYLE_GUIDE.md` for the full candidate rule delta.

The date correction composes the existing shared DatePicker/LeaveCalendarRange in the purchase, warranty, inspection and maintenance fields. Its narrow shared-component changes are explicit button types, opt-in optional-date clearing (required callers retain their contract), and viewport-aware popover placement/width. Desktop retains the side calendar; mobile opens vertically to keep the same calendar and footer reachable. This is implementation of the existing popup rule “Keep the calendar within the modal's scroll/focus bounds and the action footer reachable”, under the user's date-conformance and completion instructions. It is not a new global visual style or a waiver of date-only/timezone semantics. Existing optional/required tests and desktop/mobile browser evidence cover the behaviour.

The frozen approved mockups remain byte-preserved, including all 42 v9 manifest entries. The implementation uses real source records and the shared application shell; synthetic fixture values are not production policy.
