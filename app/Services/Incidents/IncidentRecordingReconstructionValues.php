<?php

namespace App\Services\Incidents;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/** Pure shape/integrity boundaries only. None admits an actor, source, window or policy. */
final class IncidentRecordingReconstructionValues
{
    public const ATTACH = 'attach_recording_reference';
    public const REVISION_KINDS = ['window_recorded','window_invalidated','recording_attached','recording_superseded','recording_unlinked'];

    public static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field=>$message]);
    }

    public static function exact(array $value, array $keys, string $field): void
    {
        $actual=array_keys($value);sort($actual);sort($keys);
        if ($actual!==$keys) { self::fail($field,'Use only the exact supported fields, including required nullable fields.'); }
    }

    public static function positive(mixed $value, string $field, int $max = PHP_INT_MAX): int
    {
        if (!is_int($value)||$value<1||$value>$max) { self::fail($field,'Use a positive whole-number identity or revision.'); }
        return $value;
    }

    public static function revision(mixed $value, string $field): int
    {
        return self::positive($value,$field,2147483647);
    }

    public static function uuid(mixed $value, string $field): string
    {
        if (!is_string($value)||preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',$value)!==1) {
            self::fail($field,'Use a canonical lowercase UUID for this exact identity.');
        }
        return $value;
    }

    public static function hash(mixed $value, string $field): string
    {
        if (!is_string($value)||preg_match('/\A[0-9a-f]{64}\z/',$value)!==1) { self::fail($field,'Use an exact lowercase SHA-256 metadata digest.'); }
        return $value;
    }

    public static function text(mixed $value, string $field, int $min, int $max): string
    {
        if (!is_string($value)||!mb_check_encoding($value,'UTF-8')||preg_match('/[\p{Cc}\p{Cf}]/u',$value)) {
            self::fail($field,'Use valid UTF-8 text without control or formatting characters.');
        }
        $value=trim($value);
        if (mb_strlen($value,'UTF-8')<$min||mb_strlen($value,'UTF-8')>$max) { self::fail($field,'Use text within the supported length.'); }
        return $value;
    }

    public static function instant(mixed $value, string $field, bool $microseconds = false): string
    {
        $pattern=$microseconds?'/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/':'/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/';
        if (!is_string($value)||preg_match($pattern,$value)!==1) { self::fail($field,'Use the exact canonical UTC instant.'); }
        $format=$microseconds?'Y-m-d\TH:i:s.u\Z':'Y-m-d\TH:i:s\Z';
        $at=DateTimeImmutable::createFromFormat('!'.$format,$value,new DateTimeZone('UTC'));
        $errors=DateTimeImmutable::getLastErrors();
        if ($at===false||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$at->format($format)!==$value) {
            self::fail($field,'Use a real representable UTC date and time.');
        }
        return $value;
    }

    /** Strict JSON types, sorted object keys, preserved list order; rejects floats, objects and invalid UTF-8. */
    public static function digest(mixed $value): string
    {
        $canonical=function(mixed $part)use(&$canonical):mixed{
            if (is_array($part)) {
                if (!array_is_list($part)) {
                    foreach(array_keys($part)as$key){if(!is_string($key)){self::fail('digest','Use string object keys or a contiguous list.');}}
                    ksort($part,SORT_STRING);
                }
                foreach($part as$key=>$child){$part[$key]=$canonical($child);}return$part;
            }
            if ($part!==null&&!is_bool($part)&&!is_int($part)&&!is_string($part)) { self::fail('digest','Use strict JSON values, never executable or floating-point state.'); }
            if (is_string($part)&&!mb_check_encoding($part,'UTF-8')) { self::fail('digest','Use valid UTF-8 metadata.'); }
            return$part;
        };
        return hash('sha256',json_encode($canonical($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    /** The approved public attach body only. Camera/recorder/times/DTO/policy claims are forbidden here. */
    public static function attachInput(array $input): array
    {
        self::exact($input,['expected_actor_id','idempotency_key','expected_incident_evidence_version','evidence_window_revision','reference_uuid','purpose'],'command');
        return ['expected_actor_id'=>self::positive($input['expected_actor_id'],'expected_actor_id'),
            'idempotency_key'=>self::uuid($input['idempotency_key'],'idempotency_key'),
            'expected_incident_evidence_version'=>self::revision($input['expected_incident_evidence_version'],'expected_incident_evidence_version'),
            'evidence_window_revision'=>self::revision($input['evidence_window_revision'],'evidence_window_revision'),
            'reference_uuid'=>self::uuid($input['reference_uuid'],'reference_uuid'),'purpose'=>self::text($input['purpose'],'purpose',10,500)];
    }

    public static function attachFingerprint(int $actualActorId, int $canonicalIncidentId, array $input): string
    {
        $input=self::attachInput($input);self::positive($actualActorId,'actor_id');self::positive($canonicalIncidentId,'incident_id');
        if ($input['expected_actor_id']!==$actualActorId) { self::fail('expected_actor_id','The command belongs to another actor.'); }
        return self::digest(['schema_version'=>1,'actor_id'=>$actualActorId,'incident_id'=>$canonicalIncidentId,'command'=>self::ATTACH,
            'expected_incident_evidence_version'=>$input['expected_incident_evidence_version'],'evidence_window_revision'=>$input['evidence_window_revision'],
            'reference_uuid'=>$input['reference_uuid'],'purpose'=>$input['purpose']]);
    }

    /** INTERNAL canonical source metadata, never a browser certificate. The future writer derives and replays it. */
    public static function contextAnchor(array $anchor): array
    {
        $links=['control_room_alert_id','hs_event_id','fleet_incident_id','shift_id','respite_stay_id','service_context_id'];
        self::exact($anchor,['incident_id','client_id','raw_site_id','effective_site_id','occurred_at','incident_type','source','journey_sha256',...$links],'context_anchor');
        $incidentType=self::text($anchor['incident_type'],'context_anchor.incident_type',1,120);
        if ($incidentType!==$anchor['incident_type']) { self::fail('context_anchor.incident_type','Preserve the exact canonical incident type; do not normalise a different value into a supported trigger.'); }
        $result=['incident_id'=>self::positive($anchor['incident_id'],'context_anchor.incident_id'),
            'client_id'=>$anchor['client_id']===null?null:self::positive($anchor['client_id'],'context_anchor.client_id'),
            'raw_site_id'=>$anchor['raw_site_id']===null?null:self::positive($anchor['raw_site_id'],'context_anchor.raw_site_id'),
            'effective_site_id'=>self::positive($anchor['effective_site_id'],'context_anchor.effective_site_id'),
            'occurred_at'=>self::instant($anchor['occurred_at'],'context_anchor.occurred_at',true),
            'incident_type'=>$incidentType,
            'source'=>self::text($anchor['source'],'context_anchor.source',1,40),'journey_sha256'=>self::hash($anchor['journey_sha256'],'context_anchor.journey_sha256')];
        foreach($links as$key){$result[$key]=$anchor[$key]===null?null:self::positive($anchor[$key],'context_anchor.'.$key);}
        return$result;
    }

    /** Set members are explicit camera/recorder/Site identities; source approval and privacy remain independent. */
    public static function sourceSet(array $sources, int $effectiveSiteId): array
    {
        self::positive($effectiveSiteId,'effective_site_id');
        if (!array_is_list($sources)) { self::fail('approved_sources','Record an explicit approved source set; an empty list grants no source.'); }
        $out=[];$seen=[];
        foreach($sources as$source){
            if (!is_array($source)) { self::fail('approved_sources','Use explicit approved source identities.'); }
            self::exact($source,['camera_id','recorder_device_id','site_id'],'approved_sources');
            $member=['camera_id'=>self::positive($source['camera_id'],'camera_id'),'recorder_device_id'=>self::positive($source['recorder_device_id'],'recorder_device_id'),
                'site_id'=>self::positive($source['site_id'],'site_id')];
            $key=$member['camera_id'].':'.$member['recorder_device_id'].':'.$member['site_id'];
            if ($member['site_id']!==$effectiveSiteId||$member['camera_id']===$member['recorder_device_id']||isset($seen[$key])) {
                self::fail('approved_sources','Use distinct camera/recorder sources at the approved canonical Site.');
            }
            $seen[$key]=true;$out[]=$member;
        }
        usort($out,static fn(array $a,array $b):int=>[$a['camera_id'],$a['recorder_device_id'],$a['site_id']]<=>[$b['camera_id'],$b['recorder_device_id'],$b['site_id']]);
        return$out;
    }

    /** INTERNAL candidate values only; policy FK/digest is not verified or approved by this pure function. */
    public static function windowRecord(array $input): array
    {
        self::exact($input,['incident_id','window_revision','introduced_evidence_version','actor_user_id','recorded_at','start_at','end_at',
            'rule_identifier','rule_revision','policy_change_id','rule_snapshot_sha256','override_reason','context_anchor','approved_sources','audit_log_id'],'window');
        if (!is_array($input['context_anchor'])||!is_array($input['approved_sources'])) { self::fail('window','Use complete canonical anchor and source-set metadata.'); }
        $anchor=self::contextAnchor($input['context_anchor']);$sources=self::sourceSet($input['approved_sources'],$anchor['effective_site_id']);
        $start=self::instant($input['start_at'],'start_at');$end=self::instant($input['end_at'],'end_at');
        if ($start>=$end) { self::fail('end_at','The reconstruction window must end after its start.'); }
        $incidentId=self::positive($input['incident_id'],'incident_id');
        if ($incidentId!==$anchor['incident_id']) { self::fail('context_anchor','The window and canonical incident must match.'); }
        $record=['incident_id'=>$incidentId,'window_revision'=>self::revision($input['window_revision'],'window_revision'),
            'introduced_evidence_version'=>self::revision($input['introduced_evidence_version'],'introduced_evidence_version'),
            'actor_user_id'=>self::positive($input['actor_user_id'],'actor_user_id'),'recorded_at'=>self::instant($input['recorded_at'],'recorded_at',true),
            'start_at'=>$start,'end_at'=>$end,'rule_identifier'=>self::text($input['rule_identifier'],'rule_identifier',1,128),
            'rule_revision'=>self::revision($input['rule_revision'],'rule_revision'),'policy_change_id'=>self::positive($input['policy_change_id'],'policy_change_id'),
            'rule_snapshot_sha256'=>self::hash($input['rule_snapshot_sha256'],'rule_snapshot_sha256'),
            'override_reason'=>$input['override_reason']===null?null:self::text($input['override_reason'],'override_reason',10,500),
            'context_anchor'=>$anchor,'approved_sources'=>$sources,'audit_log_id'=>self::positive($input['audit_log_id'],'audit_log_id'),
            'effective_site_id'=>$anchor['effective_site_id'],'client_id'=>$anchor['client_id'],
            'context_anchor_sha256'=>self::digest($anchor),'source_set_sha256'=>self::digest($sources)];
        // The audit FK is checked separately; exclude it so strict audit metadata can bind this digest without a cycle.
        $record['window_sha256']=self::digest(array_diff_key($record,['audit_log_id'=>true]));
        return$record;
    }

    public static function assertWindowRecord(array $window): void
    {
        $derived=['effective_site_id','client_id','context_anchor_sha256','source_set_sha256','window_sha256'];
        $raw=array_diff_key($window,array_flip($derived));
        $expected=self::windowRecord($raw);
        if (self::digest($expected)!==self::digest($window)) { self::fail('window','The entire original window/context/source inventory changed.'); }
    }

    /** Exact immutable SOURCE reference only, no envelope/current_source or arbitrary provider DTO. */
    public static function referenceForWindow(array $reference, array $window, string $expectedReferenceUuid): array
    {
        self::assertWindowRecord($window);
        self::exact($reference,['schema_version','reference_uuid','camera_id','recorder_device_id','site_id','provider','console_id','provider_camera_id','provider_recorder_id',
            'source_revision','protect_version','requested_start_at','requested_end_at','created_at','source_verified_at','recording_verified_at','vendor_retention_until',
            'state','preserved','binding_basis','window_coverage','created_by','purpose','reader_href'],'reference');
        if ($reference['schema_version']!==1||$reference['provider']!=='unifi'||$reference['state']!=='reference_only'||$reference['preserved']!==false
            ||$reference['binding_basis']!=='reviewed_source_revision'||$reference['window_coverage']!=='not_verified'
            ||$reference['recording_verified_at']!==null||$reference['vendor_retention_until']!==null) {
            self::fail('reference','Only the actual reference-only, unverified and unpreserved source protocol is supported.');
        }
        $uuid=self::uuid($reference['reference_uuid'],'reference_uuid');
        if ($uuid!==self::uuid($expectedReferenceUuid,'expected_reference_uuid')) { self::fail('reference_uuid','The resolved receipt is not the selected immutable reference.'); }
        foreach(['camera_id','recorder_device_id','site_id','source_revision']as$key){self::positive($reference[$key],'reference.'.$key);}
        foreach(['console_id','provider_camera_id','provider_recorder_id']as$key){
            if (!is_string($reference[$key])||preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/',$reference[$key])!==1) { self::fail('reference.'.$key,'Use the original reviewed provider identity.'); }
        }
        if (!is_string($reference['protect_version'])||$reference['protect_version']===''||!mb_check_encoding($reference['protect_version'],'UTF-8')) {
            self::fail('reference.protect_version','Preserve the original source version string.');
        }
        // Installed resolver time() emits whole-second UTC; its nullable source verification remains unknown.
        foreach(['requested_start_at','requested_end_at','created_at']as$key){self::instant($reference[$key],'reference.'.$key);}
        if ($reference['source_verified_at']!==null) { self::instant($reference['source_verified_at'],'reference.source_verified_at'); }
        if (!is_array($reference['created_by'])) { self::fail('reference.created_by','Use original source creator metadata.'); }
        self::exact($reference['created_by'],['id','name'],'reference.created_by');self::positive($reference['created_by']['id'],'reference.created_by.id');
        // Creator names are captured User strings, not purpose fields: do not silently trim/change their bytes.
        if (!is_string($reference['created_by']['name'])||!mb_check_encoding($reference['created_by']['name'],'UTF-8')
            ||mb_strlen($reference['created_by']['name'],'UTF-8')>255) { self::fail('reference.created_by.name','Preserve original source creator metadata.'); }
        if (self::text($reference['purpose'],'reference.purpose',10,500)!==$reference['purpose']) {
            self::fail('reference.purpose','Use the original canonical source purpose without normalising its bytes.');
        }
        if ($reference['reader_href']!=='/security-devices/cctv/recording-references/'.$uuid) { self::fail('reference.reader_href','Use the original canonical source reader identity.'); }
        if ($reference['requested_start_at']!==$window['start_at']||$reference['requested_end_at']!==$window['end_at']) {
            self::fail('reference','The receipt must match the approved immutable window exactly, without truncation or inferred coverage.');
        }
        $member=['camera_id'=>$reference['camera_id'],'recorder_device_id'=>$reference['recorder_device_id'],'site_id'=>$reference['site_id']];
        if (!in_array($member,$window['approved_sources'],true)) { self::fail('reference','The original camera/recorder/Site is outside this approved source set.'); }
        // Deliberately return original bytes/values. No regenerated provider metadata, names or UUID binding.
        return $reference;
    }

    /** Immutable safe confirmation core only. Add current state only after fresh parent/action/source checks. */
    public static function attachConfirmation(int $actorId, int $incidentId, array $input, array $window,
        array $reference, string $attachmentUuid, int $confirmedVersion, string $confirmedAt): array
    {
        $input=self::attachInput($input);self::attachFingerprint($actorId,$incidentId,$input);
        self::referenceForWindow($reference,$window,$input['reference_uuid']);
        if ($window['incident_id']!==$incidentId||$window['window_revision']!==$input['evidence_window_revision']
            ||$window['introduced_evidence_version']>$input['expected_incident_evidence_version']
            ||$input['expected_incident_evidence_version']>=2147483647||$confirmedVersion!==$input['expected_incident_evidence_version']+1) {
            self::fail('confirmation','The committed incident, original window and incremented evidence revision must match.');
        }
        return ['schema_version'=>1,'actor_id'=>$actorId,'incident_id'=>$incidentId,
            'command'=>['idempotency_key'=>$input['idempotency_key'],'kind'=>self::ATTACH,'confirmed_incident_evidence_version'=>$confirmedVersion,
                'confirmed_evidence_window_revision'=>$input['evidence_window_revision'],'confirmed_at'=>self::instant($confirmedAt,'confirmed_at',true)],
            'attachment'=>['attachment_uuid'=>self::uuid($attachmentUuid,'attachment_uuid'),'kind'=>'recording_reference','reference_uuid'=>$reference['reference_uuid'],
                'requested_start_at'=>$reference['requested_start_at'],'requested_end_at'=>$reference['requested_end_at'],
                'state'=>'reference_only','preserved'=>false,'window_coverage'=>'not_verified']];
    }
    /** INTERNAL attributable journal shape. Supersede/unlink commands still need their own approved protocol. */
    public static function revisionRecord(array $input): array
    {
        self::exact($input,['incident_id','incident_evidence_version','kind','window_revision','attachment_uuid','predecessor_attachment_uuid',
            'actor_user_id','recorded_at','reason','audit_log_id'],'revision');
        if (!in_array($input['kind'],self::REVISION_KINDS,true)) { self::fail('kind','Use a recognised evidence revision kind.'); }
        $kind=$input['kind'];$attachment=$input['attachment_uuid']===null?null:self::uuid($input['attachment_uuid'],'attachment_uuid');
        $predecessor=$input['predecessor_attachment_uuid']===null?null:self::uuid($input['predecessor_attachment_uuid'],'predecessor_attachment_uuid');
        $reason=$input['reason']===null?null:self::text($input['reason'],'reason',10,500);
        if (($input['incident_evidence_version']===1&&$kind!=='window_recorded')
            ||(str_starts_with($kind,'window_')&&$attachment!==null)||(!str_starts_with($kind,'window_')&&$attachment===null)
            ||($kind==='recording_superseded'?($predecessor===null||$predecessor===$attachment):$predecessor!==null)
            ||(in_array($kind,['window_invalidated','recording_superseded','recording_unlinked'],true)&&$reason===null)) {
            self::fail('revision','Record the exact original target and attributable correction reason.');
        }
        return ['incident_id'=>self::positive($input['incident_id'],'incident_id'),'incident_evidence_version'=>self::revision($input['incident_evidence_version'],'incident_evidence_version'),
            'kind'=>$kind,'window_revision'=>self::revision($input['window_revision'],'window_revision'),'attachment_uuid'=>$attachment,'predecessor_attachment_uuid'=>$predecessor,
            'actor_user_id'=>self::positive($input['actor_user_id'],'actor_user_id'),'recorded_at'=>self::instant($input['recorded_at'],'recorded_at',true),
            'reason'=>$reason,'audit_log_id'=>self::positive($input['audit_log_id'],'audit_log_id')];
    }
}
