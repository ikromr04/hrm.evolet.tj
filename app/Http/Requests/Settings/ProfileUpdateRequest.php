<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use App\Rules\LatinEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The name is what HR files on a colleague's card, not something
            // they set here; this page settles the address they sign in with.
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                new LatinEmail,
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }
}
