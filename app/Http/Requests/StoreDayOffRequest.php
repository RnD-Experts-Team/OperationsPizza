<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDayOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'type' => is_string($this->type) ? strtolower($this->type) : $this->type,
        ]);
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:employees,id'],

            'date' => [
                'required',
                'date',
                Rule::unique('days_off')
                    ->where(function ($query) {
                        return $query->where('employee_id', $this->employee_id);
                    }),
            ],

            'type' => [
                'required',
                Rule::in(['sick day', 'unavailable', 'pto', 'vto'])
            ],

            'note' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.required' => 'Employee id is required.',
            'employee_id.exists' => 'Selected employee does not exist.',
            'date.required' => 'Date is required.',
            'date.date' => 'Date must be a valid date.',
            'date.unique' => 'This employee already has a day off request on this date.',
            'type.required' => 'Type is required.',
            'type.in' => 'Type is invalid.',
            'note.required' => 'Note is required.',
        ];
    }
}