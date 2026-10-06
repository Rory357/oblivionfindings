/* Dose details — opened from a row's View (a recorded or not-yet-due dose)
 * or its menu (approved P01 v2 `DoseDetailDialog`). Read-only: corrections
 * stay on the medication record (MAR), which keeps the original and shows
 * the change. */
import { Link } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import type { ReactNode } from 'react';

import { KV } from '@/components/emar/record-dose/parts';
import { PersonDisc } from '@/components/lists/entity-cells';
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';

import { clockTime, nzTime } from '../_rows';
import type { ScheduleRow } from '../types';

const OUTCOME: Record<string, string> = {
    given: 'Given',
    refused: 'Refused',
    withheld: 'Withheld',
    missed: 'Missed',
};

export function RecordedDetailDialog({
    row,
    person,
    dateLabel,
    canViewMar,
    onClose,
}: {
    row: ScheduleRow;
    person: { preferred: string; legal: string };
    dateLabel: string;
    canViewMar: boolean;
    onClose: () => void;
}) {
    const r = row.recorded;
    const outcome = r
        ? `${r.reoffer_of_id ? (r.status === 'given' ? 'Given after re-offer' : 'Refused again') : (OUTCOME[r.status] ?? r.status)}${r.status !== 'given' && r.reason_label ? ` · ${r.reason_label}` : ''}`
        : row.status === 'upcoming'
          ? `Not yet due — window opens ${nzTime(row.window_opens_at)}`
          : row.status === 'away'
            ? (row.away_reason ?? 'Away — not due while away')
            : row.status === 'pending_check'
              ? 'Waiting for the order check'
              : 'Not recorded yet';
    const also = r
        ? [
              r.amount_mode === 'less' && r.dose_given
                  ? `Less than ordered: ${r.dose_given}`
                  : null,
              r.amount_mode === 'more' && r.dose_given
                  ? `More than ordered: ${r.dose_given} · medication error reported`
                  : null,
              r.late_reason
                  ? 'Outside the dose window — reason recorded'
                  : null,
              r.notes ? `Note: ${r.notes}` : null,
          ].filter((w): w is string => !!w)
        : [];
    const rows: [ReactNode, ReactNode][] = [
        ['Outcome', outcome],
        ['Amount', r?.dose_given ?? (r ? (row.dose ?? '—') : '—')],
        [
            'Recorded',
            r ? `${clockTime(r.time)}${r.by ? ` by ${r.by}` : ''}` : '—',
        ],
    ];
    if (r?.witness)
        rows.push([
            r.second_person_kind === 'witness' || row.is_controlled
                ? 'Witnessed by'
                : 'Confirmed by',
            <span key="w" className="inline-flex flex-wrap items-center gap-2">
                {r.witness}
                <StatusBadge
                    variant={
                        r.second_person_status === 'not_confirmed'
                            ? 'warning'
                            : 'success'
                    }
                    size="sm"
                >
                    {r.second_person_status === 'not_confirmed'
                        ? 'Not confirmed'
                        : 'Witness PIN'}
                </StatusBadge>
            </span>,
        ]);
    if (also.length)
        rows.push([
            'Also recorded',
            <ul key="a" className="list-disc pl-4">
                {also.map((w) => (
                    <li key={w}>{w}</li>
                ))}
            </ul>,
        ]);

    return (
        <SettingsModal
            frontline
            width={720}
            title={`${row.medication_name} · ${person.preferred}`}
            description={`${row.dose ? `${row.dose} · ` : ''}${nzTime(row.scheduled_for)} dose · ${dateLabel} · times in NZ time`}
            onClose={onClose}
            footer={
                <>
                    {canViewMar && row.mar_url ? (
                        <Button
                            className="frontline-tap"
                            variant="outline"
                            asChild
                        >
                            <Link href={row.mar_url}>
                                <ClipboardList
                                    className="size-4"
                                    aria-hidden="true"
                                />{' '}
                                Open {person.preferred}’s medication record
                            </Link>
                        </Button>
                    ) : null}
                    <Button
                        className="frontline-tap"
                        onClick={onClose}
                        autoFocus
                    >
                        Close
                    </Button>
                </>
            }
        >
            <div className="flex items-center gap-3">
                <PersonDisc name={person.legal} size={36} />
                <span className="flex flex-col">
                    <span className="text-sm font-semibold">
                        {person.preferred}
                    </span>
                    <span className="text-caption">{person.legal}</span>
                </span>
            </div>
            <KV rows={rows} />
        </SettingsModal>
    );
}

export default RecordedDetailDialog;
