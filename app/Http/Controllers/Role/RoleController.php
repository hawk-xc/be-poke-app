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
        $this->middleware(['permission:roles:update'])->only(['update']);
        $this->middleware(['permission:roles:delete'])->only(['destroy']);
        $this->middleware(['permission:roles:assign-permission'])->only(['assignPermission']);

        $this->authRepository = $ar;
    }


    public function index(Request $request)
    {
        $roles = Role::withCount('users', 'permissions');

        if ($request->has('search')) {
            $roles->where('name', 'like', '%' . $request->search . '%');
        }

        $roles = $roles->get();

        return $this->responseSuccess($roles, 'Roles retrieved successfully');
    }

    public function show(string $id)
    {
        $role = Role::with(['users', 'permissions'])->find($id);

        if (!$role) {
            return $this->responseError(null, 'Role not found', 404);
        }

        return $this->responseSuccess($role, 'Role retrieved successfully');
    }

    public function showWithoutPermissions(string $id)
    {
        $role = Role::with(['users'])->find($id);

        if (!$role) {
            return $this->responseError(null, 'Role not found', 404);
        }

        return $this->responseSuccess($role, 'Role retrieved successfully');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name'
        ]);

        $permissionNames = null;

        if (!is_array($request->permissions)) {
            $request->validate([
                'permissions' => ['sometimes', 'string', function ($attribute, $value, $fail) {
                    $permissionNames = array_map('trim', explode(',', $value));
                    $dbPermissions = \Spatie\Permission\Models\Permission::whereIn('name', $permissionNames)->get();

                    if (count($permissionNames) !== $dbPermissions->count()) {
                        $missingPermissions = array_diff($permissionNames, $dbPermissions->pluck('name')->all());
                        $fail('The following permissions do not exist: ' . implode(', ', $missingPermissions));
                    }
                }],
            ]);

            $permissionNames = array_map('trim', explode(',', $request->permissions));
        } {
            $permissionNames = $request->permissions;
        }

        $role = Role::create(['name' => $request->name]);
        $role->givePermissionTo($permissionNames);
        $role->load('permissions');

        return $this->responseSuccess($role, 'Role created successfully');
    }

    public function assignPermissions(Request $request, string $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return $this->responseError(null, 'Role not found', 404);
        }

        $request->validate([
            'permissions' => ['required', 'string', function ($attribute, $value, $fail) {
                $permissionNames = array_map('trim', explode(',', $value));
                $dbPermissions = \Spatie\Permission\Models\Permission::whereIn('name', $permissionNames)->get();

                if (count($permissionNames) !== $dbPermissions->count()) {
                    $missingPermissions = array_diff($permissionNames, $dbPermissions->pluck('name')->all());
                    $fail('The following permissions do not exist: ' . implode(', ', $missingPermissions));
                }
            }],
        ]);

        $permissionNames = array_map('trim', explode(',', $request->permissions));

        $role->givePermissionTo($permissionNames);

        $role->load('permissions');

        return $this->responseSuccess($role, 'Permissions assigned successfully');
    }

    public function update(Request $request, string $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return $this->responseError(null, 'Role not found', 404);
        }

        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $id
        ]);

        $role->update(['name' => $request->name]);

        return $this->responseSuccess($role, 'Role updated successfully');
    }

    public function destroy(string $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return $this->responseError(null, 'Role not found', 404);
        }

        $role->delete();

        return $this->responseSuccess(null, 'Role deleted successfully');
    }
}
