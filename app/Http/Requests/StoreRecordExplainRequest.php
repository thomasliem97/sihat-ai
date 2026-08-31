<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRecordExplainRequest extends FormRequest
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
            'question' => ['required', 'string', 'max:2000'],
            'finding_index' => ['nullable', 'integer', 'min:0'],
            'selected_box' => ['nullable', 'array'],
            'selected_box.label' => ['nullable', 'string', 'max:120'],
            'selected_box.x' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'selected_box.y' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'selected_box.width' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'selected_box.height' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'selected_box.kind' => ['nullable', 'string', 'in:finding,anatomy'],
            'selected_box.image_index' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
