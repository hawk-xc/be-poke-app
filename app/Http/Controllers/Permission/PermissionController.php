<?php

namespace App\Http\Controllers\Permission;

use Exception;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Repositories\AuthRepository;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
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
        $this->middleware(['permission:permissions:list'])->only(['index', 'show']);

        $this->authRepository = $ar;
    }

    public function index(Request $request)
    {
        try {
            $permissions = Permission::query();

            if ($request->has('name')) {
                $permissions->where('name', 'like', '%' . $request->name . '%');
            }

            $permissions = $permissions->get();

            return $this->responseSuccess($permissions, 'Permissions retrieved successfully');
        } catch (Exception $err) {
            Log::error('List All Permission Error : ' . $err->getMessage());
            return $this->responseError([], 'Permissions retrieved failed!');
        }
    }

    public function show(string $id)
    {
        try {
            $permission = Permission::with(['roles'])->findOrFail($id);

            return $this->responseSuccess($permission, 'Permission retrieved successfully');
        } catch (Exception $err) {
            Log::error('Show Permission Error : ' . $err->getMessage());
            return $this->responseError([], 'Permission retrieved failed!');
        }
    }
}
