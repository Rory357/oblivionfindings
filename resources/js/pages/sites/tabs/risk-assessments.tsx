import { RaRegisterSection } from '@/components/health-safety/risk-assessments/ra-register-section';
import type {
    RaPickers,
    RaRow,
} from '@/components/health-safety/risk-assessments/types';
import { SafetyRegisterHeader } from './safety-register';

export type SiteRiskAssessmentsData = {
    locked?: boolean;
    assessments: RaRow[];
    pickers: RaPickers;
    can_manage: boolean;
    site: { id: number; name: string };
    href: string;
};

export function SiteProfileRiskAssessments({
    data,
}: {
    data: SiteRiskAssessmentsData;
}) {
    return (
        <div className="space-y-5">
            <SafetyRegisterHeader
                title="Risk assessments"
                description="The complete Site-scoped H&S risk register, with the canonical create, approve, review, residual-risk, supersede and archive workflows."
                href={data.href}
                actionLabel="Open organisation register"
                count={data.assessments.length}
            />
            <RaRegisterSection
                assessments={data.assessments}
                pickers={data.pickers}
                canManage={data.can_manage}
                lockedAssessable={{
                    type: 'site',
                    id: data.site.id,
                    name: data.site.name,
                }}
                title="Site risk assessment register"
            />
        </div>
    );
}
