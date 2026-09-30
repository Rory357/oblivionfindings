/* P11 v5’s settings compositions (src/ui.tsx), copied unchanged for the P11
 * frame that shows P08b’s addition to Settings (Main, Q5 addition): the titled
 * SettingGroup, the GroupRow with its state badge, the two-per-line GroupGrid,
 * the Section caption, the Choice (Segmented) and the sticky SaveBar. Built only from
 * the app’s real primitives, as in P11. */
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { FieldErr, Segmented } from '@/components/wizard/primitives';
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export const DefaultNotReviewed = () => <StatusBadge variant="warning" size="sm">Default — not yet reviewed</StatusBadge>;
export const NotConfigured = () => <StatusBadge variant="neutral" size="sm">Not configured</StatusBadge>;
export const Changed = () => <StatusBadge variant="info" size="sm">Changed — not saved</StatusBadge>;

export function Choice<T extends string>({ value, onChange, options, disabled }: { value: T; onChange: (v: T) => void; options: [T, string][]; disabled?: boolean }) {
    return <Segmented value={value} onChange={onChange} options={options.map(([v, l]) => ({ value: v, label: l, disabled }))} />;
}

/** Section card: ListCaption + content (LIST_STYLE_GUIDE §1 caption before every list or group). */
export function Section({ title, caption, right, children, id }: { title: ReactNode; caption?: ReactNode; right?: ReactNode; children: ReactNode; id?: string }) {
    return (
        <section className="space-y-3" aria-labelledby={id}>
            <div id={id}>
                <ListCaption title={title} caption={caption} right={right} />
            </div>
            {children}
        </section>
    );
}

/** Sticky save bar (Fleet _notifications.tsx): what changed, then Discard / Review changes. */
export function SaveBar({ count, onDiscard, onReview, readOnly }: { count: number; onDiscard: () => void; onReview: () => void; readOnly?: string }) {
    return (
        <Card className="sticky bottom-0 z-10 flex flex-row flex-wrap items-center justify-between gap-3 p-4" role="region" aria-label="Save changes">
            {readOnly ? (
                <p className="text-subtle">{readOnly}</p>
            ) : (
                <>
                    <div>
                        <p className="text-sm font-semibold">{count ? `${count} ${count === 1 ? 'change' : 'changes'} to review` : 'No unsaved changes'}</p>
                        <p className="text-caption mt-1">{count ? 'Nothing applies until you review and save. Your changes stay while you move between tabs and views.' : 'Changes apply only after you review and save them.'}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" disabled={!count} onClick={onDiscard}>
                            Discard changes
                        </Button>
                        <Button size="sm" disabled={!count} onClick={onReview} data-fk="review">
                            Review changes
                        </Button>
                    </div>
                </>
            )}
        </Card>
    );
}

/** Internal decision and package codes stay out of product copy. */
export const plain = (t?: string) => (t ?? '').replace(/\s*\((?:D\d+|NF-\d+|EM-\d+|P\d{2}[a-z]?)\)/g, '');
export function SettingGroup({ icon: Icon, title, caption, children, wide, id }: { icon: LucideIcon; title: string; caption?: string; children: ReactNode; wide?: boolean; id?: string }) {
    return (
        <Card className={cn('gap-0 overflow-hidden p-0 [&:not(:has([data-setting]))]:hidden', wide && 'lg:col-span-2')} data-group={id}>
            <div className="flex items-start gap-3 border-b border-border p-4">
                <span className="shrink-0 rounded-lg bg-primary/10 p-2 text-primary">
                    <Icon className="size-4" />
                </span>
                <div className="min-w-0">
                    <h3 className="text-sm font-semibold">{title}</h3>
                    {caption ? <p className="text-caption mt-0.5">{caption}</p> : null}
                </div>
            </div>
            <div className="divide-y divide-border">{children}</div>
        </Card>
    );
}
/** One setting inside a group: label and a one-line hint left, control right; a choice or list below. */
export function GroupRow({ id, label, hint, control, children, state, error, errorId, hidden }: { id?: string; label: string; hint?: string; control?: ReactNode; children?: ReactNode; state?: 'default' | 'nc' | 'changed' | null; error?: string; errorId?: string; hidden?: boolean }) {
    if (hidden) return null;
    return (
        <div className="space-y-2 px-4 py-3" data-setting={id}>
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <Label htmlFor={id} className="text-[13px] leading-snug">
                            {label}
                        </Label>
                        {state === 'changed' ? <Changed /> : state === 'nc' ? <NotConfigured /> : state === 'default' ? <DefaultNotReviewed /> : null}
                    </div>
                    {hint ? <p className="text-caption mt-0.5">{plain(hint)}</p> : null}
                </div>
                {control ? <div className="shrink-0">{control}</div> : null}
            </div>
            {children ? <div className="space-y-2">{children}</div> : null}
            <FieldErr id={errorId}>{error}</FieldErr>
        </div>
    );
}
/** Two groups per line where they fit. */
export const GroupGrid = ({ children }: { children: ReactNode }) => <div className="peer grid items-start gap-5 lg:grid-cols-2">{children}</div>;
