<?php

namespace App\Http\Requests;

use App\Models\ServicePrice;
use Illuminate\Foundation\Http\FormRequest;

class BuyActivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'operator' => ['required', 'string', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->any()) {
                return;
            }

            $price = ServicePrice::where('service_id', $this->service_id)
                ->where('country_id', $this->country_id)
                ->where('is_active', true)
                ->first();

            if (! $price) {
                $validator->errors()->add('service_id', 'No active pricing found for this service and country combination.');
                return;
            }

            $operators = $price->provider_payload;
            if (! is_array($operators) || empty($operators)) {
                $validator->errors()->add('operator', 'No operators are available for this service and country right now.');
                return;
            }

            $chosenOperator = (string) $this->input('operator');
            $operatorInfo = $operators[$chosenOperator] ?? null;
            if (! is_array($operatorInfo)) {
                $validator->errors()->add('operator', 'Selected operator is not available for this service and country.');
                return;
            }

            $count = (int) ($operatorInfo['count'] ?? 0);
            $cost = (float) ($operatorInfo['cost'] ?? 0);

            if ($count <= 0 || $cost <= 0) {
                $validator->errors()->add('operator', 'Selected operator is currently out of stock.');
            }
        });
    }
}
