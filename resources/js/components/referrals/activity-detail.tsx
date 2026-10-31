import { Button } from '@/components/ui/button';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import { History } from 'lucide-react';
import { useRef, useState } from 'react';
import { label, type Activity } from './types';

function RecordedValue({ value, name }: { value: unknown; name: string }) {
    if (value === null || value === undefined || value === '')
        return <span className="text-muted-foreground">Not recorded</span>;
    if (typeof value === 'boolean') return <span>{value ? 'Yes' : 'No'}</span>;
    if (Array.isArray(value)) {
        if (!value.length)
            return <span className="text-muted-foreground">None recorded</span>;
        if (name.endsWith('_ids'))
            return (
                <span>
                    {value.length} linked records retained with this event
                </span>
            );
        return (
            <ol className="space-y-4">
                {value.map((entry, index) => (
                    <li key={index} className="space-y-2 rounded-md border p-3">
                        <p className="text-caption text-muted-foreground">
                            Item {index + 1}
                        </p>
                        <RecordedValue value={entry} name={name} />
                    </li>
                ))}
            </ol>
        );
    }
    if (typeof value === 'object') {
        const entries = Object.entries(value as Record<string, unknown>).filter(
            ([key]) => !key.endsWith('_id') && key !== 'case_version',
        );
        return (
            <dl className="space-y-4">
                {entries.map(([key, entry]) => (
                    <div key={key} className="space-y-1">
                        <dt className="text-caption text-muted-foreground">
                            {label(key)}
                        </dt>
                        <dd className="text-sm break-words whitespace-pre-wrap">
                            <RecordedValue value={entry} name={key} />
                        </dd>
                    </div>
                ))}
            </dl>
        );
    }
    const text = String(value);
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(text))
        return <span>{formatDateTimeLong(text)}</span>;
    if (/^\d{4}-\d{2}-\d{2}$/.test(text))
        return <span>{formatDateOnly(text)}</span>;
    return (
        <span>
            {[
                'status',
                'stage',
                'state',
                'outcome',
                'channel',
                'basis',
                'kind',
                'audience',
                'submitted_by',
            ].includes(name)
                ? label(text)
                : text}
        </span>
    );
}

export function ReferralActivityDetail({
    event,
    onClose,
}: {
    event: Activity;
    onClose: () => void;
}) {
    const [section, setSection] = useState(0);
    const body = useRef<HTMLDivElement>(null);
    const snapshots = ['previous', 'current', 'submission'].filter(
        (key) => event.details[key] && typeof event.details[key] === 'object',
    );
    const steps = [
        {
            key: 'details',
            label: 'Recorded details',
            blurb: 'Who, when and what happened',
            icon: History,
        },
        ...snapshots.map((key) => ({
            key,
            label:
                key === 'previous'
                    ? 'Before this change'
                    : key === 'current'
                      ? 'After this change'
                      : 'External submission',
            blurb: 'Preserved event record',
            icon: History,
        })),
    ];
    const visible = section
        ? event.details[steps[section].key]
        : Object.fromEntries(
              Object.entries(event.details).filter(
                  ([key]) => !snapshots.includes(key),
              ),
          );
    return (
        <WizardShell
            open
            onClose={onClose}
            title={label(event.kind.replaceAll('.', '_'))}
            description={`${formatDateTimeLong(event.occurred_at)} · ${event.actor_name ?? 'Website / system'}`}
            railTitle="Activity record"
            railIcon={History}
            railSub="This is the information recorded at the time. Current permissions, decisions and readiness may have changed."
            steps={steps}
            stepIndex={section}
            headerLabel={steps[section].label}
            bodyRef={body}
            onStepClick={(index) => {
                setSection(index);
                requestAnimationFrame(() => {
                    body.current?.scrollTo({ top: 0 });
                    body.current
                        ?.querySelector<HTMLElement>('[data-heading]')
                        ?.focus();
                });
            }}
            footerEnd={
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <div className="space-y-5">
                <h2 className="text-section-title" data-heading tabIndex={-1}>
                    {steps[section].label}
                </h2>
                {section === 0 && (
                    <ReviewCard title="Event" icon={History}>
                        <ReviewRow
                            label="Recorded by"
                            value={
                                event.actor_name ??
                                (event.actor_id
                                    ? 'Former staff member'
                                    : 'Website / system')
                            }
                        />
                        <ReviewRow
                            label="When"
                            value={formatDateTimeLong(event.occurred_at)}
                        />
                        <ReviewRow
                            label="Access"
                            value={label(event.audience)}
                        />
                    </ReviewCard>
                )}
                <RecordedValue value={visible} name="details" />
            </div>
        </WizardShell>
    );
}
