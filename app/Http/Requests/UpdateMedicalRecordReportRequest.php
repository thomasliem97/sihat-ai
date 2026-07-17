<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMedicalRecordReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'summary' => ['required', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'array'],
            'recommendations.*' => ['string', 'max:1000'],
            'technical_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
