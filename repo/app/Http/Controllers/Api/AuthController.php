<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Cookie;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username'      => 'required|string|max:64',
            'password'      => 'required|string',
            'captcha_token' => 'nullable|string',
        ]);

        $result = $this->authService->attempt(
            $data['username'],
            $data['password'],
            $request->ip(),
            $data['captcha_token'] ?? null,
        );

        return response()->json([
            'token'                    => $result['token'],
            'user'                     => $result['user'],
            'captcha_required'         => $result['captcha_required'],
            'requires_password_change' => $result['requires_password_change'],
        ])->withCookie($this->sessionCookie($result['token'], $request->isSecure()));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());
        return response()->json(['message' => 'Logged out successfully.'])
            ->withoutCookie('vetops_session');
    }

    public function refresh(Request $request): JsonResponse
    {
        $cookieToken = $request->cookie('vetops_session');
        // Accept token from cookie or bearer header for flexibility in tests.
        $cookieToken = $cookieToken ?? $request->bearerToken();

        if (!$cookieToken) {
            return response()->json(['message' => 'No active session.'], 401);
        }

        try {
            $result = $this->authService->refreshFromCookie($cookieToken);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Session is invalid or has expired.'], 422);
        }

        return response()->json([
            'token'                    => $cookieToken,
            'user'                     => $result['user'],
            'requires_password_change' => $result['requires_password_change'],
        ])->withCookie($this->sessionCookie($cookieToken, $request->isSecure()));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['facility', 'department']);
        return response()->json(['user' => $user]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password'         => ['required', 'string', 'min:12', 'confirmed', Password::min(12)],
        ]);

        $this->authService->changePassword(
            $request->user(),
            $data['current_password'],
            $data['password']
        );

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function captchaStatus(Request $request): JsonResponse
    {
        return response()->json($this->authService->getCaptchaChallenge($request->ip()));
    }

    private function sessionCookie(string $token, bool $secure): Cookie
    {
        return cookie(
            'vetops_session',
            $token,
            8 * 60,  // 8 hours — covers a typical work shift
            '/',
            null,
            $secure, // Secure flag (HTTPS-only in production)
            true,    // HttpOnly — not accessible from JavaScript
            false,
            'strict' // SameSite=Strict — prevents CSRF via cross-origin requests
        );
    }
}
