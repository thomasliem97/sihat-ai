<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTriageMessageRequest extends FormRequest
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
            'text' => ['nullable', 'string', 'max:5000'],
            'audio' => ['nullable', 'file', 'max:10240', 'mimetypes:audio/webm,audio/wav,audio/mpeg,audio/mp4,video/webm'],
        ];
    }
}
