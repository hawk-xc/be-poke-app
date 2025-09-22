<?php

namespace App\Http\Controllers\Visitor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Visitor;
use App\Traits\ResponseTrait;
use Illuminate\Database\Eloquent\Builder;

class VisitorController extends Controller
{
    use ResponseTrait;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Visitor::query();

        // Handle soft deletes
        if ($request->query('trashed') === 'with') {
            $query->withTrashed();
        } elseif ($request->query('trashed') === 'only') {
            $query->onlyTrashed();
        }

        // Global search
        if ($request->has('search') && !empty($request->query('search'))) {
            $search = $request->query('search');
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('gender', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%");
            });
        }

        // Column-specific filters
        $filterableColumns = ['name', 'gender', 'phone_number', 'address', 'is_active'];
        foreach ($filterableColumns as $column) {
            if ($request->has($column) && !empty($request->query($column))) {
                $query->where($column, $request->query($column));
            }
        }

        // Sorting
        if ($request->has('sort_by') && !empty($request->query('sort_by'))) {
            $sortBy = $request->query('sort_by');
            $sortDir = $request->query('sort_dir', 'asc');
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->latest(); // Default sort
        }

        // Pagination
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

        $visitor = Visitor::create($validated);
        return $this->responseSuccess($visitor, 'Visitor Created Successfully!', 201);
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