<?php

namespace App\Http\Requests\Admin;

use App\Enums\MarkupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'markup_type' => ['required', Rule::in(array_column(MarkupType::cases(), 'value'))],
            'markup_value' => ['required', 'numeric', 'min:0'],
        ];
    }
}
