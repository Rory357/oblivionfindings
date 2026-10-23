/**
 * Record a restrictive intervention, or add a behaviour-support plan, as a
 * full page (approved H&S drawings pg-hsn-103, 103b). The capture forms are
 * unchanged — their fields, checks, prescoping (a Respite stay locks the
 * person and Site) and unsaved-draft guard all stay; this page only frames
 * them: the header, the switch between the two (for people who may create
 * plans) and one card.
 */
import { RestraintEventCommandForm } from '@/components/health-safety/restraints/event-command-form';
import { BehaviourSupportPlanCommandForm } from '@/components/health-safety/restraints/plan-command-form';
import type { RestraintPickers } from '@/components/health-safety/restraints/types';
import { PageHeader, PageHeaderRail, PageLayout } from '@/components/page';
import { Surface } from '@/components/signature/blocks';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import {
    RESTRAINT_REGISTER,
    restraintPositive,
    restraintReturn,
} from '@/lib/health-safety/restraint-navigation';
import { Head, router, usePage } from '@inertiajs/react';
import { BookOpen, ShieldAlert } from 'lucide-react';

type Props = {
    pickers: Partial<RestraintPickers>;
    return_to?: string | null;
    restraint_href?: string | null;
    plan_href?: string | null;
    can: { capture: boolean; create_plan: boolean };
    capture_context?: {
        client_id: number;
        site_id: number;
        respite_stay_id: number;
        store_href: string;
    } | null;
};
export default function RestraintReport({
    pickers,
    return_to,
    restraint_href,
    plan_href,
    can,
    capture_context,
}: Props) {
    const { url } = usePage();
    const query = new URLSearchParams(url.split('?')[1]);
    const path = url.split('?')[0];
    const kind = query.get('kind') === 'plan' ? 'plan' : 'event';
    const drafts = useSafetyWorkspaceDrafts();
    const returnTo = return_to ? restraintReturn(return_to) : '/my-day';
    const prescope = {
        clientId:
            capture_context?.client_id ??
            restraintPositive(query.get('client_id')) ??
            undefined,
        siteId:
            capture_context?.site_id ??
            restraintPositive(query.get('site_id')) ??
            undefined,
        stayId: capture_context?.respite_stay_id,
        endpoint: capture_context?.store_href,
        locked: !!capture_context,
    };
    const checkHref =
        path + '?' + new URLSearchParams({ ...Object.fromEntries(query), kind });
    const cancel = () => drafts.request(() => router.get(returnTo));
    const title =
        kind === 'plan'
            ? 'Add behaviour-support plan'
            : 'Record restrictive intervention';
    // The register crumb only when the person came from it (reporters may not have register access).
    const fromRegister = returnTo.startsWith(RESTRAINT_REGISTER);
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                ...(fromRegister
                    ? [
                          { title: 'Health & safety', href: '/health-safety' },
                          {
                              title: 'Safety registers',
                              href: '/health-safety/registers',
                          },
                          {
                              title: 'Restrictive interventions',
                              href: returnTo,
                          },
                      ]
                    : []),
                { title, href: url },
            ]}
        >
            <Head title={title} />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            variant="profile"
                            wrapTitle
                            backHref={returnTo}
                            icon={kind === 'plan' ? BookOpen : ShieldAlert}
                            title={title}
                            subline={
                                capture_context
                                    ? 'Record facts for this person’s current Respite stay. Review and approval retain their own permissions.'
                                    : kind === 'plan'
                                      ? 'Saves a draft · review and approval happen on the plan’s own page'
                                      : 'Record actual facts, then continue each review and approval in its own workspace.'
                            }
                            rail={
                                can.create_plan ? (
                                    <PageHeaderRail
                                        items={[
                                            {
                                                key: 'event',
                                                label: 'Intervention',
                                                icon: ShieldAlert,
                                            },
                                            {
                                                key: 'plan',
                                                label: 'Behaviour-support plan',
                                                icon: BookOpen,
                                            },
                                        ]}
                                        value={kind}
                                        showFind={false}
                                        onSelect={(next) =>
                                            drafts.request(() => {
                                                const target =
                                                    new URLSearchParams(query);
                                                target.set('kind', next);
                                                router.replace({
                                                    url: path + '?' + target,
                                                    preserveState: true,
                                                    preserveScroll: true,
                                                });
                                            })
                                        }
                                        ariaLabel="Safety capture type"
                                    />
                                ) : undefined
                            }
                        />
                    }
                >
                    <Surface aria-label={title}>
                        <div className="p-5 sm:p-6">
                            {kind === 'plan' ? (
                                <BehaviourSupportPlanCommandForm
                                    key="create-plan"
                                    detail={null}
                                    command="create"
                                    pickers={pickers}
                                    prescope={prescope}
                                    captureAllowed={can.create_plan}
                                    captureCheckHref={checkHref}
                                    returnTo={returnTo}
                                    onCancel={cancel}
                                    onDone={(href) =>
                                        router.get(href ?? plan_href ?? returnTo)
                                    }
                                />
                            ) : (
                                <RestraintEventCommandForm
                                    key="create-event"
                                    detail={null}
                                    command="create"
                                    pickers={pickers}
                                    prescope={prescope}
                                    captureAllowed={can.capture}
                                    captureCheckHref={checkHref}
                                    returnTo={returnTo}
                                    onCancel={cancel}
                                    onDone={(href) =>
                                        router.get(
                                            href ?? restraint_href ?? returnTo,
                                        )
                                    }
                                />
                            )}
                        </div>
                    </Surface>
                </PageLayout>
                {drafts.confirmation}
            </SafetyFormDraftContext.Provider>
        </AppLayout>
    );
}
