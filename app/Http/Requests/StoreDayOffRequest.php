<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\DayOff;

class StoreDayOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->requests || !is_array($this->requests)) {
            return;
        }

        $cleaned = [];

        foreach ($this->requests as $item) {

            // normalize type
            $type = isset($item['type']) && is_string($item['type'])
                ? strtolower($item['type'])
                : $item['type'] ?? null;

            // clean note
            $note = $item['note'] ?? null;

            if (is_string($note)) {
                $note = trim($note);
                $note = preg_replace('/\s+/', ' ', $note);
            }

            $cleaned[] = [
                'employee_id' => $item['employee_id'] ?? null,
                'date' => $item['date'] ?? null,
                'type' => $type,
                'note' => $note,
            ];
        }

        $this->merge([
            'requests' => $cleaned
        ]);
    }

    public function rules(): array
    {
        return [
            'requests' => ['required', 'array', 'min:1'],

            'requests.*.employee_id' => ['required', 'exists:employees,id'],

            'requests.*.date' => [
                'required',
                'date',
                function ($attribute, $value, $fail) {

                    preg_match('/requests\.(\d+)\./', $attribute, $matches);
                    $index = $matches[1] ?? null;

                    $employeeId = $this->requests[$index]['employee_id'] ?? null;

                    $exists = DayOff::where('employee_id', $employeeId)
                        ->where('date', $value)
                        ->exists();

                    if ($exists) {
                        $fail('This employee already has a day off request on this date.');
                    }
                }
            ],

            'requests.*.type' => [
                'required',
                Rule::in(['sick day', 'unavailable', 'pto', 'vto'])
            ],

            'requests.*.note' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'requests.required' => 'Requests are required.',
            'requests.array' => 'Requests must be an array.',

            'requests.*.employee_id.required' => 'Employee id is required.',
            'requests.*.employee_id.exists' => 'Selected employee does not exist.',

            'requests.*.date.required' => 'Date is required.',
            'requests.*.date.date' => 'Date must be a valid date.',

            'requests.*.type.required' => 'Type is required.',
            'requests.*.type.in' => 'Type is invalid.',

            'requests.*.note.required' => 'Note is required.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {

            if (!$this->requests || !is_array($this->requests)) {
                return;
            }

            $seen = [];

            foreach ($this->requests as $index => $item) {

                $employeeId = $item['employee_id'] ?? null;
                $date = $item['date'] ?? null;

                if (!$employeeId || !$date) {
                    continue;
                }

                $key = $employeeId . '_' . $date;

                // ✅ check duplicate inside same request
                if (isset($seen[$key])) {
                    $validator->errors()->add(
                        "requests.$index.date",
                        'Duplicate day off for the same employee in request.'
                    );
                } else {
                    $seen[$key] = true;
                }
            }
        });
    }
}