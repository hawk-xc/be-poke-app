<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Repositories\AuthRepository;
use Spatie\Permission\Models\Role;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

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
        $this->middleware(['permission:users:list'])->only(['index', 'show']);
        $this->middleware(['permission:users:create'])->only('store');
        $this->middleware(['permission:users:edit'])->only('update');
        $this->middleware(['permission:users:delete'])->only(['destroy']);
        $this->middleware(['permission:roles:create'])->only(['assignRole']);
    
    
        $this->authRepository = $ar;
    }

    public function index(Request $request)
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

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);
            DB::commit();
            return $this->responseSuccess($user, 'User created successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->responseError(null, $e->getMessage(), 500);
        }
    }

    public function show(string $id)
    {
        $user = User::with('roles')->find($id);
        if (!$user) {
            return $this->responseError(null, 'User not found', 404);
        }
        return $this->responseSuccess($user, 'User retrieved successfully');
    }

    public function update(Request $request, string $id)
    {
        $user = User::find($id);
        if (!$user) {
            return $this->responseError(null, 'User not found', 404);
        }

        $data = array_filter($request->only(['name', 'email', 'password']), function ($value) {
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
        $request->validate($rules);

        DB::beginTransaction();
        try {
            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
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


    public function destroy(string $id)
    {
        $user = User::find($id);
        if (!$user) {
            return $this->responseError(null, 'User not found', 404);
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

    public function assignRole(Request $request, string $id)
    {
        $user = User::find($id);
        if (!$user) {
            return $this->responseError(null, 'User not found', 404);
        }

        $request->validate([
            'role' => 'required|string|exists:roles,name',
        ]);

        try {
            $user->assignRole($request->role);
            return $this->responseSuccess($user, 'Role assigned successfully');
        } catch (\Exception $e) {
            return $this->responseError(null, $e->getMessage(), 500);
        }
    }
}
