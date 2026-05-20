<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\AuditableChanges;
class BoardSkillsMatrix extends Model
{
    use AuditableChanges;

    protected $table = 'board_skills_matrix';

    protected $fillable = [
        'board_member_id', 'skill_category', 'proficiency_level',
        'notes', 'assessed_at', 'assessed_by',
    ];

    protected $casts = [
        'assessed_at' => 'date',
    ];

    public const CATEGORIES = [
        'governance' => 'Governance & Board Practice',
        'finance' => 'Finance & Accounting',
        'legal' => 'Legal & Regulatory',
        'clinical' => 'Clinical & Health Services',
        'it' => 'IT & Digital',
        'hr' => 'Human Resources',
        'risk' => 'Risk Management',
        'strategy' => 'Strategy & Planning',
        'sector_knowledge' => 'Disability/Supported Living Sector',
        'te_tiriti' => 'Te Tiriti o Waitangi',
        'fundraising' => 'Fundraising & Stakeholder Relations',
    ];

    public const PROFICIENCY_LEVELS = [
        1 => 'Awareness',
        2 => 'Working Knowledge',
        3 => 'Proficient',
        4 => 'Expert',
    ];

    public function boardMember(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class);
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function getCategoryLabel(): string
    {
        return self::CATEGORIES[$this->skill_category] ?? $this->skill_category;
    }

    public function getProficiencyLabel(): string
    {
        return self::PROFICIENCY_LEVELS[$this->proficiency_level] ?? 'Unknown';
    }

    public static function getGapAnalysis(): array
    {
        $members = BoardMember::active()->with('skills')->get();
        $gaps = [];

        foreach (self::CATEGORIES as $key => $label) {
            $maxProficiency = $members->flatMap->skills
                ->where('skill_category', $key)
                ->max('proficiency_level') ?? 0;

            $avgProficiency = $members->flatMap->skills
                ->where('skill_category', $key)
                ->avg('proficiency_level') ?? 0;

            $memberCount = $members->filter(fn($m) => $m->skills->where('skill_category', $key)->isNotEmpty())->count();

            $gaps[$key] = [
                'category' => $label,
                'max_proficiency' => $maxProficiency,
                'avg_proficiency' => round($avgProficiency, 1),
                'members_with_skill' => $memberCount,
                'total_members' => $members->count(),
                'gap_level' => $maxProficiency < 2 ? 'critical' : ($maxProficiency < 3 ? 'moderate' : 'adequate'),
            ];
        }

        return $gaps;
    }
}
