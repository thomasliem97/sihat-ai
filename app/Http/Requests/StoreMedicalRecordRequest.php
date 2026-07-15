<?php

namespace App\Http\Requests;

use App\Enums\Modality;
use App\Enums\ReportLanguage;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedicalRecordRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'modality' => ['nullable', Rule::enum(Modality::class)],
            'language' => ['nullable', Rule::enum(ReportLanguage::class)],
            'patient_id' => [
                'nullable',
                Rule::prohibitedIf(fn () => $this->user()?->isPatient() === true),
                Rule::exists('users', 'id')->where('role', UserRole::Patient->value),
            ],
            'subject' => [
                Rule::requiredIf(fn () => $this->user()?->isPatient() === true),
                'nullable',
                Rule::in(['self', 'other']),
            ],
            'file' => ['required', 'file', 'max:51200', 'mimes:jpg,jpeg,png,pdf,dcm,zip'],
        ];
    }
}
