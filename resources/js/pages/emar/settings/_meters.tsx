/* Settings' header meters (eMAR P11 v5): each a link to where its number
 * lives, and each shown only when that view is open to this person. The
 * on-call meter counts the houses with an on-call contact (B2 C4) — a donut,
 * linked to On-call contacts (Rory). */
import {
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
} from '@/components/page/page-header';
import type { Pending, ViewKey } from './_model';
import type { Built } from './_nav';
import type { MedicineRule } from './_rules';
import type { WitnessPinStaffRow } from './_sections';
import type { RoundTemplate } from './_templates';

const METERS: [ViewKey, string][] = [
    ['history', 'decide'],
    ['rules', 'medicines'],
    ['rounds', 'templates'],
    ['staff', 'status'],
    ['alerts', 'oncall'],
];
const opens = (built: Built, v: ViewKey, k: string) =>
    built[v]?.includes(k) ?? false;

/** Whether any meter is open to this person, so the header keeps its band. */
export const hasSettingsMeters = (built: Built) =>
    METERS.some(([v, k]) => opens(built, v, k));

export function SettingsMeters({
    built,
    view,
    sec,
    go,
    pending,
    rules,
    templates,
    pins,
    oncall = [],
}: {
    built: Built;
    view: ViewKey;
    sec: string;
    go: (view: ViewKey, sec: string) => void;
    pending: Pending[];
    rules: MedicineRule[];
    templates: RoundTemplate[];
    pins: WitnessPinStaffRow[];
    /** Houses this person sees, and whether each has an on-call contact. */
    oncall?: { configured: boolean }[];
}) {
    const has = (v: ViewKey, k: string) => opens(built, v, k);
    const ocSet = oncall.filter((h) => h.configured).length;
    const activeRules = rules.filter((r) => r.active);
    const activeTemplates = templates.filter((t) => t.status === 'active');
    const pinCount = (...st: string[]) =>
        pins.filter((x) => st.includes(x.status)).length;
    const pinSet = pinCount('set');
    const nc = pending.filter((p) => p.state === 'nc').length;
    // Short form: "· the rest are defaults" truncated at 1280 and at 200 %.
    const pendingCaption = !pending.length
        ? 'Nothing to decide'
        : !nc
          ? 'All are defaults'
          : `${nc} not configured`;

    return (
        <>
            {has('history', 'decide') ? (
                <PageHeaderMeterBlock
                    label="Still to decide"
                    tone={pending.length ? 'warning' : 'success'}
                    ariaLabel={`View ${pending.length} settings still to decide`}
                    pressed={view === 'history' && sec === 'decide'}
                    onClick={() => go('history', 'decide')}
                >
                    <PageHeaderMeterBig>{pending.length}</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        {pendingCaption}
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ) : null}
            {has('rules', 'medicines') ? (
                <PageHeaderMeterBlock
                    label="Medicine rules"
                    ariaLabel={`View medicine rules, ${activeRules.length} active`}
                    pressed={view === 'rules' && sec === 'medicines'}
                    onClick={() => go('rules', 'medicines')}
                >
                    <PageHeaderMeterBig>
                        {activeRules.length} active
                    </PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        {rules.length
                            ? `${rules.length - activeRules.length} paused · ${activeRules.filter((r) => r.overlaps.length).length} overlap`
                            : 'No rules yet'}
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ) : null}
            {has('rounds', 'templates') ? (
                <PageHeaderMeterBlock
                    label="Round templates"
                    ariaLabel={`View round templates, ${activeTemplates.length} active`}
                    pressed={view === 'rounds' && sec === 'templates'}
                    onClick={() => go('rounds', 'templates')}
                >
                    <PageHeaderMeterBig>
                        {activeTemplates.length} active
                    </PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        {templates.length
                            ? `${templates.filter((t) => t.status === 'paused').length} paused · ${new Set(activeTemplates.map((t) => t.site_id ?? 'all')).size} houses`
                            : 'None yet'}
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ) : null}
            {has('staff', 'status') ? (
                <PageHeaderMeterBlock
                    label="Witness PINs"
                    tone={pinCount('locked') ? 'warning' : 'brand'}
                    ariaLabel={`View witness PIN status, ${pinSet} of ${pins.length} set`}
                    pressed={view === 'staff' && sec === 'status'}
                    onClick={() => go('staff', 'status')}
                >
                    {pins.length ? (
                        <PageHeaderMeterDonut
                            percent={(pinSet / pins.length) * 100}
                            caption={
                                <>
                                    {pinSet} of {pins.length} set
                                    <br />
                                    {pinCount('locked')} locked ·{' '}
                                    {pinCount('not_set', 'reset', 'expired')} to
                                    set
                                </>
                            }
                        />
                    ) : (
                        <>
                            <PageHeaderMeterBig>n/a</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Nobody to show yet
                            </PageHeaderMeterCaption>
                        </>
                    )}
                </PageHeaderMeterBlock>
            ) : null}
            {has('alerts', 'oncall') && oncall.length ? (
                <PageHeaderMeterBlock
                    label="On-call contacts"
                    tone={ocSet < oncall.length ? 'warning' : 'success'}
                    ariaLabel={`View on-call contacts, ${ocSet} of ${oncall.length} set`}
                    pressed={view === 'alerts' && sec === 'oncall'}
                    onClick={() => go('alerts', 'oncall')}
                >
                    <PageHeaderMeterDonut
                        percent={(ocSet / oncall.length) * 100}
                        caption={
                            <>
                                {ocSet} of {oncall.length} set
                                <br />
                                {ocSet === oncall.length
                                    ? 'Every house has one'
                                    : `${oncall.length - ocSet} not configured`}
                            </>
                        }
                    />
                </PageHeaderMeterBlock>
            ) : null}
        </>
    );
}
