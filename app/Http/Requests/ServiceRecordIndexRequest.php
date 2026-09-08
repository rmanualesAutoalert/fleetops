<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;

class ServiceRecordIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'min:1'],
            'cursor' => [
                'nullable',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        if (Cursor::fromEncoded((string) $value) === null) {
                            $fail("The {$attribute} is invalid.");
                        }
                    } catch (\Throwable) {
                        $fail("The {$attribute} is invalid.");
                    }
                },
            ],
        ];
    }
}
