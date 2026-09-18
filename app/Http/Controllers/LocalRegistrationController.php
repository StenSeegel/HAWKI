<?php
declare(strict_types=1);


namespace App\Http\Controllers;


use App\Models\User;
use App\Services\Auth\EmailDomainRoleResolver;
use App\Services\Auth\EmailVerificationService;
use App\Services\Auth\Http\GuestUserRequest;
use App\Services\Auth\Value\Local\EmailVerificationResult;
use App\Services\Users\Db\UserDb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class LocalRegistrationController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $verification,
        private readonly EmailDomainRoleResolver  $domainResolver,
    )
    {
    }

    /**
     * Submit guest access request
     * Creates a new local user account with submitted credentials
     */
    public function submitGuestRequest(
        GuestUserRequest $request,
        UserDb           $userDb
    ): JsonResponse
    {
        $email = (string) $request->validated('email');
        $rule = null;

        if ($this->domainResolver->isActive()) {
            $rule = $this->domainResolver->match($email);

            if (! $rule || ! $rule->role) {
                return response()->json([
                    'success' => false,
                    'reason' => 'domain_not_allowed',
                    'errors' => [
                        'email' => ['This email domain is not allowed to register.'],
                    ],
                    'message' => 'This email domain is not allowed to register.',
                ], 422);
            }
        }

        // An address that is already taken by an unverified account never creates a second row.
        // The owner of the mailbox simply gets a new code instead.
        $pending = User::where('email', $email)
            ->whereNull('email_verified_at')
            ->where('auth_type', 'local')
            ->first();

        if ($pending) {
            return response()->json([
                'success' => false,
                'reason' => 'unverified_exists',
                'token' => $this->verification->stepToken($pending),
                'email_masked' => $this->verification->maskEmail($pending->email),
                'message' => 'This email address is already waiting for confirmation. We can send the code again.',
            ]);
        }

        $roleSlug = $rule ? $rule->role->slug : (string) $request->validated('employeetype');
        $requiresVerification = $this->verification->requiresVerification($roleSlug, $rule);

        $user = $userDb->createUserFromGuestUserRequest(
            data: $request->getData($roleSlug),
            emailVerified: ! $requiresVerification,
            domainRuleId: $rule?->id
        );

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing your request. Please try again.',
            ], 500);
        }

        if (! $requiresVerification) {
            return response()->json([
                'success' => true,
                'verification_required' => false,
                'message' => 'Your guest access request has been submitted successfully. You can now log in with your credentials.',
            ]);
        }

        $this->verification->issue($user);

        return response()->json([
            'success' => true,
            'verification_required' => true,
            'token' => $this->verification->stepToken($user),
            'email_masked' => $this->verification->maskEmail($user->email),
            'message' => 'We sent a confirmation code to your email address.',
        ]);
    }

    /**
     * Check the code entered in the guest panel, identified by the step token.
     */
    public function verify(Request $request): JsonResponse
    {
        $user = $this->verification->userFromToken($request->input('token'));

        if (! $user) {
            return $this->invalidTokenResponse();
        }

        return $this->respondToVerification($request, $user);
    }

    /**
     * Send a new code to the address of the account behind the step token.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $this->verification->userFromToken($request->input('token'));

        if (! $user) {
            return $this->invalidTokenResponse();
        }

        return $this->respondToResend($user);
    }

    /**
     * Correct the address of the account behind the step token.
     */
    public function changeAddress(Request $request): JsonResponse
    {
        $user = $this->verification->userFromToken($request->input('token'));

        if (! $user) {
            return $this->invalidTokenResponse();
        }

        return $this->respondToChangeAddress($request, $user);
    }

    /**
     * Twin of verify() for the pre-slide in /register, acting on the session user.
     */
    public function verifySession(Request $request): JsonResponse
    {
        $user = $this->sessionUser();

        if (! $user) {
            return $this->invalidTokenResponse();
        }

        return $this->respondToVerification($request, $user);
    }

    /**
     * Twin of resend() for the pre-slide in /register.
     */
    public function resendSession(Request $request): JsonResponse
    {
        $user = $this->sessionUser();

        if (! $user) {
            return $this->invalidTokenResponse();
        }

        return $this->respondToResend($user);
    }

    /**
     * Twin of changeAddress() for the pre-slide in /register.
     */
    public function changeAddressSession(Request $request): JsonResponse
    {
        $user = $this->sessionUser();

        if (! $user) {
            return $this->invalidTokenResponse();
        }

        return $this->respondToChangeAddress($request, $user);
    }

    private function respondToVerification(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'otp' => 'required|string',
        ]);

        $result = $this->verification->verify($user, (string) $request->input('otp'));

        if ($result->isVerified()) {
            // The session copy of the address may be stale after a change, refresh it.
            $this->refreshSessionUserInfo($user);

            return response()->json([
                'success' => true,
                'message' => 'Your email address has been confirmed. You can now log in.',
            ]);
        }

        return response()->json([
            'success' => false,
            'reason' => $result->status,
            'attempts_left' => $result->attemptsLeft,
            'message' => $result->status === EmailVerificationResult::STATUS_INVALID
                ? 'The code is not correct.'
                : 'The code has expired. Please request a new one.',
        ], 422);
    }

    private function respondToResend(User $user): JsonResponse
    {
        $this->verification->issue($user);

        return response()->json([
            'success' => true,
            'token' => $this->verification->stepToken($user),
            'email_masked' => $this->verification->maskEmail($user->email),
            'message' => 'We sent a new confirmation code to your email address.',
        ]);
    }

    private function respondToChangeAddress(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $status = $this->verification->changeAddress($user, (string) $request->input('email'));

        if ($status !== 'ok') {
            $message = match ($status) {
                'email_taken' => 'This email address is already registered.',
                'domain_not_allowed' => 'This email domain is not allowed to register.',
                default => 'This account has already been confirmed.',
            };

            return response()->json([
                'success' => false,
                'reason' => $status,
                'errors' => ['email' => [$message]],
                'message' => $message,
            ], 422);
        }

        $user->refresh();
        $this->refreshSessionUserInfo($user);

        return response()->json([
            'success' => true,
            'token' => $this->verification->stepToken($user),
            'email_masked' => $this->verification->maskEmail($user->email),
            'message' => 'We sent a confirmation code to your new email address.',
        ]);
    }

    /**
     * The unverified local account the current registration session belongs to.
     */
    private function sessionUser(): ?User
    {
        $userInfo = json_decode((string) Session::get('authenticatedUserInfo'), true);
        $username = $userInfo['username'] ?? null;

        if (empty($username)) {
            return null;
        }

        return User::where('username', $username)
            ->where('auth_type', 'local')
            ->whereNull('email_verified_at')
            ->first();
    }

    /**
     * Keep the session copy of the user data in sync after an address or role change.
     */
    private function refreshSessionUserInfo(User $user): void
    {
        $userInfo = json_decode((string) Session::get('authenticatedUserInfo'), true);

        if (! is_array($userInfo) || ($userInfo['username'] ?? null) !== $user->username) {
            return;
        }

        $userInfo['email'] = $user->email;
        $userInfo['employeetype'] = $user->employeetype;

        Session::put('authenticatedUserInfo', json_encode($userInfo));
    }

    private function invalidTokenResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'reason' => 'token_invalid',
            'message' => 'This confirmation link is no longer valid. Please start again.',
        ], 422);
    }
}
