<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Bounded questions are source information, never consent, funding or activation decisions. */
final class ReferralForms
{
    public const DESTINATIONS = ['intake' => 'referral_intake', 'assessment' => 'referral_assessment', 'funding' => 'referral_funding'];

    public function validateConfiguration(array $configuration): void
    {
        $fields = $configuration['forms']['fields'] ?? [];
        Validator::make(['fields' => $fields], [
            'fields' => 'array|max:40',
            'fields.*' => 'required|array:id,label,help,type,options,enabled,public,required,audience,destination,service_ids,purposes',
            'fields.*.id' => 'required|string|regex:/^[a-z][a-z0-9_]{1,63}$/|distinct',
            'fields.*.label' => 'required|string|max:160', 'fields.*.help' => 'present|nullable|string|max:500',
            'fields.*.type' => ['required', Rule::in(['text', 'date', 'number', 'choice', 'checkbox'])],
            'fields.*.options' => 'present|array|max:30',
            'fields.*.options.*' => 'required|string|max:120',
            'fields.*.enabled' => 'required|boolean', 'fields.*.public' => 'required|boolean',
            'fields.*.required' => ['required', Rule::in(['optional', 'intake', 'handover'])],
            'fields.*.audience' => ['required', Rule::in(array_keys(self::DESTINATIONS))],
            'fields.*.destination' => ['required', Rule::in(array_values(self::DESTINATIONS))],
            'fields.*.service_ids' => 'present|array|max:30',
            'fields.*.service_ids.*' => ['required', Rule::in([...array_column($configuration['services'], 'id'), 'unsure'])],
            'fields.*.purposes' => 'required|array|min:1|max:4',
            'fields.*.purposes.*' => ['required', Rule::in(['support', 'referral', 'funding', 'callback'])],
        ])->validate();
        foreach ($fields as $field) {
            foreach (['options', 'service_ids', 'purposes'] as $collection) {
                if (count(array_unique($field[$collection])) !== count($field[$collection])) {
                    throw ValidationException::withMessages(['configuration.forms' => 'Do not repeat options or conditions within the same question.']);
                }
            }
            if ($field['destination'] !== self::DESTINATIONS[$field['audience']]
                || ($field['type'] === 'choice' ? $field['options'] === [] : $field['options'] !== [])
                || in_array('__unanswered', $field['options'], true)
                || ($field['required'] === 'intake' && ! $field['public'])) {
                throw ValidationException::withMessages(['configuration.forms' => 'Match the destination to its information audience, provide options only for choice questions, and make intake-required questions available at intake.']);
            }
        }
    }

    public function stableReferences(array $before, array $after): void
    {
        $next = array_column($after['forms']['fields'] ?? [], null, 'id');
        foreach ($before['forms']['fields'] ?? [] as $field) {
            $candidate = $next[$field['id']] ?? null;
            if (! $candidate || Arr::only($field, ['type', 'audience', 'destination']) !== Arr::only($candidate, ['type', 'audience', 'destination'])) {
                throw ValidationException::withMessages(['configuration.forms' => 'Keep published field references, types, audiences and destinations stable. Archive the question and add a new reference for a different meaning.']);
            }
        }
    }

    public function publicFields(array $configuration): array
    {
        return array_values(array_map(fn ($field) => Arr::only($field, ['id', 'label', 'help', 'type', 'options', 'required', 'service_ids', 'purposes']),
            array_filter($configuration['forms']['fields'] ?? [], fn ($field) => $field['enabled'] && $field['public'])));
    }

    public function applies(array $field, string $service, string $purpose, bool $general): bool
    {
        return ! $general && $field['enabled'] && in_array($purpose, $field['purposes'], true)
            && ($field['service_ids'] === [] || in_array($service, $field['service_ids'], true));
    }

    public function intakeAnswers(array $configuration, array $form, int $versionId, ?User $actor = null): array
    {
        $general = $form['authority_status'] === 'unsure' || $form['purpose'] === 'business';
        $allowed = array_filter($configuration['forms']['fields'] ?? [], fn ($field) => $field['public'] && $this->applies($field, $form['requested_service'], $form['purpose'], $general));
        $submitted = $form['answers'] ?? [];
        $known = array_column($allowed, null, 'id');
        if (array_diff(array_keys($submitted), array_keys($known))) {
            throw ValidationException::withMessages(['answers' => 'Remove answers to questions that are not available for this enquiry.']);
        }
        $answers = [];
        foreach ($allowed as $field) {
            $value = $submitted[$field['id']] ?? null;
            // Recording staff need the same audience access as subsequent readers.
            if ($actor && ($value !== null || $field['required'] === 'intake')) {
                abort_unless(app(ReferralAccess::class)->audience($actor, $field['audience'], true), 403, 'A colleague with the required form audience access must record this intake.');
            }
            $value = $this->value($field, $value, $field['required'] === 'intake');
            if ($value !== null) {
                $answers[$field['id']] = ['definition' => $field, 'value' => $value, 'settings_version_id' => $versionId];
            }
        }

        return $answers;
    }

