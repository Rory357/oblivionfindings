/* The Overview's role-based start (approved drawing D1, MASTER for Stephan, 30 Oct). The start follows the person's
 * role: the health and safety lead sees "Your queue", a team lead "Your site", everyone else "Your start". Every start
 * offers the safeguarding choice; the frontline start also lists the other things they can report, straight to the
 * right form. Choices come from the same permission check as "Report something". Tokens only. */
import { SectionHead, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { ShieldQuestion } from 'lucide-react';
import { launcherWorkflows } from './report-launcher';

type Lens = 'governance' | 'manager' | 'frontline' | string;

/** The start's heading for this role (exported for tests). */
export function startTitle(lens: Lens): string {
    if (lens === 'governance') return 'Your queue';
    if (lens === 'manager') return 'Your site';
    return 'Your start';
}

export function RoleStart({
    lens,
    onWorkflow,
}: {
    lens: Lens;
    onWorkflow: (key: string) => void;
}) {
    const can = usePage<SharedData>().props.auth?.can as unknown as Parameters<
        typeof launcherWorkflows
    >[0];
    const workflows = launcherWorkflows(can);
    const safeguarding = workflows.find((w) => w.key === 'safeguarding');

    return (
        <div className="grid gap-4">
            <SectionHead
                title={startTitle(lens)}
                sub={
                    lens === 'governance'
                        ? 'The work only you can move on, most urgent first'
                        : lens === 'manager'
                          ? 'What needs you at your sites'
                          : undefined
                }
            />
            {lens === 'frontline' ? (
                workflows.length > 0 ? (
                    <Surface
                        title="Something happened?"
                        sub="Tell us, and we’ll take it from there."
                    >
                        <div className="grid gap-2 px-[18px] pt-1 pb-4 sm:grid-cols-2 xl:grid-cols-3">
                            {workflows.map((w) => (
                                // eslint-disable-next-line no-restricted-syntax -- start tile opens its form (as the launcher's tiles)
                                <button
                                    key={w.key}
                                    type="button"
                                    onClick={() => onWorkflow(w.key)}
                                    className="flex items-start gap-3 rounded-xl border border-border bg-card p-3 text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <span className="popup-lens size-9 shrink-0">
                                        <w.icon
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block text-sm font-semibold text-foreground">
                                            {w.label}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {w.opens}
                                        </span>
                                    </span>
                                </button>
                            ))}
                        </div>
                    </Surface>
                ) : null
            ) : safeguarding ? (
                <Surface aria-label="Safeguarding">
                    <div className="flex flex-wrap items-center gap-3 px-[18px] py-3">
                        <ShieldQuestion
                            className="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span className="text-sm text-foreground">
                            {safeguarding.label}?
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            className="ml-auto"
                            onClick={() => onWorkflow('safeguarding')}
                        >
                            {safeguarding.opens}
                        </Button>
                    </div>
                </Surface>
            ) : null}
        </div>
    );
}
