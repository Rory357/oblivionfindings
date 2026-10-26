<?php

namespace Tests\Support;

/**
 * Codex tests for code this H&S build doesn't include (Stephan, 25 Oct: "skip with reasons"). Each skip names its
 * group, why it can't pass yet and the follow-up it waits on; the list is in docs/health-safety-build/PLAN.md.
 */
final class NotInThisBuild
{
    public const NEVER_BUILT = 'never built or wired up by Codex';

    public const OTHER_MODULE = "Codex's change to a module outside this build";

    public const UNMET_CHECK = "a check Codex's own code never met";

    public const TEST_SHAPE = 'test shape only: the rule it checks is met';

    public const DECIDED = "left out by Stephan's decision";

    public static function reason(string $group, string $why, string $followUp): string
    {
        return "Not in this H&S build ({$group}): {$why} Follow-up: {$followUp}";
    }
}
