<?php

namespace App\Http\Requests;

use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMasterScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    protected function prepareForValidation(): void
    {
        $storeParam = $this->route('store');

        $storeId = $storeParam instanceof Store
            ? $storeParam->id
            : Store::where('store', $storeParam)->value('id');

        $this->merge([
            'store_id' => $storeId,
        ]);
    }

    public function rules(): array
    {
        return [
            'store_id' => ['required', 'exists:stores,id'],

            'start_date' => [
                'required',
                'date',
                function ($attribute, $value, $fail) {
                    if (date('l', strtotime($value)) !== 'Tuesday') {
                        $fail('start_date must be a Tuesday.');
                    }
                },
            ],

            'end_date' => [
                'required',
                'date',
                function ($attribute, $value, $fail) {
                    if (date('l', strtotime($value)) !== 'Monday') {
                        $fail('end_date must be a Monday.');
                    }
                },
            ],

            'schedules' => ['required', 'array', 'min:1'],

            'schedules.*.employee_id' => [
                'nullable',
                Rule::exists('employees', 'id')->where(function ($query) {
                    $query->where('store_id', $this->input('store_id'));
                }),
            ],
            'schedules.*.date' => ['required', 'date'],
            'schedules.*.start_time' => ['required', 'date_format:H:i'],
            'schedules.*.end_time' => ['required', 'date_format:H:i'],
            'schedules.*.skill_id' => ['required', 'exists:skills,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $startDate = $this->input('start_date');
            $endDate = $this->input('end_date');
            $schedules = $this->input('schedules', []);

            if ($startDate && $endDate) {
                $start = Carbon::parse($startDate)->startOfDay();
                $end = Carbon::parse($endDate)->startOfDay();

                if ($start->gte($end)) {
                    $validator->errors()->add('start_date', 'start_date must be before end_date.');
                }

                if (!$start->copy()->addDays(6)->isSameDay($end)) {
                    $validator->errors()->add(
                        'end_date',
                        'The date range must be exactly 7 days (Tuesday to Monday).'
                    );
                }
            }

            foreach ($schedules as $index => $schedule) {
                if (isset($schedule['start_time'], $schedule['end_time'])) {
                    $startTime = Carbon::createFromFormat('H:i', $schedule['start_time']);
                    $endTime = Carbon::createFromFormat('H:i', $schedule['end_time']);

                    if ($endTime->lte($startTime)) {
                        $validator->errors()->add(
                            "schedules.$index.end_time",
                            'end_time must be after start_time.'
                        );
                    }
                }

                if (isset($schedule['date']) && $startDate && $endDate) {
                    $scheduleDate = Carbon::parse($schedule['date'])->startOfDay();
                    $start = Carbon::parse($startDate)->startOfDay();
                    $end = Carbon::parse($endDate)->startOfDay();

                    if ($scheduleDate->lt($start) || $scheduleDate->gt($end)) {
                        $validator->errors()->add(
                            "schedules.$index.date",
                            'schedule date must be within the master schedule range.'
                        );
                    }
                }
            }
        });
    }
}