    private function value(array $field, mixed $value, bool $required): mixed
    {
        if ($value === '') {
            $value = null;
        }
        $key = 'answers.'.$field['id'];
        $rules = [$required ? 'required' : 'nullable'];
        $rules = [...$rules, ...match ($field['type']) {
            'text' => ['string', 'max:2000'],
            'date' => ['date_format:Y-m-d'],
            'number' => ['numeric', 'between:-1000000000,1000000000'],
            'choice' => ['string', Rule::in($field['options'])],
            'checkbox' => ['boolean'],
        }];
        Validator::make(['answers' => [$field['id'] => $value]], [$key => $rules], [], [$key => $field['label']])->validate();
        if ($value === null) {
            return null;
        }

        return match ($field['type']) {
            'checkbox' => (bool) $value, 'number' => (float) $value, default => $value,
        };
    }

    public function fields(User $actor, CareReferral $referral): array
    {
        $access = app(ReferralAccess::class);
        $configuration = ReferralSettingsVersion::findOrFail($referral->settings_version_id)->configuration;
        $answers = $referral->intake['form_answers'] ?? [];
        $fields = [];
        foreach ($configuration['forms']['fields'] ?? [] as $field) {
            if (! $access->audience($actor, $field['audience']) || ! $this->applies($field, $referral->requested_service, $this->purpose($referral), $referral->general_enquiry)) {
                continue;
            }
            $fields[] = [...$field, 'value' => $answers[$field['id']]['value'] ?? null,
                'editable' => $access->can($actor, 'edit') && $access->audience($actor, $field['audience'], true) && $referral->stage !== 'closed'
                    && ReferralAssessment::scope($referral, 'collect')];
        }

        return $fields;
    }

    public function history(User $actor, CareReferral $referral): array
    {
        // Original definitions accompany the original answers, including archived or conditionally hidden questions.
        return array_values(array_filter($referral->intake['form_answers'] ?? [], fn ($answer) => app(ReferralAccess::class)->audience($actor, $answer['definition']['audience'])));
    }

    public function missing(CareReferral $referral): int
    {
        $configuration = ReferralSettingsVersion::findOrFail($referral->settings_version_id)->configuration;
        $answers = $referral->intake['form_answers'] ?? [];
        $count = 0;
        foreach ($configuration['forms']['fields'] ?? [] as $field) {
            if (! $this->applies($field, $referral->requested_service, $this->purpose($referral), $referral->general_enquiry) || $field['required'] === 'optional') {
                continue;
            }
            try {
                $this->value($field, $answers[$field['id']]['value'] ?? null, true);
            } catch (ValidationException) {
                $count++;
            }
        }

        return $count;
    }

    public function save(User $actor, int $id, int $version, array $input): void
    {
        $data = Validator::make($input, ['answers' => 'required|array|min:1|max:40', 'reason' => 'required|string|min:5|max:2000'])->validate();
        $command = app(ReferralCommand::class);
        $command->run($actor, $id, $version, 'edit', function (CareReferral $referral, User $actor) use ($data, $command): void {
            abort_if($referral->stage === 'closed' || $referral->general_enquiry || ! ReferralAssessment::scope($referral, 'collect'), 422, 'Review collection authority and the open referral before recording these answers.');
            $fields = array_column($this->fields($actor, $referral), null, 'id');
            $answers = $referral->intake['form_answers'] ?? [];
            $events = [];
            foreach ($data['answers'] as $id => $value) {
                $field = $fields[$id] ?? null;
                abort_unless($field && $field['editable'], 403, 'This form question is not available for editing.');
                $value = $this->value($field, $value, false);
                $definition = Arr::except($field, ['value', 'editable']);
                $events[$field['audience']][] = ['field_id' => $id, 'previous' => $answers[$id] ?? null];
                $answers[$id] = ['definition' => $definition, 'value' => $value, 'settings_version_id' => $referral->settings_version_id];
            }
            abort_if(count($events) > 1, 422, 'Update one information audience at a time so the reason stays with the right readers.');
            $referral->intake = [...$referral->intake, 'form_answers' => $answers];
            foreach ($events as $audience => $changes) {
                $command->record($referral, $actor, 'form.answers_updated', $audience, ['reason' => $data['reason'], 'changes' => $changes]);
            }
            app(ReferralAssessment::class)->invalidateReadiness($referral, $actor, 'form');
        });
    }

    private function purpose(CareReferral $referral): string
    {
        // Once an enquiry is lawfully completed into a referral, its original
        // business/legacy/internal source cannot bypass support preparation questions.
        return in_array($referral->entry_kind, ['support', 'referral', 'funding', 'callback'], true) ? $referral->entry_kind : 'support';
    }
}
