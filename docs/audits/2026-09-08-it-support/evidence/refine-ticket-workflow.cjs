const fs = require('fs');
const path = 'resources/js/components/it/ticket-work-workspace.tsx';
let s = fs.readFileSync(path, 'utf8');
const start = s.indexOf('            {recoveryPending && !proposal ? (');
const end = s.indexOf('            <section', start);
if (start < 0 || end < 0) throw new Error('Recovery controls not found');
s = s.slice(0, start) + '            {!proposal && recoveryControls}\n' + s.slice(end);
s = s.replace('                    </DialogHeader>', '                    </DialogHeader>\n                    {recoveryControls}');
s = s.replace('disabled={busy || !editable}', 'disabled={busy || !editable || draftBlocked}');
const confirmStart = s.indexOf('                title={\n                    pending\n');
const confirmEnd = s.indexOf('            />', confirmStart);
if (confirmStart < 0 || confirmEnd < 0) throw new Error('Confirm not found');
s = s.slice(0, confirmStart) + `                title={pending || recoveryPending ? 'Cancel the unconfirmed request?' : 'Discard entered changes?'}
                description={pending || recoveryPending
                    ? 'The server will cancel this request if it has not saved yet. If it already saved, the ticket will be refreshed and the saved record will remain in place.'
                    : 'Your entered fields and saved draft will be discarded. Saved ticket records remain in place.'}
                confirmText={pending || recoveryPending ? 'Cancel request' : 'Discard changes'}
                onConfirm={() => void discardWork()}
` + s.slice(confirmEnd);
fs.writeFileSync(path, s);
