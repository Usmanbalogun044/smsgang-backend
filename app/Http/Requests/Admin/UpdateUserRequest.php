<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::in(array_column(UserRole::cases(), 'value'))],
            'status' => ['sometimes', Rule::in(array_column(UserStatus::cases(), 'value'))],
        ];
    }
}
