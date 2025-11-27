<?php

namespace App\Http\Controllers\User;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use App\Http\Controllers\Controller;
use App\Repositories\AuthRepository;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
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
        $this->middleware(['permission:users:list'])->only(['index', 'show', 'getUserPassword']);
        $this->middleware(['permission:users:create'])->only('store');
        $this->middleware(['permission:users:edit'])->only('update', 'activateUser', 'deactivateUser');
        $this->middleware(['permission:users:delete'])->only(['destroy']);
        $this->middleware(['permission:roles:create'])->only(['assignRole']);
        $this->middleware(['permission:roles:delete'])->only(['revokeRole']);

        $this->authRepository = $ar;
    }

    public function index(Request $request): JsonResponse
    {
        $users = User::with('roles');

        if ($request->has('role')) {
            $users->whereHas('roles', function ($query) use ($request) {
                $query->where('name', $request->role);
            });
        }

        $users = $users->get();

        return $this->responseSuccess($users, 'Users retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'                  => 'required|string|max:255',
            'email'                 => 'required|string|email|max:255|unique:users',
            'password'              => 'nullable|string|min:8|confirmed',
            'username'              => 'nullable|string|max:255|unique:users',
            'firstname'             => 'nullable|string|max:255',
            'lastname'              => 'nullable|string|max:255',
            'roles'                 => 'nullable|string',
            'roles.*'               => 'string|exists:roles,name',
        ]);

        DB::beginTransaction();
        try {
            $data = [
                'is_active'         => false,
                'name'              => $request->name,
                'email'             => $request->email,
                'username'          => $request->username,
                'firstname'         => $request->firstname,
                'lastname'          => $request->lastname,
                'fullname'          => $request->firstname . ' ' . $request->lastname,
            ];

            if ($request->filled('password')) {
                $plainPassword = $request->password;
            } else {
                $plainPassword = Str::random(10);
            }

            $data['password'] = Hash::make($plainPassword);
            $data['secure_password'] = encrypt($plainPassword); 

            $user = User::create($data);

            if ($request->has('roles') && !empty($request->roles)) {
                $user->assignRole($request->roles);
            }

            DB::commit();

            $user->plain_password = $plainPassword;

            return $this->responseSuccess($user, 'User created successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->responseError(null, $e->getMessage(), 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        $user = User::with('roles')->find($id);
        if (!$user) {
            return $this->responseError(null, 'User not found', 404);
        }
        return $this->responseSuccess($user, 'User retrieved successfully');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return $this->responseError(null, 'User not found', 404);
        }

        $data = array_filter($request->only(['name', 'email', 'password', 'username', 'firstname', 'lastname']), function ($value) {
            return !is_null($value) && $value !== '';
        });

        if (empty($data)) {
            return $this->responseError(null, 'No data provided to update', 422);
        }

        $rules = [];
        if (array_key_exists('name', $data)) {
            $rules['name'] = 'string|max:255';
        }
        if (array_key_exists('email', $data)) {
            $rules['email'] = 'string|email|max:255|unique:users,email,' . $id;
        }
        if (array_key_exists('password', $data)) {
            $rules['password'] = 'string|min:8';
        }
        if (array_key_exists('username', $data)) {
            $rules['username'] = 'string|max:255|unique:users,username,' . $id;
        }
        if (array_key_exists('firstname', $data)) {
            $rules['firstname'] = 'string|max:255';
        }
        if (array_key_exists('lastname', $data)) {
            $rules['lastname'] = 'nullable|string|max:255';
        }
        $request->validate($rules);

        DB::beginTransaction();
        try {
            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
                $data['secure_password'] = encrypt($data['password']);
            }

            if (isset($data['firstname']) || isset($data['lastname'])) {
                $firstname = $data['firstname'] ?? $user->firstname;
                $lastname = $data['lastname'] ?? $user->lastname;
                $data['fullname'] = $firstname . ' ' . $lastname;
            }

            $user->fill($data);
            $user->save();

            DB::commit();
            return $this->responseSuccess($user, 'User updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->responseError(null, $e->getMessage(), 500);
        }
    }


    public function destroy(string $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return $this->responseError(null, 'User not found', 404);
        }

        if ($user->hasRole('admin')) {
            return $this->responseError(null, 'Cannot delete admin user', 403);
        }

        DB::beginTransaction();
        try {
            $user->delete();
            DB::commit();
            return $this->responseSuccess(null, 'User deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->responseError(null, $e->getMessage(), 500);
        }
    }

    public function assignRole(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);
        
        if (!$user) {
            return $this->responseError(null, 'User not found', 404);
        }

        $request->validate([
            'roles'   => 'required|string',
        ]);

        DB::beginTransaction();
        try {
            $user->assignRole($request->roles);
            DB::commit();
            return $this->responseSuccess($user->load('roles'), 'Roles assigned successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->responseError(null, $e->getMessage(), 500);
        }
    }

    public function revokeRole(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return $this->responseError(null, 'User not found', 404);
        }

        $request->validate([
            'roles'   => 'required|array',
            'roles.*' => 'string|exists:roles,name'
        ]);

        DB::beginTransaction();
        try {
            foreach ($request->roles as $role) {
                if ($user->hasRole($role)) {
                    $user->removeRole($role);
                }
            }
            DB::commit();
            return $this->responseSuccess($user->load('roles'), 'Roles revoked successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->responseError(null, $e->getMessage(), 500);
        }
    }

    public function activateUser(string $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return $this->responseError(null, 'User not found', 404);
        }

        DB::beginTransaction();

        try {
            $user->is_active = true;
            $user->save();
            DB::commit();

            return $this->responseSuccess($user, 'User activated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->responseError(null, $e->getMessage(), 500);
        }
    }

    public function deactivateUser(string $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return $this->responseError(null, 'User not found', 404);
        }

        DB::beginTransaction();
        try {
            $user->is_active = false;
            $user->save();
            DB::commit();

            return $this->responseSuccess($user, 'User deactivated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->responseError(null, $e->getMessage(), 500);
        }
    }

    public function getUserPassword(string $id): JsonResponse
    {
        $user = User::find($id);
        
        if ($user->secure_password == null) {
            return $this->responseError(null, 'User secure password not found', 404);
        }

        $user_password = decrypt($user->secure_password);

        if (!$user_password) {
            return $this->responseError(null, 'User password not found', 404);
        }

        return $this->responseSuccess([
            'secure_password' => $user_password
        ], 'User password fetched successfully');
    }
}
