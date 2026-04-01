<?php

namespace App\Http\Requests;

use App\Models\Availability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAvailabilityRequest extends FormRequest
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
        $id = $this->route('availability') ?? $this->route('id');

        $availability = Availability::find($id);

        $employeeId = $this->employee_id ?? $availability?->employee_id;

        return [
            'employee_id' => ['sometimes', 'exists:employees,id'],
            'day_of_week' => [
                'sometimes',
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
                    ->where(function ($query) use ($employeeId) {
                        return $query->where('employee_id', $employeeId);
                    })
                    ->ignore($id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.exists' => 'Selected employee does not exist.',
            'day_of_week.in' => 'Day of week is invalid.',
            'day_of_week.unique' => 'This day already exists for this employee.',
        ];
    }
}