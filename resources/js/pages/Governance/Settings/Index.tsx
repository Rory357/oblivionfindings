import { Head, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Layers,
    Save,
    Scale,
    Settings as SettingsIcon,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useState } from 'react';

import {
    GovernanceExplainer,
    GovernanceTermHint,
} from '@/components/governance/GovernanceTermHint';
import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import InputError from '@/components/input-error';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { InfoCard } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { legalFormLabel, quorumModeLabel } from '@/lib/governance-labels';
import { PageProps } from '@/types';

import {
    ActivateRulesDialog,
    RecordRulesApprovalDialog,
    type ApprovalMeetingOption,
    type ApprovalResolutionOption,
    type RulesActivationTarget,
} from './_dialogs';

interface SettingDefinition {
    key: string;
    label: string;
    category: string;
    type: 'number';
    format: 'money' | 'count' | 'person';
    default: string | number | null;
    default_label?: string | null;
    description: string;
    value: string | number | null;
    value_label?: string | null;
}

interface BoardMemberSummary {
    id: number;
    name: string;
    role: string;
    role_label: string;
    can_vote: boolean;
    why_not: string | null;
    term_start: string | null;
    term_end: string | null;
}

interface VotingRules {
    legal_form: string;
    governing_document_reference: string | null;
    governing_document_version: string | null;
    quorum_mode: string;
    quorum_formula: string;
    written_voting_permitted: boolean;
    written_unanimity_required: boolean;
}

interface RulesInForce extends VotingRules {
    approved_on: string | null;
    approval_source: string | null;
    approval_minutes_reference: string | null;
    approval_meeting_title: string | null;
    approved_by_name: string | null;
    approved_by_resolution: {
        id: number;
        title: string;
        resolution_reference: string | null;
    } | null;
}

interface RulesProfileProp {
    status: 'on' | 'off';
    isConfirmed: boolean;
    hasEverBeenActive: boolean;
    hasPendingChanges: boolean;
    rules: VotingRules;
    inForce: RulesInForce | null;
    eligibleVoterCount: number;
    memberCount: number;
    quorumRequired: number;
    members: BoardMemberSummary[];
    /** Present only for people who may switch voting rules on. */
    activation?: RulesActivationTarget | null;
    approvalResolutions?: ApprovalResolutionOption[];
    approvalMeetings?: ApprovalMeetingOption[];
}

interface Props extends PageProps {
    settings: SettingDefinition[];
    categories: Record<string, string>;
    people?: Array<{ id: number; name: string }>;
    rulesProfile?: RulesProfileProp;
    canManage?: boolean;
}

const RULES_SECTION = 'rules';
const NONE = '__none';
const LEGAL_FORMS = ['charitable_trust', 'incorporated_society', 'company'];
const QUORUM_MODES = ['majority_floor_plus_one', 'percentage', 'fixed_count'];

/** "Right now 5 members can vote. A decision needs at least 3 of them taking part. …" */
function votingSummary(
    eligible: number,
    quorum: number,
    rules: Pick<VotingRules, 'written_voting_permitted' | 'written_unanimity_required'>,
): string {
    const members =
        eligible === 1 ? 'Right now 1 member can vote.' : `Right now ${eligible} members can vote.`;
    const quorumText =
        eligible === 0
            ? 'Nobody can vote until board members with a voting seat are added.'
            : `A decision needs at least ${quorum} of them taking part.`;
    const passing =
        'Resolutions pass when more vote For than Against, unless a resolution says it needs at least two-thirds For or everyone’s For vote.';
    const written = rules.written_voting_permitted
        ? rules.written_unanimity_required
            ? 'Voting outside meetings (written resolutions) is allowed, and those votes need everyone’s agreement.'
            : 'Voting outside meetings (written resolutions) is allowed.'
        : 'Voting outside meetings (written resolutions) isn’t allowed.';
    return [members, quorumText, passing, written].join(' ');
}

