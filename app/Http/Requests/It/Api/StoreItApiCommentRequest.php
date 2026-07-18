<?php

namespace App\Http\Requests\It\Api;

use App\Models\ItServiceIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreItApiCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('it_service_identity') instanceof ItServiceIdentity;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:5000']];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['body']) as $field) {
                $validator->errors()->add($field, 'This field is not available for public API comments.');
            }
        }];
    }
}
