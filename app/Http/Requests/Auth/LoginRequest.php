<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
            // Which screen the credentials were typed on. Only ever
            // narrows what is allowed: see `refuseAtTheKiosk()`.
            'from' => ['nullable', 'string', 'in:kiosk'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'username' => 'username',
        ];
    }

    /**
     * Attempt to log the user in using the username credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            'username' => $this->string('username')->toString(),
            'password' => $this->string('password')->toString(),
            'is_active' => true,
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'username' => __('auth.failed'),
            ]);
        }

        $this->refuseAtTheKiosk();

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Not every account may sign in from the shared tablet.
     *
     * The kiosk posts to this same route, which is what keeps there being
     * one way into the system rather than two. The screen it came from is
     * carried as a plain field, and that field can only ever take
     * something away: a request without it is the ordinary sign-in and is
     * unaffected.
     *
     * This is containment, not a wall. The same person with the same
     * password can still use the ordinary screen, here or anywhere else.
     * What it stops is an administrator signing in on a tablet that is
     * left on a bench, which is the thing worth stopping.
     *
     * The check runs after the password has been accepted, so a wrong
     * password is still answered with the same message as always and this
     * never becomes a way to find out which accounts exist.
     *
     * @throws ValidationException
     */
    protected function refuseAtTheKiosk(): void
    {
        if ($this->string('from')->toString() !== 'kiosk') {
            return;
        }

        if (Auth::user()?->can('kiosk.use')) {
            return;
        }

        // Signed in a moment ago and signed straight back out: the
        // credentials were right, the screen was wrong.
        Auth::guard('web')->logout();

        throw ValidationException::withMessages([
            'username' => 'This account cannot sign in from the kiosk. '
                .'Use the ordinary sign-in screen instead.',
        ]);
    }

    /**
     * Stop too many login attempts from the same username and IP address.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            event(new Lockout($this));

            $seconds = RateLimiter::availableIn($this->throttleKey());

            throw ValidationException::withMessages([
                'username' => __('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }
    }

    /**
     * The rate limiting key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('username')->toString()).'|'.$this->ip());
    }
}
