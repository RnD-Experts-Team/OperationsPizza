<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDayOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'acceptedStatus' => is_string($this->acceptedStatus)
                ? strtolower($this->acceptedStatus)
                : $this->acceptedStatus,
        ]);
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date'],

            'acceptedStatus' => [
                'sometimes',
                Rule::in(['pending', 'approved', 'rejected'])
            ],
        ];
    }
}