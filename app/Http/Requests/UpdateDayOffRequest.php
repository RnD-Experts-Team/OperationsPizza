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
        $managerNote = $this->managerNote;

        if (is_string($managerNote)) {
            $managerNote = trim($managerNote);
            $managerNote = preg_replace('/\s+/', ' ', $managerNote);
        }

        $this->merge([
            'acceptedStatus' => is_string($this->acceptedStatus)
                ? strtolower($this->acceptedStatus)
                : $this->acceptedStatus,

            'managerNote' => $managerNote,
        ]);
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date'],
            'managerNote' => ['required', 'string'],
            'acceptedStatus' => [
                'required',
                Rule::in(['pending', 'approved', 'rejected'])
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'date.date' => 'Date must be a valid date.',
            'managerNote.required' => 'Manager note is required.',
            'managerNote.string' => 'Manager note must be a string.',
            'acceptedStatus.required' => 'Accepted status is required.',
            'acceptedStatus.in' => 'Accepted status is invalid.',
        ];
    }
}