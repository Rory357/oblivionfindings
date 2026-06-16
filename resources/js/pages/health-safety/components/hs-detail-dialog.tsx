/* Read-only worklist detail (WS4) — opened from a worklist row (click or the right-click
 * "View detail"). Built on the Add-Client WizardShell chrome (single pane + footer Options
 * bar) so it matches every other popup. Footer actions deep-link to the real register /
 * client / staff (the ids the WS1 worklist builders emit). Tokens only; no fake data. */
import { Button } from '@/components/ui/button';
import { ReviewCard, ReviewRow, WizardShell, type WizardStep } from '@/components/wizard/shell';
import { router } from '@inertiajs/react';
import {
    ClipboardList,
    ExternalLink,
    Printer,
    User,
    UserCog,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';

export type HsDetailRow = { label: string; value: ReactNode };

export type HsDetail = {
    title: string;
    description: string;
    railIcon: LucideIcon;
    railTitle: string;
    railSub: string;
    cardTitle: string;
    cardIcon: LucideIcon;
    rows: HsDetailRow[];
    registerUrl?: string | null;
    registerLabel?: string | null;
    clientId?: number | null;
    staffId?: number | null;
};

const STEP: WizardStep[] = [
    { key: 'detail', label: 'Detail', blurb: 'Record summary', icon: ClipboardList },
];

export function HsDetailDialog({ detail, onClose }: { detail: HsDetail; onClose: () => void }) {
    return (
        <WizardShell
            open
            onClose={onClose}
            title={detail.title}
            description={detail.description}
            railIcon={detail.railIcon}
            railTitle={detail.railTitle}
            railSub={detail.railSub}
            steps={STEP}
            stepIndex={0}
            onStepClick={() => {}}
            pct={null}
            footerStart={
                <Button type="button" variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={
                <>
                    {detail.registerUrl ? (
                        <Button type="button" variant="outline" onClick={() => router.visit(detail.registerUrl!)}>
                            <ExternalLink className="h-4 w-4" /> {detail.registerLabel ?? 'View register'}
                        </Button>
                    ) : null}
                    {detail.clientId ? (
                        <Button type="button" variant="ghost" onClick={() => router.visit(`/clients/${detail.clientId}`)}>
                            <User className="h-4 w-4" /> Client
                        </Button>
                    ) : null}
                    {detail.staffId ? (
                        <Button type="button" variant="ghost" onClick={() => router.visit(`/staff/${detail.staffId}`)}>
                            <UserCog className="h-4 w-4" /> Staff
                        </Button>
                    ) : null}
                    <Button type="button" variant="ghost" onClick={() => window.print()}>
                        <Printer className="h-4 w-4" /> Print
                    </Button>
                </>
            }
        >
            <ReviewCard icon={detail.cardIcon} title={detail.cardTitle} span>
                {detail.rows.map((r, i) => (
                    <ReviewRow key={i} label={r.label} value={r.value} />
                ))}
            </ReviewCard>
        </WizardShell>
    );
}

export default HsDetailDialog;
