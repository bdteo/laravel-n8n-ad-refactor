<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdScriptTaskRequest extends FormRequest
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
            'reference_script' => [
                'required',
                'string',
                'min:10',
                'max:10000',
            ],
            'outcome_description' => [
                'required',
                'string',
                'min:5',
                'max:1000',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'reference_script.required' => 'The reference script is required.',
            'reference_script.min' => 'The reference script must be at least 10 characters.',
            'reference_script.max' => 'The reference script may not be greater than 10,000 characters.',
            'outcome_description.required' => 'The outcome description is required.',
            'outcome_description.min' => 'The outcome description must be at least 5 characters.',
            'outcome_description.max' => 'The outcome description may not be greater than 1,000 characters.',
        ];
    }
}
