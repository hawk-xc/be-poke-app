<?php

namespace App\Http\Controllers\Role;

use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use Spatie\Permission\Models\Role;
use App\Repositories\AuthRepository;
use App\Http\Controllers\Controller;

class RoleController extends Controller
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
        $this->middleware(['permission:roles:list'])->only(['index', 'show']);
        $this->middleware(['permission:roles:create'])->only(['store']);
        $this->middleware(['permission:roles:assign-permission'])->only(['assignPermission']);
        
        $this->authRepository = $ar;
    }


    public function index(Request $request)
    {
        $roles = Role::withCount('users');

        if ($request->has('name')) {
            $roles->where('name', 'like', '%' . $request->name . '%');
        }

        $roles = $roles->get(); 

        return $this->responseSuccess($roles, 'Roles retrieved successfully');
    }

    public function show(string $id)
    {
        $role = Role::with(['users', 'permissions'])->find($id);

        if (!$role) {
            return $this->errorResponse(null, 'Role not found', 404);
        }

        return $this->responseSuccess($role, 'Role retrieved successfully');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name'
        ]);

        $role = Role::create(['name' => $request->name]);

        return $this->responseSuccess($role, 'Role created successfully');
    }

    public function assignPermission(Request $request, string $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return $this->errorResponse(null, 'Role not found', 404);
        }

        $request->validate([
            'permission' => 'required|string|exists:permissions,name'
        ]);

        $role->givePermissionTo($request->permission);

        return $this->responseSuccess($role, 'Permission assigned successfully');
    }
}