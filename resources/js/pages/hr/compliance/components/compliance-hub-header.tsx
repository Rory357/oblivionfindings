/* Shared hub header: the golden hero band + tab strip, rendered identically on
 * every Compliance route so the hero "spans the whole hub". Pages pass the hero
 * payload + an onWizard opener; the stat cluster / quick actions are wired here. */
import { router } from '@inertiajs/react';
import { Car, ClipboardCheck, Download, Plus, ShieldCheck } from 'lucide-react';

import { ComplianceTabs, type ComplianceTab } from '@/components/hr/compliance-tabs';
import { ComplianceHubHero, type HeroChip } from './compliance-hero';
import type { WizardType } from './compliance-wizards';

export type HeroPayload = {
    role: string;
    site: string;
    chips: HeroChip[];
    needs: { key: string; label: string; tab: string; status?: string }[];
    summary: {
        total_staff: number;
        fully_compliant: number;
        expiring_total: number;
        expired_total: number;
        hard_stops: number;
        shifts_affected: number;
    };
};

const todayLabel = new Date().toLocaleDateString('en-NZ', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

function go(path: string) {
    router.visit(path);
}

function applyOverviewStatus(status: string) {
    router.get('/hr/compliance', { status }, { preserveScroll: true });
}

export function ComplianceHubHeader({
    hero,
    active,
    counts,
    can,
    onWizard,
}: {
    hero: HeroPayload;
    active: ComplianceTab;
    counts?: Partial<Record<ComplianceTab, number>>;
    can: { manage?: boolean; vetting?: boolean; driver?: boolean };
    onWizard: (type: WizardType) => void;
}) {
    const s = hero.summary;
    const pct = s.total_staff > 0 ? Math.round((s.fully_compliant / s.total_staff) * 100) : 0;

    const stats = [
        { label: 'Staff tracked', value: s.total_staff, onClick: () => go('/hr/compliance') },
        { label: 'Fully compliant', value: `${pct}%`, onClick: () => applyOverviewStatus('fully_compliant') },
        { label: 'Expiring ≤30d', value: s.expiring_total, amber: true, onClick: () => go('/hr/compliance/calendar') },
        { label: 'Expired', value: s.expired_total, amber: true, onClick: () => applyOverviewStatus('has_expired') },
        { label: 'Hard-stops', value: s.hard_stops, amber: true, onClick: () => applyOverviewStatus('has_expired') },
        { label: 'Shifts affected', value: s.shifts_affected, onClick: () => go('/hr/compliance/calendar') },
    ];

    const actions = [
        ...(can.manage
            ? [
                  { icon: ClipboardCheck, label: 'Record compliance', onClick: () => onWizard('record') },
                  { icon: Plus, label: 'Add requirement', onClick: () => onWizard('requirement') },
              ]
            : []),
        ...(can.vetting ? [{ icon: ShieldCheck, label: 'Add vetting check', onClick: () => onWizard('vetting') }] : []),
        ...(can.driver ? [{ icon: Car, label: 'Add driver', onClick: () => onWizard('driver') }] : []),
        { icon: Download, label: 'Export', onClick: () => go('/hr/compliance/export?dataset=staff') },
    ];

    const needs = hero.needs.map((n) => ({
        key: n.key,
        label: n.label,
        onClick: () => (n.status ? applyOverviewStatus(n.status) : go(`/hr/compliance/${n.tab === 'calendar' ? 'calendar' : ''}`)),
    }));

    return (
        <>
            <ComplianceHubHero today={todayLabel} role={hero.role} site={hero.site} chips={hero.chips} stats={stats} actions={actions} needs={needs} />
            <ComplianceTabs active={active} counts={counts} />
        </>
    );
}
