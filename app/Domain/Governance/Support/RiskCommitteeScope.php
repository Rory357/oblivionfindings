<?php

namespace App\Domain\Governance\Support;

use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\CommitteeMembership;
use App\Domain\Governance\Models\RiskRegisterEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which risks each board committee oversees — ONE map shared by the committee
 * risk view and the committee report, covering all eight risk categories so
 * no category belongs to no committee. A risk assigned directly to a
 * committee (`risk_committee`) goes to that committee whatever its category.
 *
 * Committees themselves are the real BoardCommittee records and their current
 * appointments — never a hard-coded committee list.
 */
final class RiskCommitteeScope
{
    /** committee_type => risk categories it oversees. */
    public const CATEGORY_MAP = [
        // The audit and risk committee oversees the risk framework as a whole.
        'audit_risk' => ['financial', 'legal_compliance', 'it_cyber', 'operational', 'reputational'],
        'people' => ['workforce', 'client_safety', 'clinical'],
        'finance' => ['financial'],
    ];

    private const ROLE_LABELS = [
        'chair' => 'Chair',
        'deputy_chair' => 'Deputy chair',
        'member' => 'Member',
        'adviser' => "Adviser (can't vote)",
        'observer' => "Observer (can't vote)",
    ];

    /** @return array<int, string> */
    public static function categoriesFor(?string $committeeType): array
    {
        return self::CATEGORY_MAP[(string) $committeeType] ?? [];
    }

    /** Restrict a risk query to the risks a committee oversees. */
    public static function apply(Builder $query, BoardCommittee $committee): Builder
    {
        $categories = self::categoriesFor($committee->committee_type);

        return $query->where(function (Builder $scoped) use ($categories, $committee) {
            $scoped->where('risk_committee', $committee->committee_type)
                ->orWhere(function (Builder $byCategory) use ($categories) {
                    $byCategory->whereNull('risk_committee')
                        ->whereIn('category', $categories === [] ? ['__none__'] : $categories);
                });
        });
    }

    /**
     * Resolve a committee from a route value: its id, or (for older links)
     * its committee type. Only active committees are reachable.
     */
    public static function resolve(string $value): ?BoardCommittee
    {
        $query = BoardCommittee::query()->active();

        if (ctype_digit($value)) {
            return $query->whereKey((int) $value)->first();
        }

        return $query->where('committee_type', $value)->orderBy('id')->first();
    }

    /**
     * Active committees that oversee risks, for switchers and links.
     *
     * @return array<int, array{id: int, name: string, type: string}>
     */
    public static function committeeOptions(): array
    {
        return BoardCommittee::query()
            ->active()
            ->whereIn('committee_type', array_keys(self::CATEGORY_MAP))
            ->orderBy('name')
            ->get(['id', 'name', 'committee_type'])
            ->map(fn (BoardCommittee $committee) => [
                'id' => (int) $committee->id,
                'name' => (string) $committee->name,
                'type' => (string) $committee->committee_type,
            ])
            ->all();
    }

    /**
     * Active committees that oversee this risk.
     *
     * @return array<int, array{id: int, name: string, type: string}>
     */
    public static function committeesOverseeing(RiskRegisterEntry $risk): array
    {
        return collect(self::committeeOptions())
            ->filter(fn (array $committee) => $risk->risk_committee
                ? $risk->risk_committee === $committee['type']
                : in_array($risk->category, self::categoriesFor($committee['type']), true))
            ->values()
            ->all();
    }

    /**
     * The committee's current appointments, chair first.
     *
     * @return array<int, array{name: string, role: string, is_chair: bool}>
     */
    public static function members(BoardCommittee $committee): array
    {
        return CommitteeMembership::query()
            ->active()
            ->where('board_committee_id', $committee->id)
            ->with('boardMember.user:id,name')
            ->get()
            ->filter(fn (CommitteeMembership $membership) => $membership->boardMember?->user !== null)
            ->sortBy(fn (CommitteeMembership $membership) => [$membership->role === 'chair' ? 0 : 1, $membership->boardMember->user->name])
            ->map(fn (CommitteeMembership $membership) => [
                'name' => (string) $membership->boardMember->user->name,
                'role' => self::ROLE_LABELS[(string) $membership->role] ?? GovernanceLabels::humanise((string) $membership->role),
                'is_chair' => $membership->role === 'chair',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function categoryOptions(BoardCommittee $committee): array
    {
        return collect(self::categoriesFor($committee->committee_type))
            ->map(fn (string $category) => [
                'value' => $category,
                'label' => GovernanceLabels::label('risk_category', $category),
            ])
            ->all();
    }

    /** Current (open or accepted) risks the committee oversees, highest after controls first. */
    public static function currentRisks(BoardCommittee $committee): Collection
    {
        return self::apply(RiskRegisterEntry::query()->current(), $committee)
            ->with('riskOwner:id,name')
            ->orderByDesc('residual_score')
            ->get();
    }
}
