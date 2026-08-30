<?php

namespace App\Http\Requests\Tasks;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `GET /api/tasks/calendar` — Yetkilendirme TaskController::calendar()
 * içinde Policy ile yapılır (aynı `tasks.view`, ayrı bir "calendar.view"
 * izni YOK — takvim, liste görünümünün başka bir sunumu).
 */
class CalendarTaskRequest extends FormRequest
{
    /**
     * Sınırsız bir aralık tüm tabloyu çeker — bu üst sınır o riski keser.
     */
    public const MAX_RANGE_DAYS = 90;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'filter' => ['sometimes', 'array'],
            'filter.assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'filter.status' => ['sometimes', 'nullable', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
            'filter.priority' => ['sometimes', 'nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ];
    }

    /**
     * `to`, `from`'dan önce olamaz ve aralık 90 günü aşamaz. İkisi de
     * date-format doğrulamasından SONRA (validator->after) kontrol edilir —
     * yoksa Carbon::parse() geçersiz bir string ile çağrılabilir.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('from') || $validator->errors()->has('to')) {
                return;
            }

            $from = Carbon::parse((string) $this->input('from'))->startOfDay();
            $to = Carbon::parse((string) $this->input('to'))->startOfDay();

            if ($to->lt($from)) {
                $validator->errors()->add('to', "'to' tarihi 'from' tarihinden önce olamaz.");

                return;
            }

            if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('to', 'Takvim aralığı en fazla '.self::MAX_RANGE_DAYS.' gün olabilir.');
            }
        });
    }

    /**
     * Repository/Service katmanının beklediği düz filtre dizisini üretir.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $filter = $validated['filter'] ?? [];

        return [
            'from' => $validated['from'],
            'to' => $validated['to'],
            'assigned_to' => $filter['assigned_to'] ?? null,
            'status' => $filter['status'] ?? null,
            'priority' => $filter['priority'] ?? null,
        ];
    }
}