function quorumLine(rules: VotingRules): string {
    switch (rules.quorum_mode) {
        case 'percentage':
            return `Quorum: at least ${rules.quorum_formula || '?'}% of voting members must take part`;
        case 'fixed_count':
            return `Quorum: at least ${rules.quorum_formula || '?'} voting members must take part`;
        default:
            return 'Quorum: more than half of the voting members must take part';
    }
}

export default function GovernanceSettingsIndex({
    auth,
    settings,
    categories,
    people = [],
    rulesProfile,
    canManage = false,
}: Props) {
    const page = usePage();
    const [section, setSection] = useState(() => {
        const requested = new URLSearchParams(page.url.split('?')[1] ?? '').get('section');
        return requested === RULES_SECTION || (requested && categories[requested])
            ? requested
            : 'all';
    });
    const [recordOpen, setRecordOpen] = useState(false);
    const [activateOpen, setActivateOpen] = useState(false);
    const activation = rulesProfile?.activation ?? null;

    const initialValues: Record<string, string> = {};
    settings.forEach((s) => {
        initialValues[s.key] =
            s.value !== null && s.value !== undefined ? String(s.value) : '';
    });

    const form = useForm<{ settings: Record<string, string> }>({
        settings: initialValues,
    });

    const rules = rulesProfile?.rules;
    const rulesForm = useForm({
        legal_form: rules?.legal_form ?? 'charitable_trust',
        governing_document_reference: rules?.governing_document_reference ?? '',
        governing_document_version: rules?.governing_document_version ?? '',
        quorum_mode: rules?.quorum_mode ?? 'majority_floor_plus_one',
        quorum_formula: rules?.quorum_formula ?? '',
        written_voting_permitted: rules?.written_voting_permitted ?? false,
        written_unanimity_required: rules?.written_unanimity_required ?? true,
    });
    const rulesErrors = rulesForm.errors as Partial<Record<keyof typeof rulesForm.data, string>>;

    const grouped = Object.entries(categories).map(([key, label]) => ({
        key,
        label,
        settings: settings.filter((s) => s.category === key),
    }));
    const visibleGroups = grouped.filter(
        (g) => g.settings.length > 0 && (section === 'all' || section === g.key),
    );
    const showRules =
        Boolean(rulesProfile) && (section === 'all' || section === RULES_SECTION);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put('/governance/settings', {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    const submitRules = (e: React.FormEvent) => {
        e.preventDefault();
        rulesForm.transform((data) => ({
            ...data,
            governing_document_reference: data.governing_document_reference.trim() || null,
            governing_document_version: data.governing_document_version.trim() || null,
            quorum_formula:
                data.quorum_mode === 'majority_floor_plus_one' ? null : data.quorum_formula,
        }));
        rulesForm.post('/governance/settings/rules', {
            preserveScroll: true,
            onSuccess: (response) => {
                const hasError = Boolean(
                    (response as { props?: { flash?: { error?: unknown } } })?.props?.flash?.error,
                );
                if (!hasError) rulesForm.setDefaults();
            },
        });
    };

    const sectionOptions = [
        { value: 'all', label: 'All sections' },
        ...(rulesProfile ? [{ value: RULES_SECTION, label: 'How the board votes' }] : []),
        ...grouped
            .filter((g) => g.settings.length > 0)
            .map((g) => ({ value: g.key, label: g.label })),
    ];

    const votingOn = rulesProfile?.status === 'on';
    const summaryRules = rulesProfile?.inForce ?? rulesProfile?.rules;
    const capex = settings.find((s) => s.key === 'spend_approval.threshold.capex');
    const savedDocument = activation?.governing_document_reference ?? null;
    const rulesSummaryLines = rules
        ? [
              `Organisation: ${legalFormLabel(rules.legal_form)}`,
              quorumLine(rules),
              rules.written_voting_permitted
                  ? rules.written_unanimity_required
                      ? 'Voting outside meetings is allowed and needs everyone’s agreement'
                      : 'Voting outside meetings is allowed'
                  : 'Voting outside meetings isn’t allowed',
          ]
        : [];

    const header = (
        <PageHeader
            icon={SettingsIcon}
            title="Settings"
            titleChip={
                canManage ? null : (
                    <PageHeaderStatusChip variant="neutral">Read only</PageHeaderStatusChip>
                )
            }
            subline="How the board votes, and the spending and reminder rules the app follows"
            meters={
                <>
                    {rulesProfile ? (
                        <>
                            <PageHeaderMeterBlock
                                label="Board voting"
                                tone={votingOn ? 'success' : 'warning'}
                                ariaLabel="View how the board votes"
                                onClick={() => setSection(RULES_SECTION)}
                            >
                                <PageHeaderMeterBig>{votingOn ? 'On' : 'Off'}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {votingOn ? 'Voting rules confirmed' : 'Voting rules not confirmed yet'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Voting members"
                                ariaLabel="View who can vote"
                                onClick={() => setSection(RULES_SECTION)}
                            >
                                <PageHeaderMeterBig>{rulesProfile.eligibleVoterCount}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {`of ${rulesProfile.memberCount} board member${rulesProfile.memberCount === 1 ? '' : 's'}`}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Quorum"
                                ariaLabel="View the quorum rule"
                                onClick={() => setSection(RULES_SECTION)}
                            >
                                <PageHeaderMeterBig>{rulesProfile.quorumRequired}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>must take part in a vote</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    ) : null}
                    {capex ? (
                        <PageHeaderMeterBlock
                            label="Board approval for purchases"
                            ariaLabel="View spending that needs board approval"
                            onClick={() => setSection(capex.category)}
                        >
                            <PageHeaderMeterBig>
                                {capex.value_label ?? capex.default_label ?? 'Not set'}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>or more for equipment and buildings</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    icon={Layers}
                    label="All sections"
                    value={section}
                    options={sectionOptions}
                    onChange={setSection}
                />
            }
            rail={<GovernanceSectionRail />}
        />
    );

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Settings', href: '/governance/settings' },
            ]}
        >
            <Head title="Settings" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {showRules && rulesProfile && rules ? (
                        <Card id="how-the-board-votes">
                            <CardHeader>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <CardTitle className="text-section-title flex items-center gap-2">
                                        <Scale className="h-4 w-4 text-primary" />
                                        How the board votes
                                    </CardTitle>
                                    <StatusBadge variant={votingOn ? 'success' : 'warning'}>
                                        {votingOn ? 'Board voting is on' : 'Board voting is off'}
                                    </StatusBadge>
                                </div>
                                <CardDescription>
                                    {votingOn
                                        ? 'These are the voting rules the board has approved. They apply to every resolution opened for voting.'
                                        : rulesProfile.hasEverBeenActive
                                          ? 'Board voting is switched off because no approved voting rules are in use. Changed rules need a resolution the board has passed.'
                                          : 'Board voting stays switched off until the chair or board secretary records the board’s approval of these voting rules.'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-5">
                                {summaryRules ? (
                                    <GovernanceExplainer
                                        title="In plain words"
                                        body={votingSummary(
                                            rulesProfile.eligibleVoterCount,
                                            rulesProfile.quorumRequired,
                                            summaryRules,
                                        )}
                                    >
                                        <span className="flex flex-wrap items-center gap-3 text-caption">
                                            <span className="inline-flex items-center gap-1">
                                                Quorum <GovernanceTermHint term="quorum" />
                                            </span>
                                            <span className="inline-flex items-center gap-1">
                                                Written resolution <GovernanceTermHint term="written_resolution" />
                                            </span>
                                        </span>
                                    </GovernanceExplainer>
                                ) : null}

                                {rulesProfile.inForce ? (
                                    <p className="text-subtle" data-test="voting-rules-approval">
                                        {rulesProfile.inForce.approval_source === 'recorded_board_approval'
                                            ? [
                                                  `The board approved these rules on ${rulesProfile.inForce.approved_on ?? 'a recorded date'}`,
                                                  rulesProfile.inForce.approval_minutes_reference
                                                      ? `recorded in ${rulesProfile.inForce.approval_minutes_reference}`
                                                      : null,
                                                  rulesProfile.inForce.approval_meeting_title
                                                      ? `at ${rulesProfile.inForce.approval_meeting_title}`
                                                      : null,
                                              ]
                                                  .filter(Boolean)
                                                  .join(', ') +
                                              (rulesProfile.inForce.approved_by_name
                                                  ? `. Recorded by ${rulesProfile.inForce.approved_by_name}.`
                                                  : '.')
                                            : rulesProfile.inForce.approved_by_resolution
                                              ? `Approved by the resolution “${rulesProfile.inForce.approved_by_resolution.title}”${rulesProfile.inForce.approved_on ? ` on ${rulesProfile.inForce.approved_on}` : ''}.`
                                              : `Approved${rulesProfile.inForce.approved_on ? ` on ${rulesProfile.inForce.approved_on}` : ''}.`}
                                    </p>
                                ) : null}

                                {rulesProfile.hasPendingChanges ? (
                                    <InfoCard icon={AlertTriangle} tone="warn">
                                        <span className="block">
                                            You’ve saved changes to these rules, but they aren’t in use yet. The
                                            board needs to pass a resolution that approves them: create a
                                            resolution and choose these voting rules under “What will this
                                            resolution approve?”. The current rules stay in use until then.
                                        </span>
                                        {canManage && activation ? (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                className="mt-2"
                                                onClick={() => setActivateOpen(true)}
                                            >
                                                <ShieldCheck className="h-4 w-4" />
                                                Switch on the approved changes
                                            </Button>
                                        ) : null}
                                    </InfoCard>
                                ) : null}

                                <form onSubmit={submitRules} className="flex flex-col gap-4">
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <Label htmlFor="legal_form">Kind of organisation</Label>
                                            <Select
                                                value={rulesForm.data.legal_form}
                                                onValueChange={(value) => rulesForm.setData('legal_form', value)}
                                                disabled={!canManage}
                                            >
                                                <SelectTrigger id="legal_form" className="mt-1.5 w-full">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {LEGAL_FORMS.map((legalForm) => (
                                                        <SelectItem key={legalForm} value={legalForm}>
                                                            {legalFormLabel(legalForm)}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={rulesErrors.legal_form} />
                                        </div>
                                        <div className="grid gap-4 sm:grid-cols-[2fr_1fr]">
                                            <div>
                                                <Label
                                                    htmlFor="governing_document_reference"
                                                    className="inline-flex items-center gap-1"
                                                >
                                                    Governing document
                                                    <GovernanceTermHint term="governing_document" />
                                                </Label>
                                                <Input
                                                    id="governing_document_reference"
                                                    className="mt-1.5"
                                                    disabled={!canManage}
                                                    placeholder="e.g. Trust deed"
                                                    value={rulesForm.data.governing_document_reference}
                                                    onChange={(e) =>
                                                        rulesForm.setData('governing_document_reference', e.target.value)
                                                    }
                                                />
                                                <InputError message={rulesErrors.governing_document_reference} />
                                            </div>
                                            <div>
                                                <Label htmlFor="governing_document_version">Version or date</Label>
                                                <Input
                                                    id="governing_document_version"
                                                    className="mt-1.5"
                                                    disabled={!canManage}
                                                    placeholder="e.g. 2019"
                                                    value={rulesForm.data.governing_document_version}
                                                    onChange={(e) =>
                                                        rulesForm.setData('governing_document_version', e.target.value)
                                                    }
                                                />
                                                <InputError message={rulesErrors.governing_document_version} />
                                            </div>
                                        </div>
                                        <div>
                                            <Label htmlFor="quorum_mode" className="inline-flex items-center gap-1">
                                                How many voting members must take part (quorum)
                                                <GovernanceTermHint term="quorum" />
                                            </Label>
                                            <Select
                                                value={rulesForm.data.quorum_mode}
                                                onValueChange={(value) => rulesForm.setData('quorum_mode', value)}
                                                disabled={!canManage}
                                            >
                                                <SelectTrigger id="quorum_mode" className="mt-1.5 w-full">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {QUORUM_MODES.map((mode) => (
                                                        <SelectItem key={mode} value={mode}>
                                                            {quorumModeLabel(mode)}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={rulesErrors.quorum_mode} />
                                        </div>
                                        {rulesForm.data.quorum_mode !== 'majority_floor_plus_one' ? (
                                            <div>
                                                <Label htmlFor="quorum_formula">
                                                    {rulesForm.data.quorum_mode === 'percentage'
                                                        ? 'Percentage of voting members'
                                                        : 'Number of voting members'}
                                                </Label>
                                                <div className="relative mt-1.5">
                                                    <Input
                                                        id="quorum_formula"
                                                        type="number"
                                                        min={1}
                                                        max={100}
                                                        step={1}
                                                        disabled={!canManage}
                                                        className={
                                                            rulesForm.data.quorum_mode === 'percentage' ? 'pr-8' : undefined
                                                        }
                                                        value={rulesForm.data.quorum_formula}
                                                        onChange={(e) =>
                                                            rulesForm.setData('quorum_formula', e.target.value)
                                                        }
                                                    />
                                                    {rulesForm.data.quorum_mode === 'percentage' ? (
                                                        <span
                                                            aria-hidden="true"
                                                            className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground"
                                                        >
                                                            %
                                                        </span>
                                                    ) : null}
                                                </div>
                                                <InputError message={rulesErrors.quorum_formula} />
                                            </div>
                                        ) : null}
                                    </div>

                                    <div className="grid gap-3 md:grid-cols-2">
                                        <label className="flex items-start gap-3 rounded-lg border border-border p-3">
                                            <Switch
                                                checked={rulesForm.data.written_voting_permitted}
                                                onCheckedChange={(checked) =>
                                                    rulesForm.setData('written_voting_permitted', checked)
                                                }
                                                disabled={!canManage}
                                                aria-label="Allow voting outside meetings (written resolutions)"
                                                className="mt-0.5"
                                            />
                                            <span>
                                                <span className="block text-sm font-medium">
                                                    Allow voting outside meetings (written resolutions)
                                                </span>
                                                <span className="text-caption block">
                                                    Members can vote in the app on a resolution that isn’t part of a
                                                    meeting.
                                                </span>
                                            </span>
                                        </label>
                                        <label className="flex items-start gap-3 rounded-lg border border-border p-3">
                                            <Switch
                                                checked={rulesForm.data.written_unanimity_required}
                                                onCheckedChange={(checked) =>
                                                    rulesForm.setData('written_unanimity_required', checked)
                                                }
                                                disabled={!canManage || !rulesForm.data.written_voting_permitted}
                                                aria-label="Written votes need everyone's agreement"
                                                className="mt-0.5"
                                            />
                                            <span>
                                                <span className="block text-sm font-medium">
                                                    Written votes need everyone’s agreement
                                                </span>
                                                <span className="text-caption block">
                                                    {rulesForm.data.written_voting_permitted
                                                        ? 'A vote outside a meeting only passes if every voting member votes For.'
                                                        : 'Only applies when voting outside meetings is allowed.'}
                                                </span>
                                            </span>
                                        </label>
                                    </div>

                                    {canManage ? (
                                        <div className="flex flex-wrap items-center justify-end gap-3">
                                            {rulesProfile.hasEverBeenActive ? (
                                                <p className="text-caption">
                                                    Changes aren’t used until the board passes a resolution that
                                                    approves them.
                                                </p>
                                            ) : null}
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={rulesForm.processing || !rulesForm.isDirty}
                                            >
                                                <Save className="h-4 w-4" />
                                                {rulesProfile.hasEverBeenActive
                                                    ? 'Save as proposed changes'
                                                    : 'Save voting rules'}
                                            </Button>
                                        </div>
                                    ) : null}
                                </form>

                                {!votingOn && !rulesProfile.hasEverBeenActive ? (
                                    <div
                                        className="flex flex-col gap-2 rounded-lg border border-status-warning/40 bg-status-warning-bg p-4"
                                        data-test="switch-on-voting"
                                    >
                                        <p className="text-sm font-medium text-foreground">
                                            Switch on board voting
                                        </p>
                                        {canManage && activation?.mode === 'record_first_approval' ? (
                                            <>
                                                <p className="text-sm text-foreground">
                                                    If your board has already approved these voting rules — in its
                                                    governing document or at a meeting — record that approval here.
                                                </p>
                                                <div className="flex flex-wrap items-center gap-3">
                                                    <Button
                                                        type="button"
                                                        disabled={rulesForm.isDirty || !savedDocument}
                                                        onClick={() => setRecordOpen(true)}
                                                    >
                                                        <ShieldCheck className="h-4 w-4" />
                                                        Record the board’s approval of these voting rules
                                                    </Button>
                                                    {(rulesProfile.approvalResolutions ?? []).length > 0 ? (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            onClick={() => setActivateOpen(true)}
                                                        >
                                                            Use a passed resolution instead
                                                        </Button>
                                                    ) : null}
                                                </div>
                                                {rulesForm.isDirty ? (
                                                    <p className="text-caption">Save your changes to the rules first.</p>
                                                ) : !savedDocument ? (
                                                    <p className="text-caption">
                                                        Add your governing document’s name above and save it first.
                                                    </p>
                                                ) : null}
                                            </>
                                        ) : (
                                            <p className="text-sm text-foreground">
                                                Only the chair or board secretary can record the board’s approval
                                                and switch on voting.
                                            </p>
                                        )}
                                    </div>
                                ) : null}

                                <div className="flex flex-col gap-3" id="who-can-vote">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h3 className="text-section-title flex items-center gap-2">
                                            <Users className="h-4 w-4 text-muted-foreground" />
                                            Who can vote
                                        </h3>
                                        <p className="text-caption">
                                            {`${rulesProfile.eligibleVoterCount} of ${rulesProfile.memberCount} board member${rulesProfile.memberCount === 1 ? '' : 's'} can vote`}
                                        </p>
                                    </div>
                                    {rulesProfile.members.length === 0 ? (
                                        <EmptyState
                                            variant="inline"
                                            icon={Users}
                                            title="No board members have been added yet"
                                        />
                                    ) : (
                                        <div className="overflow-x-auto rounded-md border border-border">
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead>Member</TableHead>
                                                        <TableHead>Board role</TableHead>
                                                        <TableHead>Can vote</TableHead>
                                                        <TableHead>Why not?</TableHead>
                                                        <TableHead>Term</TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {rulesProfile.members.map((member) => (
                                                        <TableRow key={member.id}>
                                                            <TableCell className="font-medium">{member.name}</TableCell>
                                                            <TableCell>{member.role_label}</TableCell>
                                                            <TableCell>
                                                                <StatusBadge
                                                                    variant={member.can_vote ? 'success' : 'neutral'}
                                                                >
                                                                    {member.can_vote ? 'Yes' : 'No'}
                                                                </StatusBadge>
                                                            </TableCell>
                                                            <TableCell className="text-subtle">
                                                                {member.why_not ?? '—'}
                                                            </TableCell>
                                                            <TableCell className="text-caption">
                                                                {`${formatDateOnly(member.term_start, 'Start not set')} to ${
                                                                    member.term_end
                                                                        ? formatDateOnly(member.term_end)
                                                                        : 'no end date'
                                                                }`}
                                                            </TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    ) : null}

                    {visibleGroups.length > 0 ? (
                        <form onSubmit={submit} className="flex flex-col gap-5">
                            {visibleGroups.map((group) => (
                                <Card key={group.key} id={`settings-${group.key}`}>
                                    <CardHeader>
                                        <CardTitle className="text-section-title">{group.label}</CardTitle>
                                    </CardHeader>
                                    <CardContent className="flex flex-col gap-4">
                                        {group.settings.map((s) => {
                                            const error = (
                                                form.errors as Record<string, string | undefined>
                                            )[`settings.${s.key}`];
                                            const value = form.data.settings[s.key] ?? '';
                                            const setValue = (next: string) =>
                                                form.setData('settings', {
                                                    ...form.data.settings,
                                                    [s.key]: next,
                                                });
                                            const fieldId = `setting-${s.key.replace(/\./g, '-')}`;
                                            return (
                                                <div
                                                    key={s.key}
                                                    className="grid gap-2 lg:grid-cols-[1fr_2fr] lg:items-start"
                                                >
                                                    <div>
                                                        <Label htmlFor={fieldId}>{s.label}</Label>
                                                        <p className="text-caption mt-1">{s.description}</p>
                                                    </div>
                                                    <div>
                                                        {!canManage ? (
                                                            <p id={fieldId} className="text-sm font-medium">
                                                                {s.value_label ?? s.default_label ?? 'Not set'}
                                                            </p>
                                                        ) : s.format === 'person' ? (
                                                            <Select
                                                                value={value === '' ? NONE : value}
                                                                onValueChange={(next) =>
                                                                    setValue(next === NONE ? '' : next)
                                                                }
                                                            >
                                                                <SelectTrigger id={fieldId} className="w-full">
                                                                    <SelectValue placeholder="Choose a person" />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value={NONE}>
                                                                        Nobody chosen — the board chair is told
                                                                    </SelectItem>
                                                                    {people.map((person) => (
                                                                        <SelectItem
                                                                            key={person.id}
                                                                            value={String(person.id)}
                                                                        >
                                                                            {person.name}
                                                                        </SelectItem>
                                                                    ))}
                                                                </SelectContent>
                                                            </Select>
                                                        ) : (
                                                            <div className="relative">
                                                                {s.format === 'money' ? (
                                                                    <span
                                                                        aria-hidden="true"
                                                                        className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"
                                                                    >
                                                                        $
                                                                    </span>
                                                                ) : null}
                                                                <Input
                                                                    id={fieldId}
                                                                    type="number"
                                                                    inputMode={s.format === 'money' ? 'decimal' : 'numeric'}
                                                                    min={0}
                                                                    step={s.format === 'money' ? 'any' : 1}
                                                                    className={s.format === 'money' ? 'pl-7' : undefined}
                                                                    value={value}
                                                                    onChange={(e) => setValue(e.target.value)}
                                                                />
                                                            </div>
                                                        )}
                                                        <InputError message={error} />
                                                        {canManage && s.default_label ? (
                                                            <p className="text-caption mt-1">{`Default: ${s.default_label}`}</p>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </CardContent>
                                </Card>
                            ))}

                            {canManage ? (
                                <div className="flex items-center justify-end">
                                    <Button type="submit" disabled={form.processing || !form.isDirty}>
                                        <Save className="h-4 w-4" />
                                        {form.processing ? 'Saving…' : 'Save changes'}
                                    </Button>
                                </div>
                            ) : null}
                        </form>
                    ) : null}
                </div>
            </PageLayout>

            {canManage && activation && rulesProfile && activation.mode === 'record_first_approval' ? (
                <RecordRulesApprovalDialog
                    open={recordOpen}
                    onClose={() => setRecordOpen(false)}
                    target={activation}
                    meetings={rulesProfile.approvalMeetings ?? []}
                    rulesSummary={rulesSummaryLines}
                />
            ) : null}

            {canManage && activation && rulesProfile ? (
                <ActivateRulesDialog
                    open={activateOpen}
                    onClose={() => setActivateOpen(false)}
                    target={activation}
                    resolutions={rulesProfile.approvalResolutions ?? []}
                    canViewResolutions={Boolean(auth.can?.governance?.resolutions?.view)}
                />
            ) : null}
        </AppLayout>
    );
}
