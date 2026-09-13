const fs = require('node:fs');
function edit(path, change) {
  const before = fs.readFileSync(path, 'utf8').replace(/\r\n/g, '\n');
  const after = change(before);
  if (before === after) throw new Error(`No change: ${path}`);
  fs.writeFileSync(path, after);
}
function replace(s, a, b) {
  if (!s.includes(a)) throw new Error(`Missing anchor: ${a.slice(0, 90)}`);
  return s.replace(a, b);
}
edit('resources/js/components/it/ticket-reply-composer.tsx', s => {
  s = replace(s, "import { Button } from '@/components/ui/button';", "import { Button } from '@/components/ui/button';\nimport { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';");
  s = replace(s, '    useCallback,', '    type ReactNode,\n    useCallback,');
  s = replace(s, 'interface Props {', 'interface Props {\n    modal?: boolean;');
  const start = s.indexOf("    const [audience, setAudience]");
  const end = s.indexOf('    const [revokedEpoch', start);
  s = s.slice(0, start) + `    const [audience, setAudience] = useState<'public' | 'internal'>(props.modal && props.canInternal ? 'internal' : 'public');
    const [open, setOpen] = useState(false);
    const composerRoot = useRef<HTMLElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    useEffect(() => {
        const root = composerRoot.current;
        const openNote = (event: Event) => {
            const requested = (event as CustomEvent).detail;
            setAudience(requested === 'internal' && props.canInternal ? 'internal' : 'public');
            setOpen(true);
        };
        root?.addEventListener('ticket-open-note', openNote);
        return () => root?.removeEventListener('ticket-open-note', openNote);
    }, [props.canInternal]);
` + s.slice(end);
  const railStart = s.indexOf('            {props.canInternal && (');
  const railEnd = s.indexOf('            <div hidden={active', railStart);
  const rail = s.slice(railStart, railEnd).trim().slice(1, -1).replace('<MessageSquare className="size-4" /> Reply', '<MessageSquare className="size-4" /> {props.modal ? \'Public reply\' : \'Reply\'}');
  s = s.slice(0, railStart) + `            {props.modal ? (
                <Button ref={trigger} className="min-h-11" onClick={() => {
                    if (!work[active].dirty && dirty) setAudience(active === 'public' ? 'internal' : 'public');
                    setOpen(true);
                }}><MessageSquare className="size-4" />{dirty ? 'Continue note' : 'Add note'}</Button>
            ) : audienceControls}
` + s.slice(railEnd);
  s = replace(s, '    return (\n        <section', `    const audienceControls = (${rail});
    const dialog = props.modal ? {
        open, switching: open, controls: audienceControls,
        onOpenChange: (next: boolean) => { if (next || !busy) setOpen(next); },
        onSaved: () => setOpen(false),
        returnFocus: () => trigger.current?.focus(),
    } : undefined;
    return (
        <section
            ref={composerRoot}
            data-ticket-composer`);
  s = replace(s, "                    isInternal={false}", "                    dialog={dialog ? { ...dialog, open: open && active === 'public' } : undefined}\n                    isInternal={false}");
  s = replace(s, '                        isInternal\n', "                        dialog={dialog ? { ...dialog, open: open && active === 'internal' } : undefined}\n                        isInternal\n");
  s = replace(s, '    accessState,\n}: Props & {', '    accessState,\n    dialog,\n}: Props & {\n    dialog?: { open: boolean; switching: boolean; controls: ReactNode; onOpenChange: (open: boolean) => void; onSaved: () => void; returnFocus: () => void };');
  s = replace(s, '        toast.success(itCommentCommitMessage(result));', '        toast.success(itCommentCommitMessage(result));\n        if (sameWork && result.canonical_ticket_id === ticketId) dialog?.onSaved();');
  s = replace(s, '    return (\n        <div className="space-y-4">', '    const content = (\n        <div className="space-y-4">');
  const tail = '        </div>\n    );\n}';
  if (!s.endsWith(tail + '\n')) throw new Error('Unexpected composer ending');
  s = s.slice(0, -(tail.length + 1)) + `        </div>
    );
    if (!dialog) return content;
    return (
        <Dialog open={dialog.open} onOpenChange={dialog.onOpenChange}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-4xl"
                onOpenAutoFocus={(event) => {
                    if (editable) { event.preventDefault(); document.getElementById(inputId)?.focus(); }
                }}
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    if (!dialog.switching) dialog.returnFocus();
                }}>
                <DialogHeader>
                    <DialogTitle>Add note</DialogTitle>
                    <DialogDescription>Write a public reply or internal note and record the time spent. Closing keeps your entered work on this ticket.</DialogDescription>
                </DialogHeader>
                {dialog.controls}
                {content}
            </DialogContent>
        </Dialog>
    );
}
`;
  return s;
});
edit('resources/js/components/it/ticket-thread.tsx', s => replace(s, '                        <TicketReplyComposer\n', '                        <TicketReplyComposer\n                            modal\n'));
edit('resources/js/pages/it/tickets/show.tsx', s => {
  const start = s.indexOf('    const focusReply = () => {');
  const end = s.indexOf('    const showWorkNote', start);
  if (start < 0 || end < 0) throw new Error('Missing show anchors');
  s = s.slice(0, start) + `    const openNote = (audience: 'public' | 'internal') => {
        const open = () => conversationRef.current
            ?.querySelector('[data-ticket-composer]')
            ?.dispatchEvent(new CustomEvent('ticket-open-note', { detail: audience }));
        if (activeTab === 'messages') open();
        else visitTab('messages', open);
    };
    const focusReply = () => openNote('public');
    const focusWorkNote = () => openNote('internal');
` + s.slice(end);
  return replace(s, "? 'Add work note'", "? 'Add note'");
});
