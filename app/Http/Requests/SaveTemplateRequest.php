<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $details = $this->input('details', []);

        $cleaned = array_map(function ($detail) {

            if (isset($detail['day_of_week'])) {
                $detail['day_of_week'] = strtolower(
                    preg_replace('/\s+/', '', trim($detail['day_of_week']))
                );
            }

            return $detail;

        }, $details);

        $this->merge(['details' => $cleaned]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],

            // 🔥 واحد من الاثنين
            'master_schedule_id' => ['nullable', 'exists:master_schedule,id'],

            'details' => ['required_without:master_schedule_id', 'array'],

            'details.*.day_of_week' => [
                'required_without:master_schedule_id',
                'in:sunday,monday,tuesday,wednesday,thursday,friday,saturday'
            ],

            'details.*.start_time' => ['required_without:master_schedule_id', 'date_format:H:i'],
            'details.*.end_time' => ['required_without:master_schedule_id', 'date_format:H:i'],
            'details.*.skill_id' => ['required_without:master_schedule_id', 'exists:skills,id'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {

            foreach ($this->details ?? [] as $i => $detail) {

                if (
                    isset($detail['start_time'], $detail['end_time']) &&
                    $detail['end_time'] <= $detail['start_time']
                ) {
                    $validator->errors()->add(
                        "details.$i.end_time",
                        'end_time must be after start_time'
                    );
                }
            }
        });
    }
}