import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, FileText, Save, Scale, Settings as SettingsIcon, ShieldAlert, Users } from 'lucide-react';

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
}

interface Props extends PageProps {
    settings: SettingDefinition[];
    categories: Record<string, string>;
    rulesProfile?: RulesProfileProp;
    canManage?: boolean;
}

export default function GovernanceSettingsIndex({
    auth,
    settings,
    categories,
    rulesProfile,
    canManage = false,
}: Props) {
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
        governing_document_reference: rulesProfile?.profile.governing_document_reference ?? '',
        governing_document_version: rulesProfile?.profile.governing_document_version ?? '1.0-candidate',
        quorum_mode: rulesProfile?.profile.quorum_mode ?? 'majority_floor_plus_one',
        quorum_formula: rulesProfile?.profile.quorum_formula ?? 'floor(N/2)+1',
        ordinary_threshold_formula: rulesProfile?.profile.ordinary_threshold_formula ?? 'for > against of valid votes cast',
        unanimous_denominator_formula: rulesProfile?.profile.unanimous_denominator_formula ?? 'assent from all entitled voters',
        written_voting_permitted: rulesProfile?.profile.written_voting_permitted ?? false,
        written_unanimity_required: rulesProfile?.profile.written_unanimity_required ?? true,
        recusal_policy: rulesProfile?.profile.recusal_policy ?? 'exclude_from_presence_and_tally_without_reducing_N',
    });

    const activateForm = useForm({
        governing_document_reference: rulesProfile?.profile.governing_document_reference ?? '',
        governing_document_version: rulesProfile?.profile.governing_document_version ?? '',
        approved_by_resolution_id: '',
    });

    const grouped = Object.entries(categories).map(([key, label]) => ({
        key,
        label,
        settings: settings.filter((s) => s.category === key),
    }));

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put('/governance/settings');
    };

    const submitRules = (e: React.FormEvent) => {
        e.preventDefault();
        rulesForm.post('/governance/settings/rules');
    };

    const submitActivate = (e: React.FormEvent) => {
        e.preventDefault();
        activateForm.post('/governance/settings/rules/activate');
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Settings', href: '/governance/settings' },
            ]}
        >
            <Head title="Governance Settings" />

            <PageLayout
                hero={
                    <PageHero
                        icon={SettingsIcon}
                        category="governance"
                        title="Governance Settings"
                        description="Configure escalation paths, spend approval thresholds, and variance alert rules."
                    />
                }
            >
                {rulesProfile && (
                    <div className="space-y-6">
                        <Card className="border-l-4 border-l-status-warning">
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <Scale className="h-5 w-5 text-status-warning" />
                                        <CardTitle>Governance Rules & Electorate (D1 Authority)</CardTitle>
                                    </div>
                                    <StatusBadge
                                        variant={rulesProfile.isConfirmed ? 'success' : 'warning'}
                                    >
                                        {rulesProfile.statusLabel}
                                    </StatusBadge>
                                </div>
                                <CardDescription>
                                    Candidate rules apply strict majority quorum (floor(N/2)+1) based on current entitled voting seats.
                                    Recused members are excluded from presence without reducing the denominator.
                                    Live voting remains blocked until governing document authority is formally recorded.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <form onSubmit={submitRules} className="space-y-4">
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <Label htmlFor="legal_form">Legal Form</Label>
                                            <Input
                                                id="legal_form"
                                                disabled={!canManage}
                                                value={rulesForm.data.legal_form}
                                                onChange={(e) => rulesForm.setData('legal_form', e.target.value)}
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="governing_document_reference">Governing Document Reference</Label>
                                            <Input
                                                id="governing_document_reference"
                                                disabled={!canManage}
                                                placeholder="e.g. Trust Deed / Constitution 2024"
                                                value={rulesForm.data.governing_document_reference}
                                                onChange={(e) => rulesForm.setData('governing_document_reference', e.target.value)}
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="quorum_formula">Quorum Mode & Formula</Label>
                                            <Input
                                                id="quorum_formula"
                                                disabled
                                                value={`${rulesProfile.profile.quorum_mode} — ${rulesProfile.quorumFormula}`}
                                            />
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                N = {rulesProfile.eligibleVoterCount} entitled seats. Required quorum = {rulesProfile.quorumRequired} participating voters.
                                            </p>
                                        </div>
                                        <div>
                                            <Label htmlFor="ordinary_threshold_formula">Ordinary Decision Threshold</Label>
                                            <Input
                                                id="ordinary_threshold_formula"
                                                disabled={!canManage}
                                                value={rulesForm.data.ordinary_threshold_formula}
                                                onChange={(e) => rulesForm.setData('ordinary_threshold_formula', e.target.value)}
                                            />
                                        </div>
                                    </div>

                                    {canManage && (
                                        <div className="flex justify-end pt-2">
                                            <Button type="submit" variant="outline" disabled={rulesForm.processing}>
                                                <Save className="mr-2 h-4 w-4" />
                                                Save Candidate Rules
                                            </Button>
                                        </div>
                                    )}
                                </form>

                                <div className="space-y-3 pt-2">
                                    <div className="flex items-center gap-2">
                                        <Users className="h-4 w-4 text-muted-foreground" />
                                        <h4 className="text-sm font-semibold">
                                            Electorate Denominator (N = {rulesProfile.eligibleVoterCount} Entitled Voters)
                                        </h4>
                                    </div>
                                    <div className="rounded-md border text-sm overflow-x-auto">
                                        <table className="w-full text-left">
                                            <thead className="bg-muted/50 text-xs font-medium text-muted-foreground">
                                                <tr>
                                                    <th className="p-2.5">Member Name</th>
                                                    <th className="p-2.5">Board Role</th>
                                                    <th className="p-2.5">Voting Seat</th>
                                                    <th className="p-2.5">Active Term</th>
                                                    <th className="p-2.5">Eligible Voter (N)</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {rulesProfile.members.map((member) => (
                                                    <tr key={member.id} className="hover:bg-muted/20">
                                                        <td className="p-2.5 font-medium">{member.name}</td>
                                                        <td className="p-2.5 capitalize">{member.role}</td>
                                                        <td className="p-2.5">
                                                            {member.role === 'observer' ? (
                                                                <span className="text-muted-foreground">No (Observer)</span>
                                                            ) : member.role === 'secretary' ? (
                                                                member.has_voting_seat ? 'Yes (Appointed)' : 'No (Administrative)'
                                                            ) : (
                                                                'Yes'
                                                            )}
                                                        </td>
                                                        <td className="p-2.5 text-xs text-muted-foreground">
                                                            {member.term_start ?? '—'} to {member.term_end ?? 'Indefinite'}
                                                        </td>
                                                        <td className="p-2.5">
                                                            <StatusBadge variant={member.can_vote ? 'success' : 'neutral'}>
                                                                {member.can_vote ? 'Entitled' : 'Not Entitled'}
                                                            </StatusBadge>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                <form onSubmit={submit} className="space-y-6">
                    {grouped.map((group) =>
                        group.settings.length === 0 ? null : (
                            <Card key={group.key}>
                                <CardHeader>
                                    <CardTitle>{group.label}</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {group.settings.map((s) => (
                                        <div
                                            key={s.key}
                                            className="grid gap-2 lg:grid-cols-[1fr,2fr] lg:items-start"
                                        >
                                            <div>
                                                <Label htmlFor={s.key}>
                                                    {s.label}
                                                </Label>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {s.description}
                                                </p>
                                            </div>
                                            <div>
                                                <Input
                                                    id={s.key}
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
                                                        form.setData(
                                                            'settings',
                                                            {
                                                                ...form.data
                                                                    .settings,
                                                                [s.key]:
                                                                    e.target
                                                                        .value,
                                                            },
                                                        )
                                                    }
                                                />
                                                {form.errors[
                                                    `settings.${s.key}` as never
                                                ] && (
                                                    <p className="mt-1 text-xs text-status-critical">
                                                        {String(
                                                            form.errors[
                                                                `settings.${s.key}` as never
                                                            ],
                                                        )}
                                                    </p>
                                                )}
                                                <p className="mt-1 text-xs text-muted-foreground">
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
                        ),
                    )}

                    <div className="flex items-center justify-end gap-2">
                        <Button type="submit" disabled={form.processing}>
                            <Save className="mr-2 h-4 w-4" />
                            {form.processing ? 'Saving…' : 'Save settings'}
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
