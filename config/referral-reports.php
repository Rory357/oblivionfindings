<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Small-group rule for referral reports
    |--------------------------------------------------------------------------
    |
    | Report counts from 1 up to one less than this number are shown as "Fewer than N" on the
    | Reports page and in summary exports, so a small group of people cannot be picked out. When
    | one group in a breakdown is hidden, the next-smallest is hidden too, so a hidden number cannot
    | be worked out by subtracting the others from the total. Zero is shown as zero.
    |
    | The rule applies to everyone, whatever their access. Exact numbers stay only in the matching
    | referrals list and the records export, which show individual records under record access.
    |
    | Set by the organisation's privacy policy. 1 turns the rule off.
    |
    */

    'small_group_threshold' => (int) env('REFERRAL_REPORTS_SMALL_GROUP_THRESHOLD', 5),
];
