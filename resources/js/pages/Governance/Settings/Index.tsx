import { Head, useForm } from '@inertiajs/react';
import {
    Layers,
    Save,
    Scale,
    Settings as SettingsIcon,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useState } from 'react';

import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
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
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { PageProps } from '@/types';

import {
    ActivateRulesDialog,
    type ApprovalResolutionOption,
    type RulesActivationTarget,
} from './_dialogs';

interface SettingDefinition {
    key: string;
    label: string;
    category: string;
    type: 'number' | 'text' | 'json';
    default: string | number | null;
    description: string;
    value: string | number | null;
}

interface BoardMemberSummary {
    id: number;
    name: string;
    email?: string;
    role: string;
    has_voting_seat: boolean | null;
    can_vote: boolean;
    is_active: boolean;
    term_start: string | null;
    term_end: string | null;
}

interface VotingProfileData {
    id?: number;
    governing_body: string;
    legal_form: string;
    governing_document_reference: string | null;
    governing_document_version: string | null;
    quorum_mode: string;
    quorum_formula: string;
    ordinary_threshold_formula: string;
    unanimous_denominator_formula: string;
    written_voting_permitted: boolean;
    written_unanimity_required: boolean;
    recusal_policy: string;
    is_active: boolean;
}

interface RulesProfileProp {
    profile: VotingProfileData;
    isConfirmed: boolean;
    statusLabel: string;
    eligibleVoterCount: number;
    quorumRequired: number;
    quorumFormula: string;
    members: BoardMemberSummary[];
    /** Present only for users who may activate rules. */
    activation?: RulesActivationTarget | null;
    approvalResolutions?: ApprovalResolutionOption[];
}

interface Props extends PageProps {
    settings: SettingDefinition[];
    categories: Record<string, string>;
    rulesProfile?: RulesProfileProp;
    canManage?: boolean;
}

const RULES_SECTION = 'rules';

