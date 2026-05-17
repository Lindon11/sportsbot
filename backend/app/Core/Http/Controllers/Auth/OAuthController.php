<?php

namespace App\Core\Http\Controllers\Auth;

use App\Core\Http\Controllers\Controller;
use App\Core\Services\OAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Core\Models\User;

class OAuthController extends Controller
{
    public function __construct(
        protected OAuthService $oauthService
    ) {}

    /**
     * Get list of available OAuth providers
     */
    public function providers(): JsonResponse
    {
        return response()->json([
            'providers' => $this->oauthService->getSupportedProviders(),
        ]);
    }

    /**
     * Get redirect URL for OAuth provider
     */
    public function redirect(string $provider): JsonResponse
    {
        try {
            $url = $this->oauthService->getRedirectUrl($provider);

            return response()->json([
                'redirect_url' => $url,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Handle OAuth callback.
     * Browser-redirected callbacks (from the OAuth provider) are forwarded to the
     * admin SPA with the token in the URL. XHR callers still receive JSON.
     */
    public function callback(string $provider)
    {
        try {
            $result = $this->oauthService->handleCallback($provider);
            $user = $result['user'] ?? null;

            // If the OAuth flow returned a pending token (user not yet created),
            // return that to the frontend so it can prompt for username completion.
            if (!$user) {
                if (!request()->expectsJson()) {
                    $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
                    $query = http_build_query([
                        'pending_oauth' => $result['pending_token'] ?? '',
                        'oauth_user' => base64_encode(json_encode($result['socialite'] ?? [])),
                    ]);
                    return redirect("{$frontendUrl}/login?{$query}");
                }

                return response()->json([
                    'is_new_user' => true,
                    'pending_token' => $result['pending_token'] ?? null,
                    'socialite' => $result['socialite'] ?? null,
                ]);
            }

            $user->update(['last_active' => now()]);

            $token = $user->createToken('auth-token')->plainTextToken;

            $userData = $user->load(['profile', 'roles']);

            // If the request came from a browser redirect (not an XHR/API call),
            // redirect back to the frontend SPA with the token in the query string.
            if (!request()->expectsJson()) {
                $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
                $query = http_build_query([
                    'oauth_token' => $token,
                    'oauth_user'  => base64_encode(json_encode([
                        'id'       => $user->id,
                        'username' => $user->username,
                        'email'    => $user->email,
                        'roles'    => $user->getRoleNames(),
                    ])),
                ]);
                return redirect("{$frontendUrl}/login?{$query}");
            }

            return response()->json([
                'user'        => $userData,
                'token'       => $token,
                'is_new_user' => $result['is_new'],
            ]);
        } catch (\Throwable $e) {
            if (!request()->expectsJson()) {
                $frontendUrl = rtrim(config('app.url'), '/');
                return redirect("{$frontendUrl}/login?oauth_error=" . urlencode($e->getMessage()));
            }
            return $this->handleGameException($e, 400);
        }
    }

    /**
     * Complete a pending OAuth registration (frontend supplies chosen username).
     */
    public function complete(Request $request, string $provider): JsonResponse
    {
        $data = $request->validate([
            'pending_token' => 'required|string',
            'username' => 'required|string|alpha_dash|max:255',
        ]);

        $pending = Cache::get($data['pending_token']);
        if (!$pending || ($pending['provider'] ?? null) !== $provider) {
            return response()->json(['message' => 'Invalid or expired pending token.'], 400);
        }

        // Ensure username not taken
        if (User::where('username', $data['username'])->exists()) {
            return response()->json(['message' => 'Username already taken.'], 422);
        }

        try {
            $user = $this->oauthService->createUserFromPending($pending, $data['username']);
            Cache::forget($data['pending_token']);

            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'user' => $user->load(['profile', 'roles']),
                'token' => $token,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to complete oauth registration: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to complete registration.'], 500);
        }
    }

    /**
     * Link OAuth provider to existing account
     */
    public function link(Request $request, string $provider): JsonResponse
    {
        try {
            $result = $this->oauthService->handleCallback($provider);

            // Check if this OAuth is already linked to another account
            if ($result['user']->id !== $request->user()->id) {
                return response()->json([
                    'message' => 'This OAuth account is already linked to another user.',
                ], 400);
            }

            return response()->json([
                'message' => ucfirst($provider) . ' account linked successfully.',
                'providers' => $this->oauthService->getLinkedProviders($request->user()),
            ]);
        } catch (\Throwable $e) {
            return $this->handleGameException($e, 400);
        }
    }

    /**
     * Unlink OAuth provider from account
     */
    public function unlink(Request $request, string $provider): JsonResponse
    {
        $user = $request->user();

        if (!$this->oauthService->unlinkProvider($user, $provider)) {
            return response()->json([
                'message' => 'Cannot unlink. You must have a password or another OAuth provider linked.',
            ], 400);
        }

        return response()->json([
            'message' => ucfirst($provider) . ' account unlinked successfully.',
            'providers' => $this->oauthService->getLinkedProviders($user),
        ]);
    }

    /**
     * Get linked OAuth providers for authenticated user
     */
    public function linked(Request $request): JsonResponse
    {
        return response()->json([
            'providers' => $this->oauthService->getLinkedProviders($request->user()),
        ]);
    }
}
