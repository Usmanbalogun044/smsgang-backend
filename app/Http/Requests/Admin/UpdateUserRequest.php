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
        $routeUser = $this->route('user');
        $userId = is_object($routeUser) ? $routeUser->id : $routeUser;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'role' => ['sometimes', Rule::in(array_column(UserRole::cases(), 'value'))],
            'status' => ['sometimes', Rule::in(array_column(UserStatus::cases(), 'value'))],
            'vendor_virtual_markup_type' => ['nullable', 'sometimes', Rule::in(['fixed', 'percent'])],
            'vendor_virtual_markup_value' => ['nullable', 'sometimes', 'numeric', 'min:0'],
            'vendor_smm_markup_type' => ['nullable', 'sometimes', Rule::in(['fixed', 'percent'])],
            'vendor_smm_markup_value' => ['nullable', 'sometimes', 'numeric', 'min:0'],
            'wallet_balance' => ['sometimes', 'numeric', 'min:0'],
            'is_email_verified' => ['sometimes', 'boolean'],
        ];
    }
}
