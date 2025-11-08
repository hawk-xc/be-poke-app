<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Tymon\JWTAuth\JWTGuard;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\LoginRequest;
use App\Http\Controllers\Controller;
use App\Repositories\AuthRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\RegisterRequest;
use Illuminate\Support\Facades\Password;

/**
 * @mixin \Tymon\JWTAuth\JWTGuard
 */
class AuthController extends Controller
{
    use ResponseTrait;

    /**
     * @var AuthRepository
     */
    protected AuthRepository $authRepository;

    /**
     * AuthController constructor.
     */
    public function __construct(AuthRepository $ar)
    {
        $this->middleware('auth:api', ['except' => ['login', 'register', 'sendResetLink', 'resetPassword']]);
        $this->authRepository = $ar;
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $credentials = $request->only('email', 'password');

            if (!$token = $this->guard()->attempt($credentials)) {
                return $this->responseError(null, 'Invalid Email or Password !', Response::HTTP_UNAUTHORIZED);
            }

            return $this->responseSuccess(
                $this->respondWithToken($token),
                'Logged In Successfully !'
            );
        } catch (\Exception $e) {
            return $this->responseError(null, $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $data = $request->only('name', 'email', 'password', 'password_confirmation');
            $user = $this->authRepository->register($data);

            if ($user && $token = $this->guard()->attempt($request->only('email', 'password'))) {
                return $this->responseSuccess(
                    $this->respondWithToken($token),
                    'User Registered and Logged in Successfully'
                );
            }

            return $this->responseError(null, 'User registration failed', Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->responseError(null, $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function me(): JsonResponse
    {
        try {
            return $this->responseSuccess($this->guard()->user(), 'Profile Fetched Successfully !');
        } catch (\Exception $e) {
            return $this->responseError(null, $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request): JsonResponse
    {
        try {
            $user = $this->guard()->user();

            $request->validate([
                'name'   => 'sometimes|string|max:255',
                'email'  => 'sometimes|email|unique:users,email,' . $user->id,
                'avatar' => 'sometimes|image|mimes:jpeg,png,jpg|max:2048',
            ]);

            if ($request->filled('name')) {
                $user->name = $request->name;
            }
            if ($request->filled('email')) {
                $user->email = $request->email;
            }

            if ($request->hasFile('avatar')) {
                if ($user->avatar && file_exists(public_path(str_replace('/storage', 'storage', $user->avatar)))) {
                    @unlink(public_path(str_replace('/storage', 'storage', $user->avatar)));
                }

                $path = $request->file('avatar')->store('public/avatars');
                $user->avatar = '/storage/' . str_replace('public/', '', $path);
            }

            $user->save();

            return $this->responseSuccess($user, 'Profile updated successfully!');
        } catch (\Exception $e) {
            return $this->responseError(null, $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    public function logout(): JsonResponse
    {
        try {
            $this->guard()->logout();
            return $this->responseSuccess(null, 'Logged out successfully !');
        } catch (\Exception $e) {
            return $this->responseError(null, $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function refresh(): JsonResponse
    {
        try {
            return $this->responseSuccess(
                $this->respondWithToken($this->guard()->refresh()),
                'Token Refreshed Successfully !'
            );
        } catch (\Exception $e) {
            return $this->responseError(null, $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Build the token response structure.
     */
    protected function respondWithToken(string $token): array
    {
        return [
            'access_token' => $token,
            'token_type'   => 'bearer',
            // TTL asli dari jwt.php config (dalam menit), dikali 60 = detik
            'expires_in'   => $this->guard()->factory()->getTTL() * 60,
            'user'         => $this->guard()->user(),
        ];
    }

    /**
     * Get the JWT guard.
     */
    protected function guard(): JWTGuard
    {
        /** @var JWTGuard $guard */
        $guard = Auth::guard('api');
        return $guard;
    }

    public function newPassword(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
            'new_password' => 'required|string|min:8',
        ]);

        $credentials = [
            'email' => auth()->user()->email,
            'password' => $request->password,
        ];

        if (!$this->guard()->validate($credentials)) {
            return $this->responseError(null, 'Invalid Email or Password !', Response::HTTP_UNAUTHORIZED);
        }

        try {
            $user = $this->guard()->user();
            $user->password = Hash::make($request->new_password);
            $user->save();

            return $this->responseSuccess($user, 'Password changed successfully');
        } catch (\Exception $e) {
            return $this->responseError(null, $e->getMessage(), 500);
        }
    }

    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return $this->responseSuccess([], __($status), 200);
        } else {
            return $this->responseError([], __($status), 400);
        }
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->responseSuccess([], __($status), 200);
        } else {
            return $this->responseError([], __($status), 400);
        }
    }
}
