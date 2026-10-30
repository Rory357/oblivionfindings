import { formatDateOnly } from '@/lib/datetime';

export type Answer = string | number | boolean | null;
export type Answers = Record<string, Answer>;
export type FormQuestion = {
    id: string;
    label: string;
    help: string | null;
    type: 'text' | 'date' | 'number' | 'choice' | 'checkbox';
    options: string[];
    required: 'optional' | 'intake' | 'handover';
    service_ids: string[];
    purposes: string[];
};
export type ConfiguredQuestion = FormQuestion & {
    enabled: boolean;
    public: boolean;
    audience: 'intake' | 'assessment' | 'funding';
    destination: string;
};
export type RecordQuestion = ConfiguredQuestion & {
    value: Answer;
    editable: boolean;
};
export type RecordedAnswer = {
    definition: ConfiguredQuestion;
    value: Answer;
    settings_version_id: number;
};

export function applicableQuestions(
    fields: FormQuestion[],
    service: string,
    purpose: string,
    minimal: boolean,
) {
    return minimal
        ? []
        : fields.filter(
              (field) =>
                  field.purposes.includes(purpose) &&
                  (!field.service_ids.length ||
                      field.service_ids.includes(service)),
          );
}
export function applicableAnswers(
    fields: FormQuestion[],
    answers: Answers,
): Answers {
    return Object.fromEntries(
        fields
            .filter((field) => Object.hasOwn(answers, field.id))
            .map((field) => [field.id, answers[field.id]]),
    );
}
export function answerText(
    value: Answer | undefined,
    type?: FormQuestion['type'],
): string {
    if (
        type === 'date' &&
        typeof value === 'string' &&
        /^\d{4}-\d{2}-\d{2}$/.test(value)
    )
        return formatDateOnly(value);
    return value === null || value === undefined || value === ''
        ? 'Not recorded'
        : typeof value === 'boolean'
          ? value
              ? 'Yes'
              : 'No'
          : String(value);
}

export function requiredQuestionErrors(
    fields: FormQuestion[],
    answers: Answers,
): Record<string, string> {
    return Object.fromEntries(
        fields
            .filter(
                (field) =>
                    field.required === 'intake' &&
                    (answers[field.id] === null ||
                        answers[field.id] === undefined ||
                        String(answers[field.id]).trim() === ''),
            )
            .map((field) => [
                `answers.${field.id}`,
                `Answer ${field.label.toLowerCase()} before continuing.`,
            ]),
    );
}
