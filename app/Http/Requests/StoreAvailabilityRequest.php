<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'day_of_week' => is_string($this->day_of_week)
                ? strtolower($this->day_of_week)
                : $this->day_of_week,
        ]);
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:employees,id'],
            'day_of_week' => [
                'required',
                'string',
                Rule::in([
                    'monday',
                    'tuesday',
                    'wednesday',
                    'thursday',
                    'friday',
                    'saturday',
                    'sunday',
                ]),
                Rule::unique('availabilities')
                    ->where(function ($query) {
                        return $query->where('employee_id', $this->employee_id);
                    }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.required' => 'Employee id is required.',
            'employee_id.exists' => 'Selected employee does not exist.',
            'day_of_week.required' => 'Day of week is required.',
            'day_of_week.in' => 'Day of week is invalid.',
            'day_of_week.unique' => 'This day already exists for this employee.',
        ];
    }
}