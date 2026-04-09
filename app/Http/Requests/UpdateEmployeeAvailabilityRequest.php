<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Availability;
use App\Models\AvailabilityTime;

class UpdateEmployeeAvailabilityRequest extends FormRequest
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
        $id = $this->route('employee_availability');
        $storeId = $this->route('store');

        $availability = Availability::where('id', $id)
            ->whereHas('employee', function ($query) use ($storeId) {
                $query->where('store_id', $storeId);
            })
            ->first();

        $employeeId = $this->employee_id ?? $availability?->employee_id;

        return [
            'employee_id' => [
                'sometimes',
                Rule::exists('employees', 'id')->where(function ($query) use ($storeId) {
                    $query->where('store_id', $storeId);
                }),
            ],

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

            'times' => ['required', 'array', 'min:1'],
            'times.*.from' => ['required', 'date_format:H:i'],
            'times.*.to'   => ['required', 'date_format:H:i'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.exists' => 'Selected employee does not belong to this store or does not exist.',
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

            $id = $this->route('employee_availability');
            $storeId = $this->route('store');

            $availability = Availability::where('id', $id)
                ->whereHas('employee', function ($query) use ($storeId) {
                    $query->where('store_id', $storeId);
                })
                ->first();

            $employeeId = $this->employee_id ?? $availability?->employee_id;
            $dayOfWeek  = $this->day_of_week ?? $availability?->day_of_week;

            foreach ($this->times as $index => $time) {
                $from = $time['from'] ?? null;
                $to   = $time['to'] ?? null;

                if (!$from || !$to) {
                    continue;
                }

                if ($from >= $to) {
                    $validator->errors()->add(
                        "times.$index.from",
                        'From must be less than To'
                    );
                    continue;
                }

                if (!$employeeId || !$dayOfWeek) {
                    continue;
                }

                $availabilities = Availability::where('employee_id', $employeeId)
                    ->where('day_of_week', $dayOfWeek)
                    ->where('id', '!=', $id)
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