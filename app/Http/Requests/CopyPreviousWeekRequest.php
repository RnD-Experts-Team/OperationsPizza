<?php

namespace App\Http\Requests;

use App\Models\MasterSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class CopyPreviousWeekRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'store_id' => $this->route('store'),
        ]);
    }

    public function rules(): array
    {
        return [
            'store_id' => ['required', 'exists:stores,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
            'master_schedule_id' => ['nullable', 'exists:master_schedule,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {

            $storeId = $this->input('store_id');
            $start = Carbon::parse($this->start_date);
            $end = Carbon::parse($this->end_date);

            if ($end->lt($start)) {
                $validator->errors()->add('end_date', 'end_date must be after start_date.');
            }

            if ($start->format('l') !== 'Tuesday') {
                $validator->errors()->add('start_date', 'start_date must be Tuesday.');
            }

            if ($end->format('l') !== 'Monday') {
                $validator->errors()->add('end_date', 'end_date must be Monday.');
            }

            if (!$start->copy()->addDays(6)->isSameDay($end)) {
                $validator->errors()->add('end_date', 'Must be exactly 7 days.');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $exists = MasterSchedule::where('store_id', $storeId)
                ->whereDate('start_date', $start)
                ->whereDate('end_date', $end)
                ->exists();

            if ($exists) {
                $validator->errors()->add('start_date', 'This schedule already exists.');
            }

            $latest = MasterSchedule::where('store_id', $storeId)
                ->orderByDesc('end_date')
                ->first();

            if ($latest) {

                $expectedStart = Carbon::parse($latest->start_date)->addWeek();
                $expectedEnd = Carbon::parse($latest->end_date)->addWeek();

                if (!$start->isSameDay($expectedStart) || !$end->isSameDay($expectedEnd)) {
                    $validator->errors()->add(
                        'start_date',
                        'Only next week is allowed.'
                    );
                }
            }
        });
    }
}