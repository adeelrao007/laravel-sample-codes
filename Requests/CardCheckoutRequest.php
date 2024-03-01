<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CardCheckoutRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @param CheckoutRequest $checkoutRequest
     * @return array
     */
    public function rules(CheckoutRequest $checkoutRequest): array
    {
        return array_merge($checkoutRequest->rules(), [
            'order.payment_token' => ['sometimes', 'string', 'nullable'],
            'payment_method.card_holder_name' => ['sometimes', 'string', 'min:3', 'max:255', 'nullable'],
            'payment_method.card_last_digits' => ['sometimes', 'string', 'min:4', 'max:4', 'nullable'],
            'payment_method.card_expiry_date' => ['sometimes', 'date_format:m/y', 'nullable'],
            'payment_method.card_brand' => ['sometimes', 'string', 'min:2', 'max:64', 'nullable'],
            'payment_method.is_default' => ['required', 'boolean'],
        ]);
    }
}
