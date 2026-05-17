<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Auth middleware handles authorization
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => 'required_without:force_change|string',
            'new_password'     => 'required|string|min:8|confirmed',
            'force_change'     => 'boolean',
        ];
    }

    /**
     * Custom error messages.
     */
    public function messages(): array
    {
        return [
            'current_password.required_without' => 'Current password is required.',
            'new_password.min'                  => 'New password must be at least 8 characters.',
            'new_password.confirmed'            => 'Password confirmation does not match.',
        ];
    }
}
