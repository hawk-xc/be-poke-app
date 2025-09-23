<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Repositories\AuthRepository;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Tymon\JWTAuth\JWTGuard;

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
        $this->middleware('auth:api', ['except' => ['login', 'register']]);
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
}
