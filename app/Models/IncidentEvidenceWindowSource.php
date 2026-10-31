<?php

namespace App\Models;

/** One member of the complete approved source set; compare the WHOLE set against its immutable window digest. */
final class IncidentEvidenceWindowSource extends IncidentEvidenceImmutableRecord
{
    protected $casts = ['incident_id'=>'integer','window_revision'=>'integer','site_id'=>'integer','camera_id'=>'integer','recorder_device_id'=>'integer'];
}
