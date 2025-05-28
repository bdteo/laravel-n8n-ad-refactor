<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessAdScriptResultRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'new_script' => [
                'nullable',
                'string',
                'max:50000',
            ],
            'analysis' => [
                'nullable',
                'array',
            ],
            'analysis.*' => [
                'string',
            ],
            'error' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'new_script.string' => 'The new script must be a string.',
            'new_script.max' => 'The new script may not be greater than 50,000 characters.',
            'analysis.array' => 'The analysis must be an array.',
            'analysis.*.string' => 'Each analysis item must be a string.',
            'error.string' => 'The error must be a string.',
            'error.max' => 'The error may not be greater than 5,000 characters.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $data = $this->validated();

            // At least one of new_script or error must be present
            if (empty($data['new_script']) && empty($data['error'])) {
                $validator->errors()->add('payload', 'Either new_script or error must be provided.');
            }

            // new_script and error cannot both be present
            if (! empty($data['new_script']) && ! empty($data['error'])) {
                $validator->errors()->add('payload', 'Cannot provide both new_script and error in the same request.');
            }
        });
    }
}
