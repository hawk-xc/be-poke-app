<?php

namespace App\Http\Controllers\Visitor;

use Carbon\Carbon;
use App\Models\Visitor;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Repositories\AuthRepository;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;

class VisitorController extends Controller
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
        $this->middleware(['permission:visitor:list'])->only(['index', 'show']);
        $this->middleware(['permission:visitor:create'])->only('store');
        $this->middleware(['permission:visitor:edit'])->only('update');
        $this->middleware(['permission:visitor:delete'])->only(['destroy', 'restore', 'forceDelete']);
        
        $this->authRepository = $ar;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Visitor::query();

        if ($request->query('trashed') === 'with') {
            $query->withTrashed();
        } elseif ($request->query('trashed') === 'only') {
            $query->onlyTrashed();
        }

        if ($request->has('search') && !empty($request->query('search'))) {
            $search = $request->query('search');
            $searchBy = $request->query('search_by');

            $searchableColumns = ['name', 'gender', 'phone_number', 'address', 'person_group', 'sex', 'similarity', 'emotion', 'mask', 'glasses', 'beard', 'attractive', 'mouth', 'eye', 'strabismus', 'nation', 'task_name'];

            if (!empty($searchBy) && in_array($searchBy, $searchableColumns)) {
                $query->where($searchBy, 'like', "%{$search}%");
            } else {
                $query->where(function (Builder $q) use ($search, $searchableColumns) {
                    foreach ($searchableColumns as $col) {
                        $q->orWhere($col, 'like', "%{$search}%");
                    }
                });
            }
        }

        $filterableColumns = ['name', 'gender', 'phone_number', 'address', 'is_active'];
        foreach ($filterableColumns as $column) {
            if ($request->has($column) && !empty($request->query($column))) {
                $query->where($column, $request->query($column));
            }
        }

        if ($request->has('start_time') && $request->has('end_time')) {
            $query->whereBetween('start_time', [$request->query('start_time'), $request->query('end_time')]);
        }

        if ($request->has('time')) {
            $time = $request->query('time');
            $now = Carbon::now();

            switch ($time) {
                case 'today':
                    $query->whereDate('start_time', $now->toDateString());
                    break;
                case 'week':
                    $startOfWeek = $now->copy()->startOfWeek(Carbon::MONDAY);
                    $endOfWeek = $now->copy()->endOfWeek(Carbon::SUNDAY);
                    $query->whereBetween('start_time', [$startOfWeek, $endOfWeek]);
                    break;
                case 'month':
                    $query->whereBetween('start_time', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);
                    break;
                case 'year':
                    $query->whereBetween('start_time', [$now->copy()->startOfYear(), $now->copy()->endOfYear()]);
                    break;
            }
        }

        if ($request->query('sum') === 'count_data') {
            $count = $query->count();
            return $this->responseSuccess(['count' => $count], 'Visitors Counted Successfully!');
        }

        if ($request->has('sort_by') && !empty($request->query('sort_by'))) {
            $sortBy = $request->query('sort_by');
            $sortDir = $request->query('sort_dir', 'asc');
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->latest();
        }

        $perPage = $request->query('per_page', 15);
        $visitors = $query->paginate($perPage);

        return $this->responseSuccess($visitors, 'Visitors Fetched Successfully!');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'gender' => 'required|in:Male,Female',
            'phone_number' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        try {
            $visitor = Visitor::create($validated);

            return $this->responseSuccess($visitor, 'Visitor Created Successfully!', 201);
        } catch (\Exception $e) {
            return $this->responseError($e->getMessage(), 'Visitor Creation Failed!', 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $visitor = Visitor::withTrashed()->findOrFail($id);
        return $this->responseSuccess($visitor, 'Visitor Fetched Successfully!');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $visitor = Visitor::withTrashed()->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'gender' => 'sometimes|required|in:Male,Female',
            'phone_number' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $visitor->update($validated);
        return $this->responseSuccess($visitor, 'Visitor Updated Successfully!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $visitor = Visitor::findOrFail($id);
        $visitor->delete();
        return $this->responseSuccess(null, 'Visitor Soft Deleted Successfully!');
    }

    /**
     * Restore the specified soft-deleted resource.
     */
    public function restore(string $id)
    {
        $visitor = Visitor::onlyTrashed()->findOrFail($id);
        $visitor->restore();
        return $this->responseSuccess($visitor, 'Visitor Restored Successfully!');
    }

    /**
     * Permanently remove the specified resource from storage.
     */
    public function forceDelete(string $id)
    {
        $visitor = Visitor::onlyTrashed()->findOrFail($id);
        $visitor->forceDelete();
        return $this->responseSuccess(null, 'Visitor Permanently Deleted Successfully!');
    }
}