export default function GovernanceSettingsIndex({
    auth,
    settings,
    categories,
    rulesProfile,
    canManage = false,
}: Props) {
    const [section, setSection] = useState('all');
    const [activateOpen, setActivateOpen] = useState(false);
    const activation = rulesProfile?.activation ?? null;
    const canActivate = Boolean(canManage && activation && !activation.is_active);

    const initialValues: Record<string, string> = {};
    settings.forEach((s) => {
        initialValues[s.key] =
            s.value !== null && s.value !== undefined ? String(s.value) : '';
    });

    const form = useForm<{ settings: Record<string, string> }>({
        settings: initialValues,
    });

    const rulesForm = useForm({
        legal_form: rulesProfile?.profile.legal_form ?? 'charitable_trust',
        governing_document_reference:
            rulesProfile?.profile.governing_document_reference ?? '',
        governing_document_version:
            rulesProfile?.profile.governing_document_version ?? '1.0-candidate',
        quorum_mode:
            rulesProfile?.profile.quorum_mode ?? 'majority_floor_plus_one',
        quorum_formula: rulesProfile?.profile.quorum_formula ?? 'floor(N/2)+1',
        ordinary_threshold_formula:
            rulesProfile?.profile.ordinary_threshold_formula ??
            'for > against of valid votes cast',
        unanimous_denominator_formula:
            rulesProfile?.profile.unanimous_denominator_formula ??
            'assent from all entitled voters',
        written_voting_permitted:
            rulesProfile?.profile.written_voting_permitted ?? false,
        written_unanimity_required:
            rulesProfile?.profile.written_unanimity_required ?? true,
        recusal_policy:
            rulesProfile?.profile.recusal_policy ??
            'exclude_from_presence_and_tally_without_reducing_N',
    });

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
        form.put('/governance/settings', { preserveScroll: true });
    };

    const submitRules = (e: React.FormEvent) => {
        e.preventDefault();
        rulesForm.post('/governance/settings/rules', { preserveScroll: true });
    };

    const customised = settings.filter(
        (s) =>
            s.value !== null &&
            s.value !== undefined &&
            String(s.value) !== String(s.default ?? ''),
    ).length;

    const sectionOptions = [
        { value: 'all', label: 'All sections' },
        ...(rulesProfile
            ? [{ value: RULES_SECTION, label: 'Rules & electorate' }]
            : []),
        ...grouped
            .filter((g) => g.settings.length > 0)
            .map((g) => ({ value: g.key, label: g.label })),
    ];

    const header = (
        <PageHeader
            icon={SettingsIcon}
            title="Governance settings"
            titleChip={
                canManage ? null : (
                    <PageHeaderStatusChip variant="neutral">
                        Read only
                    </PageHeaderStatusChip>
                )
            }
            subline="Voting rules, spend approval thresholds, escalation and variance alerts"
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Configuration"
                        ariaLabel="View all settings"
                        onClick={() => setSection('all')}
                    >
                        <PageHeaderMeterBig>{settings.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {customised} changed from default
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {rulesProfile ? (
                        <>
                            <PageHeaderMeterBlock
                                label="Voting rules"
                                tone={rulesProfile.isConfirmed ? 'success' : 'warning'}
                                ariaLabel="View governance rules and electorate"
                                onClick={() => setSection(RULES_SECTION)}
                            >
                                <PageHeaderMeterBig>
                                    {rulesProfile.isConfirmed ? 'Confirmed' : 'Candidate'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {rulesProfile.isConfirmed
                                        ? 'live voting available'
                                        : 'live voting unavailable'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Entitled voters"
                                ariaLabel="View the electorate"
                                onClick={() => setSection(RULES_SECTION)}
                            >
                                <PageHeaderMeterBig>
                                    {rulesProfile.eligibleVoterCount}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    of {rulesProfile.members.length} board
                                    appointments
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Quorum"
                                ariaLabel="View quorum rules"
                                onClick={() => setSection(RULES_SECTION)}
                            >
                                <PageHeaderMeterBig>
                                    {rulesProfile.quorumRequired}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    voters · {rulesProfile.quorumFormula}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
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
            <Head title="Governance settings" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {showRules && rulesProfile ? (
                        <Card>
                            <CardHeader>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <CardTitle className="flex items-center gap-2">
                                        <Scale className="h-4 w-4 text-status-warning" />
                                        Governance rules & electorate (D1 authority)
                                    </CardTitle>
                                    <StatusBadge
                                        variant={
                                            rulesProfile.isConfirmed
                                                ? 'success'
                                                : 'warning'
                                        }
                                    >
                                        {rulesProfile.statusLabel}
                                    </StatusBadge>
                                </div>
                                <CardDescription>
                                    Candidate rules apply strict majority quorum
                                    (floor(N/2)+1) based on current entitled voting
                                    seats. Recused members are excluded from
                                    presence without reducing the denominator.
                                    Live voting remains blocked until governing
                                    document authority is formally recorded.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-5">
                                <form
                                    onSubmit={submitRules}
                                    className="flex flex-col gap-4"
                                >
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <Label htmlFor="legal_form">
                                                Legal form
                                            </Label>
                                            <Input
                                                id="legal_form"
                                                disabled={!canManage}
                                                value={rulesForm.data.legal_form}
                                                onChange={(e) =>
                                                    rulesForm.setData(
                                                        'legal_form',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={rulesForm.errors.legal_form}
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="governing_document_reference">
                                                Governing document reference
                                            </Label>
                                            <Input
                                                id="governing_document_reference"
                                                disabled={!canManage}
                                                placeholder="e.g. Trust Deed / Constitution 2024"
                                                value={
                                                    rulesForm.data
                                                        .governing_document_reference
                                                }
                                                onChange={(e) =>
                                                    rulesForm.setData(
                                                        'governing_document_reference',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={
                                                    rulesForm.errors
                                                        .governing_document_reference
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="quorum_formula">
                                                Quorum mode & formula
                                            </Label>
                                            <Input
                                                id="quorum_formula"
                                                disabled
                                                value={`${rulesProfile.profile.quorum_mode} — ${rulesProfile.quorumFormula}`}
                                            />
                                            <p className="mt-1 text-caption">
                                                N = {rulesProfile.eligibleVoterCount}{' '}
                                                entitled seats. Required quorum ={' '}
                                                {rulesProfile.quorumRequired}{' '}
                                                participating voters.
                                            </p>
                                        </div>
                                        <div>
                                            <Label htmlFor="ordinary_threshold_formula">
                                                Ordinary decision threshold
                                            </Label>
                                            <Input
                                                id="ordinary_threshold_formula"
                                                disabled={!canManage}
                                                value={
                                                    rulesForm.data
                                                        .ordinary_threshold_formula
                                                }
                                                onChange={(e) =>
                                                    rulesForm.setData(
                                                        'ordinary_threshold_formula',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={
                                                    rulesForm.errors
                                                        .ordinary_threshold_formula
                                                }
                                            />
                                        </div>
                                    </div>

                                    {canManage ? (
                                        <div className="flex flex-wrap justify-end gap-2">
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={rulesForm.processing}
                                            >
                                                <Save className="h-4 w-4" />
                                                Save candidate rules
                                            </Button>
                                            {canActivate ? (
                                                <Button
                                                    type="button"
                                                    onClick={() =>
                                                        setActivateOpen(true)
                                                    }
                                                >
                                                    <ShieldCheck className="h-4 w-4" />
                                                    Activate rules
                                                </Button>
                                            ) : null}
                                        </div>
                                    ) : null}
                                    {canActivate &&
                                    (rulesProfile.approvalResolutions ?? [])
                                        .length === 0 ? (
                                        <p className="text-caption">
                                            Approve these rules through a board
                                            resolution first — activation needs a
                                            carried resolution bound to this
                                            version of the rules.
                                        </p>
                                    ) : null}
                                </form>

                                <div className="flex flex-col gap-3">
                                    <div className="flex items-center gap-2">
                                        <Users className="h-4 w-4 text-muted-foreground" />
                                        <h3 className="text-sm font-semibold">
                                            Electorate denominator (N ={' '}
                                            {rulesProfile.eligibleVoterCount}{' '}
                                            entitled voters)
                                        </h3>
                                    </div>
                                    {rulesProfile.members.length === 0 ? (
                                        <EmptyState
                                            variant="inline"
                                            icon={Users}
                                            title="No board members are appointed yet"
                                        />
                                    ) : (
                                        <div className="overflow-x-auto rounded-md border border-border">
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead>Member</TableHead>
                                                        <TableHead>Board role</TableHead>
                                                        <TableHead>Voting seat</TableHead>
                                                        <TableHead>Active term</TableHead>
                                                        <TableHead>Eligible voter (N)</TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {rulesProfile.members.map(
                                                        (member) => (
                                                            <TableRow key={member.id}>
                                                                <TableCell className="font-medium">
                                                                    {member.name}
                                                                </TableCell>
                                                                <TableCell className="capitalize">
                                                                    {member.role}
                                                                </TableCell>
                                                                <TableCell>
                                                                    {member.role ===
                                                                    'observer' ? (
                                                                        <span className="text-muted-foreground">
                                                                            No (observer)
                                                                        </span>
                                                                    ) : member.role ===
                                                                      'secretary' ? (
                                                                        member.has_voting_seat ? (
                                                                            'Yes (appointed)'
                                                                        ) : (
                                                                            'No (administrative)'
                                                                        )
                                                                    ) : (
                                                                        'Yes'
                                                                    )}
                                                                </TableCell>
                                                                <TableCell className="text-caption">
                                                                    {formatDateOnly(
                                                                        member.term_start,
                                                                    )}{' '}
                                                                    to{' '}
                                                                    {member.term_end
                                                                        ? formatDateOnly(
                                                                              member.term_end,
                                                                          )
                                                                        : 'indefinite'}
                                                                </TableCell>
                                                                <TableCell>
                                                                    <StatusBadge
                                                                        variant={
                                                                            member.can_vote
                                                                                ? 'success'
                                                                                : 'neutral'
                                                                        }
                                                                    >
                                                                        {member.can_vote
                                                                            ? 'Entitled'
                                                                            : 'Not entitled'}
                                                                    </StatusBadge>
                                                                </TableCell>
                                                            </TableRow>
                                                        ),
                                                    )}
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
                                <Card key={group.key}>
                                    <CardHeader>
                                        <CardTitle>{group.label}</CardTitle>
                                    </CardHeader>
                                    <CardContent className="flex flex-col gap-4">
                                        {group.settings.map((s) => (
                                            <div
                                                key={s.key}
                                                className="grid gap-2 lg:grid-cols-[1fr_2fr] lg:items-start"
                                            >
                                                <div>
                                                    <Label htmlFor={s.key}>
                                                        {s.label}
                                                    </Label>
                                                    <p className="mt-1 text-caption">
                                                        {s.description}
                                                    </p>
                                                </div>
                                                <div>
                                                    <Input
                                                        id={s.key}
                                                        disabled={!canManage}
                                                        type={
                                                            s.type === 'number'
                                                                ? 'number'
                                                                : 'text'
                                                        }
                                                        value={
                                                            form.data.settings[
                                                                s.key
                                                            ] ?? ''
                                                        }
                                                        onChange={(e) =>
                                                            form.setData('settings', {
                                                                ...form.data.settings,
                                                                [s.key]: e.target.value,
                                                            })
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            (
                                                                form.errors as Record<
                                                                    string,
                                                                    string | undefined
                                                                >
                                                            )[`settings.${s.key}`]
                                                        }
                                                    />
                                                    <p className="mt-1 text-caption">
                                                        Key:{' '}
                                                        <code className="font-mono">
                                                            {s.key}
                                                        </code>{' '}
                                                        · Default:{' '}
                                                        {String(s.default ?? '—')}
                                                    </p>
                                                </div>
                                            </div>
                                        ))}
                                    </CardContent>
                                </Card>
                            ))}

                            {canManage ? (
                                <div className="flex items-center justify-end">
                                    <Button type="submit" disabled={form.processing}>
                                        <Save className="h-4 w-4" />
                                        {form.processing
                                            ? 'Saving…'
                                            : 'Save settings'}
                                    </Button>
                                </div>
                            ) : null}
                        </form>
                    ) : null}
                </div>
            </PageLayout>

            {canActivate && activation ? (
                <ActivateRulesDialog
                    open={activateOpen}
                    onClose={() => setActivateOpen(false)}
                    target={activation}
                    resolutions={rulesProfile?.approvalResolutions ?? []}
                    canViewResolutions={Boolean(
                        auth.can?.governance?.resolutions?.view,
                    )}
                />
            ) : null}
        </AppLayout>
    );
}
