<?php

return [
    /*
    | Chemical exposures reach the H&S register automatically, straight after the save (job 3, Stephan 26 Oct).
    | Codex's delivery tests switch this off to exercise the manager's delivery and retry path as written.
    | Leave it on in every real environment: switched off, no new exposure reaches the register or its WorkSafe review.
    */
    'exposure_automatic_delivery' => (bool) env('HS_EXPOSURE_AUTOMATIC_DELIVERY', true),
];
