from pathlib import Path
p = Path('resources/js/pages/operations/handovers/components/handover-detail-dialog.tsx')
s = p.read_text(encoding='utf-8')
flow = s[s.index('                    {/* Flow */}'):s.index('                    {/* Narrative */}')]
notes = s[s.index('                    {/* Narrative */}'):s.index('                    {/* Audit trail */}')]
history = s[s.index('                    {/* Audit trail */}'):s.index('                {/* Footer */}')]
history = history[:history.rfind('                </div>')]
links = s[s.index('                    <div className="flex flex-wrap items-center gap-1.5">', s.index('                {/* Footer */}')):s.index('                    <div className="flex flex-wrap items-center justify-between gap-3">', s.index('                {/* Footer */}'))]
links = links.replace('{h.client ? (\n                            <OptionLink\n                                href={`/emar', '{h.client && medicationSnapshotUrl ? (\n                            <OptionLink\n                                href={`/emar')
s = s.replace("import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';", "import { WizardShell, WizardStepPane } from '@/components/wizard/shell';")
s = s.replace('    Home,\n', '')
s = s.replace('    const [snapshot, setSnapshot]', "    const [section, setSection] = useState(0);\n    useEffect(() => { setSection(0); }, [open, handover?.id]);\n    const [snapshot, setSnapshot]")
start = s.index('function lockNote(')
end = s.index('/** Inline Inertia', start)
s = s[:start] + '''function lockNote(h: Handover) {
    if (h.status === 'draft') return 'Saved draft. It has not been sent to the next worker.';
    if (h.status === 'acknowledged') return 'The incoming worker has acknowledged this handover.';
    return 'Sent to the incoming shift. The submitted record is kept unchanged.';
}

''' + s[end:]
s = s.replace('className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-2.5 py-1.5 text-xs font-semibold text-foreground transition-colors hover:bg-accent"', 'className="inline-flex min-h-11 items-center gap-2 rounded-lg border border-border bg-card px-3 text-sm font-medium hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring"')
start = s.index('    return (\n        <Dialog open=', s.index('export function HandoverDetailDialog'))
s = s[:start] + '''    const needsIncoming = h.status === 'draft' && !h.incoming_shift;
    const editable = h.can_edit && !h.edit_lock;
    const sections = [
        { key: 'notes', label: 'Shift notes', blurb: 'People, site and follow-up', icon: FileText },
        { key: 'next', label: 'Next worker', blurb: 'Who this handover goes to', icon: Users },
        { key: 'history', label: 'History and links', blurb: 'Saved, sent and read', icon: Activity },
    ];
    return (
        <WizardShell
            open={open}
            onClose={() => onOpenChange(false)}
            title="Shift handover"
            description="Read each person's notes, check who receives the handover, and review its history."
            railIcon={FileText}
            railTitle="Shift handover"
            railSub={h.site?.name ?? 'Your shift'}
            steps={sections}
            stepIndex={section}
            onStepClick={setSection}
            headerLabel={sections[section].label}
            pct={null}
            railExtra={<div className="space-y-3 text-sm"><StatusPill status={h.status} /><p className="text-muted-foreground">{note}</p>{needsIncoming && <p className="text-status-warning">Choose an incoming shift before sending.</p>}</div>}
            footerStart={<GuardrailButton variant="outline" className="min-h-11" onClick={() => onOpenChange(false)}>Close</GuardrailButton>}
            footerEnd={<>
                {editable && <GuardrailButton variant={needsIncoming ? 'default' : 'outline'} className="min-h-11" onClick={() => onEdit(h)}><FileText />{needsIncoming ? 'Choose incoming shift' : 'Edit handover'}</GuardrailButton>}
                {h.status === 'draft' && h.can_submit && !needsIncoming && !h.edit_lock && <GuardrailButton className="min-h-11" onClick={() => onSubmit(h)}><Send />Send handover</GuardrailButton>}
                {h.status === 'submitted' && h.can_acknowledge && <GuardrailButton className="min-h-11" onClick={() => onAcknowledge(h)}><Check />I've read this handover</GuardrailButton>}
            </>}
        >
            <WizardStepPane key={`${h.id}-${section}`}>
                <div className="mb-4 border-b border-border pb-4 text-sm text-muted-foreground">
                    {handoverDate(h).toLocaleDateString('en-NZ', { weekday: 'long', day: 'numeric', month: 'long' })}
                    {h.outgoing_shift && <span> · {fmtShiftRange(h.outgoing_shift)}</span>}
                </div>
                {section === 0 && <div className="space-y-4">
''' + notes.replace('Handover narrative', 'Notes for {clientName(h.client)}').replace('No narrative recorded.', 'No notes recorded.') + '''
                </div>}
                {section === 1 && <div className="space-y-4">
''' + flow + '''
                </div>}
                {section === 2 && <div className="space-y-5">
''' + history + links + '''
                </div>}
            </WizardStepPane>
        </WizardShell>
    );
}
'''
p.write_text(s, encoding='utf-8')
