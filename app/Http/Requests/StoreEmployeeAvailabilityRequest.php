<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Availability;
use App\Models\AvailabilityTime;

class StoreEmployeeAvailabilityRequest extends FormRequest
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
            // Availability validation
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

            // Times validation
            'times' => ['required', 'array', 'min:1'],

            'times.*.from' => ['required', 'date_format:H:i'],
            'times.*.to'   => ['required', 'date_format:H:i'],
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

            'times.required' => 'Times are required.',
            'times.array' => 'Times must be an array.',
            'times.*.from.required' => 'From time is required.',
            'times.*.to.required' => 'To time is required.',
            'times.*.from.date_format' => 'From must be in H:i format.',
            'times.*.to.date_format' => 'To must be in H:i format.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {

            if (!$this->times || !is_array($this->times)) {
                return;
            }

            // =========================
            // 1. تحقق كل عنصر (from < to + DB overlap)
            // =========================
            foreach ($this->times as $index => $time) {

                $from = $time['from'] ?? null;
                $to   = $time['to'] ?? null;

                if (!$from || !$to) {
                    continue;
                }

                // from < to
                if ($from >= $to) {
                    $validator->errors()->add(
                        "times.$index.from",
                        'From must be less than To'
                    );
                    continue;
                }

                if (!$this->employee_id || !$this->day_of_week) {
                    continue;
                }

                // نفس logic القديم (DB overlap)
                $availabilities = Availability::where('employee_id', $this->employee_id)
                    ->where('day_of_week', $this->day_of_week)
                    ->pluck('id');

                $overlap = AvailabilityTime::whereIn('availability_id', $availabilities)
                    ->where(function ($query) use ($from, $to) {
                        $query->whereBetween('from', [$from, $to])
                            ->orWhereBetween('to', [$from, $to])
                            ->orWhere(function ($q) use ($from, $to) {
                                $q->where('from', '<=', $from)
                                  ->where('to', '>=', $to);
                            });
                    })
                    ->exists();

                if ($overlap) {
                    $validator->errors()->add(
                        "times.$index.from",
                        'This time overlaps with an existing time.'
                    );
                }
            }

            // =========================
            // 2. تحقق التداخل داخل نفس request
            // =========================
            $count = count($this->times);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {

                    $a = $this->times[$i];
                    $b = $this->times[$j];

                    if (
                        !isset($a['from'], $a['to']) ||
                        !isset($b['from'], $b['to'])
                    ) {
                        continue;
                    }

                    $aFrom = $a['from'];
                    $aTo   = $a['to'];
                    $bFrom = $b['from'];
                    $bTo   = $b['to'];

                    $overlap =
                        ($aFrom >= $bFrom && $aFrom < $bTo) ||
                        ($aTo > $bFrom && $aTo <= $bTo) ||
                        ($aFrom <= $bFrom && $aTo >= $bTo);

                    if ($overlap) {
                        $validator->errors()->add(
                            "times.$i.from",
                            'Times overlap with each other.'
                        );

                        $validator->errors()->add(
                            "times.$j.from",
                            'Times overlap with each other.'
                        );
                    }
                }
            }
        });
    }
}