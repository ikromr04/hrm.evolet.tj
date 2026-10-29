<?php

namespace App\Http\Requests\Auth;

use App\Http\Middleware\EnsureUserIsActive;
use App\Support\GuessLimit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        // Five a minute by address and by where the request came from, as it has
        // always been; the counting itself now lives where the other forms that
        // take a guess at something can do the same.
        $limit = GuessLimit::on('login', $this);

        $limit->ensure();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            $limit->hit();

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // People who were transferred or fired keep their record but cannot sign in.
        if (! Auth::user()->isActive()) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => EnsureUserIsActive::MESSAGE,
            ]);
        }

        $limit->clear();
    }
}
