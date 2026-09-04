<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentBoardRequest extends FormRequest
{
    public const STATUSES = ['booked', 'checked_in', 'in_service', 'completed', 'cancelled'];

    public function authorize(): bool
    {
        // Both routes enforce authentication and the service-advisor role.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
