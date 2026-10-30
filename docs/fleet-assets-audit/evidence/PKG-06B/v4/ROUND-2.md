# Additional audit round 2 — documents and evidence

Frozen v4, continuing v3. Found and corrected shared placeholder source IDs for unrelated new uploads, missing uploader attribution, invisible staged report attachments, archive without failure recovery, empty-library navigation still referring to the former Documents group, and insufficient photo-specific file validation.

New uploads now get distinct synthetic document-set references; replacement retains the selected source and classification, version reason, earlier file and uploader identity. Archive requires a reason, retains a failed draft, records actor/time in the original's history, and offers retry. Report attachments are visible/removable. Profile photos accept images and display only a local blob after confirmation. Empty document libraries start at zero and can show their first staged local document. Header count follows the actual local file list.

Chromium passed five checks: replacement failure/retry with source DS-104-1 and v2/v3/v4 retained; archive failure/retry; first document from empty; non-image rejection for profile photo; confirmed photo using only a local blob. No runtime errors recorded. One harness lookup initially used the initial upload label instead of the recovery label; corrected and passed. No production upload, scanning or private-download enforcement is claimed.

The following round covers navigation, access states, focus and desktop fit across the entire candidate.
