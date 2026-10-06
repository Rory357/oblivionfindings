import { RecordPicker } from '@/components/people-locations/record-picker';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import { Check, Download, FileText, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { reportRequest, requestJson, type Props } from './_types';

type Preview = {
    site: { id: number; name: string };
    nz_date: string;
    purpose: string;
    controlled_notice: string | null;
    first_page: {
        name: string;
        allergies: {
            allergen: string;
            severity: string | null;
            reaction: string | null;
        }[];
        scheduled: {
            medicine: string;
            dosage: string | null;
            ordered_time: string;
        }[];
        prn: { medicine: string; dosage: string | null }[];
    } | null;
};

const steps = [
    {
        key: 'scope',
        label: 'Choose house',
        blurb: 'One house, today or tomorrow in New Zealand.',
        icon: FileText,
    },
    {
        key: 'review',
        label: 'Review preview',
        blurb: 'Check the first page and fixed purpose.',
        icon: Check,
    },
];

export function DowntimePackDialog({
    props,
    pack,
    online,
    onClose,
}: {
    props: Pick<Props, 'filters' | 'sites'>;
    pack: NonNullable<Props['downtime_pack']>;
    online: boolean;
    onClose: () => void;
}) {
    const [site, setSite] = useState<number | null>(
        props.filters.site_id ??
            (props.sites.length === 1 ? props.sites[0].id : null),
    );
    const [day, setDay] = useState(pack.today);
    const [step, setStep] = useState(0);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [done, setDone] = useState(false);

    function changeScope(nextSite: number | null, nextDay: string) {
        setSite(nextSite);
        setDay(nextDay);
        setPreview(null);
        setError('');
    }

    async function loadPreview() {
        if (!online || busy || !site || !pack.allowed) return;
        setBusy(true);
        setError('');
        try {
            const result = (await requestJson('/emar/downtime/pack/preview', {
                site_id: site,
                nz_date: day,
            })) as Preview;
            setPreview(result);
            setStep(1);
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'The preview could not be loaded. Your choices have been kept.',
            );
        } finally {
            setBusy(false);
        }
    }

    async function download() {
        if (!online || busy || !preview || !pack.allowed) return;
        setBusy(true);
        setError('');
        try {
            const response = await reportRequest('/emar/downtime/pack', {
                site_id: preview.site.id,
                nz_date: preview.nz_date,
            });
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download =
                response.headers
                    .get('Content-Disposition')
                    ?.match(/filename="([^"]+)"/)?.[1] ??
                `downtime-pack-${preview.nz_date}.pdf`;
            document.body.append(anchor);
            anchor.click();
            anchor.remove();
            setTimeout(() => URL.revokeObjectURL(url), 60_000);
            setDone(true);
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'The pack could not be made. Your choices have been kept.',
            );
        } finally {
            setBusy(false);
        }
    }

    return (
        <WizardShell
            frontline
            open
            onClose={() => !busy && onClose()}
            title="Make downtime pack"
            description="Prepare a paper copy in case the system is down."
            railIcon={Download}
            railTitle="Downtime pack"
            railSub="PDF"
            steps={steps}
            stepIndex={step}
            onStepClick={(next) => !busy && next < step && setStep(next)}
            pct={step ? 100 : site ? 50 : 0}
            footerStart={
                <Button
                    variant="outline"
                    disabled={busy}
                    onClick={() => (step ? setStep(0) : onClose())}
                >
                    {step ? 'Back' : 'Cancel'}
                </Button>
            }
            footerEnd={
                step === 0 ? (
                    <Button
                        disabled={busy || !online || !site || !pack.allowed}
                        onClick={loadPreview}
                    >
                        {busy ? 'Loading preview…' : 'Preview pack'}
                    </Button>
                ) : (
                    <Button
                        disabled={busy || !online || !preview || !pack.allowed}
                        onClick={download}
                    >
                        {busy ? 'Preparing pack…' : 'Make pack'}
                    </Button>
                )
            }
            success={
                done ? (
                    <WizardSuccessPane
                        title="Pack ready"
                        blurb="The download has started and its Downtime purpose has been recorded in the audit trail."
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
        >
            <div className="space-y-5 p-5">
                {!online && (
                    <p role="status" className="text-subtle">
                        You’re offline. Your choices are kept; reconnect before
                        preparing the pack.
                    </p>
                )}
                {error && (
                    <p
                        role="alert"
                        className="text-subtle text-status-critical"
                    >
                        {error}
                    </p>
                )}
                {step === 0 && (
                    <>
                        <div className="space-y-2">
                            <Label>House</Label>
                            <RecordPicker
                                label="House"
                                value={site ? String(site) : ''}
                                options={[
                                    { value: '', label: 'Choose one house' },
                                    ...props.sites.map((house) => ({
                                        value: String(house.id),
                                        label: house.name,
                                    })),
                                ]}
                                onChange={(value) =>
                                    changeScope(
                                        value ? Number(value) : null,
                                        day,
                                    )
                                }
                                disabled={busy}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>NZ calendar day</Label>
                            <RecordPicker
                                label="NZ calendar day"
                                value={day}
                                options={[
                                    {
                                        value: pack.today,
                                        label: `Today · ${formatDateOnly(pack.today)}`,
                                    },
                                    {
                                        value: pack.tomorrow,
                                        label: `Tomorrow · ${formatDateOnly(pack.tomorrow)}`,
                                    },
                                ]}
                                onChange={(value) => changeScope(site, value)}
                                disabled={busy}
                            />
                        </div>
                        <p className="text-subtle">
                            The pack includes each permitted person’s scheduled
                            and as-needed medicines, allergies and paper
                            recording spaces.
                        </p>
                        <ReviewCard icon={ShieldCheck} title="Purpose">
                            <ReviewRow label="Purpose" value={pack.purpose} />
                        </ReviewCard>
                    </>
                )}
                {step === 1 && preview && (
                    <>
                        <ReviewCard
                            icon={FileText}
                            title="Pack"
                            onEdit={() => setStep(0)}
                        >
                            <ReviewRow
                                label="House"
                                value={preview.site.name}
                            />
                            <ReviewRow
                                label="Day"
                                value={formatDateOnly(preview.nz_date)}
                            />
                            <ReviewRow
                                label="Purpose"
                                value={preview.purpose}
                            />
                        </ReviewCard>
                        {preview.controlled_notice && (
                            <p className="text-subtle" role="status">
                                {preview.controlled_notice}
                            </p>
                        )}
                        <ReviewCard icon={FileText} title="First page preview">
                            {preview.first_page ? (
                                <>
                                    <ReviewRow
                                        label="Person"
                                        value={preview.first_page.name}
                                    />
                                    <ReviewRow
                                        label="Allergies"
                                        value={
                                            preview.first_page.allergies
                                                .map((allergy) =>
                                                    [
                                                        allergy.allergen,
                                                        allergy.reaction,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · '),
                                                )
                                                .join('; ') ||
                                            'No allergy records in this pack'
                                        }
                                    />
                                    <div className="mt-3 space-y-2">
                                        <p className="text-caption">
                                            Scheduled doses
                                        </p>
                                        {preview.first_page.scheduled.length ? (
                                            preview.first_page.scheduled.map(
                                                (dose, index) => (
                                                    <ReviewRow
                                                        key={index}
                                                        label={
                                                            dose.ordered_time
                                                        }
                                                        value={[
                                                            dose.medicine,
                                                            dose.dosage,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    />
                                                ),
                                            )
                                        ) : (
                                            <p className="text-subtle">
                                                No scheduled doses on this page.
                                            </p>
                                        )}
                                    </div>
                                    <div className="mt-3 space-y-2">
                                        <p className="text-caption">
                                            As-needed medicines
                                        </p>
                                        {preview.first_page.prn.length ? (
                                            preview.first_page.prn.map(
                                                (medicine, index) => (
                                                    <ReviewRow
                                                        key={index}
                                                        label={
                                                            medicine.medicine
                                                        }
                                                        value={medicine.dosage}
                                                    />
                                                ),
                                            )
                                        ) : (
                                            <p className="text-subtle">
                                                No as-needed medicines on this
                                                page.
                                            </p>
                                        )}
                                    </div>
                                </>
                            ) : (
                                <p className="text-subtle">
                                    There are no permitted people in this
                                    house’s pack.
                                </p>
                            )}
                        </ReviewCard>
                    </>
                )}
            </div>
        </WizardShell>
    );
}
