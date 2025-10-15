<?php

namespace App\Http\Controllers\Visitor;

use Carbon\Carbon;
use App\Models\VisitorDetection as Visitor;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Repositories\AuthRepository;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;

class VisitorDetectionController extends Controller
{
    use ResponseTrait;

    /**
     * @var AuthRepository
     */
    protected AuthRepository $authRepository;
    protected $searchableColumns = [
        'id',
        'label',
        'rec_no',
        'channel',
        'code',
        'action',
        'class',
        'event_type',
        'name',
        'locale_time',
        'face_sex',
        'object_action',
        'object_sex',
        'emotion',
        'passerby_group_id',
        'passerby_uid',
        'person_uid',
        'person_name',
        'person_sex',
        'person_group_name',
        'person_group_type',
        'person_pic_url',
        'similarity',
        'status',
    ];

    /**
     * AuthController constructor.
     */
    public function __construct(AuthRepository $ar)
    {
        // $this->middleware(['permission:visitor:list'])->only(['index', 'show']);
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

        if ($request->has('data_status') && $request->query('data_status') === 'with_embedding') {
            $query->whereNotNull('embedding_id');
        }

        if ($request->has('label') && $request->query('label') === 'in') {
            $query->where('label', 'in');
        }

        if ($request->has('label') && $request->query('label') === 'out') {
            $query->where('label', 'out');
        }

        if ($request->query('trashed') === 'with') {
            $query->withTrashed();
        } elseif ($request->query('trashed') === 'only') {
            $query->onlyTrashed();
        }

        if ($request->has('search') && !empty($request->query('search'))) {
            $search = $request->query('search');
            $searchBy = $request->query('search_by');

            if (!empty($searchBy) && in_array($searchBy, $this->searchableColumns)) {
                $query->where($searchBy, 'like', "%{$search}%");
            } else {
                $query->where(function (Builder $q) use ($search) {
                    foreach ($this->searchableColumns as $col) {
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
                    $query->whereDate('locale_time', $now->toDateString());
                    break;
                case 'week':
                    $startOfWeek = $now->copy()->startOfWeek(Carbon::MONDAY);
                    $endOfWeek = $now->copy()->endOfWeek(Carbon::SUNDAY);
                    $query->whereBetween('locale_time', [$startOfWeek, $endOfWeek]);
                    break;
                case 'month':
                    $query->whereBetween('locale_time', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);
                    break;
                case 'year':
                    $query->whereBetween('locale_time', [$now->copy()->startOfYear(), $now->copy()->endOfYear()]);
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

        $visitor_data = [
            'current' => $visitor,
            'related' => [],
        ];

        if (!is_null($visitor->embedding_id)) {
            $relatedVisitors = Visitor::withTrashed()
                ->where('embedding_id', $visitor->embedding_id)
                ->where('id', '!=', $visitor->id)
                ->orderBy('created_at', 'asc')
                ->get();

            $labeled = $relatedVisitors->map(function ($item) use ($visitor) {
                $item->label_relation = $visitor->label === 'in' ? 'out' : 'in';
                return $item;
            });

            $visitor_data['related'] = $labeled;
        }

        return $this->responseSuccess($visitor_data, 'Visitor fetched successfully!');
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
