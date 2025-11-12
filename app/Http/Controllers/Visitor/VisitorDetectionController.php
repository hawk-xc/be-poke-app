<?php

namespace App\Http\Controllers\Visitor;

use Carbon\Carbon;
use App\Models\VisitorQueue;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Repositories\AuthRepository;
use Illuminate\Database\Eloquent\Builder;
use App\Models\VisitorDetection as Visitor;
use App\Models\VisitorDetection;
use Symfony\Component\HttpFoundation\JsonResponse;

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
    public function index(Request $request): JsonResponse
    {
        $baseQuery = Visitor::query();

        // Filter embedding & registered
        if ($request->query('data_status') === 'with_embedding') {
            $baseQuery->whereNotNull('embedding_id')
                ->where('is_registered', true);
        }

        // Filter soft delete
        if ($request->query('trashed') === 'with') {
            $baseQuery->withTrashed();
        } elseif ($request->query('trashed') === 'only') {
            $baseQuery->onlyTrashed();
        }

        // Filter search
        if ($request->filled('search')) {
            $search = $request->query('search');
            $searchBy = $request->query('search_by');

            if (!empty($searchBy) && in_array($searchBy, $this->searchableColumns)) {
                $baseQuery->where($searchBy, 'like', "%{$search}%");
            } else {
                $baseQuery->where(function ($q) use ($search) {
                    foreach ($this->searchableColumns as $col) {
                        $q->orWhere($col, 'like', "%{$search}%");
                    }
                });
            }
        }

        if ($request->query('visitor_image') === 'true') {
            $baseQuery->whereNotNull('person_pic_url');
        } elseif ($request->query('visitor_image') === 'false') {
            $baseQuery->whereNull('person_pic_url');
        }

        // Filter additional columns
        $filterableColumns = ['name', 'gender', 'phone_number', 'address', 'is_active'];
        foreach ($filterableColumns as $column) {
            if ($request->filled($column)) {
                $baseQuery->where($column, $request->query($column));
            }
        }

        // Filter waktu
        $now = Carbon::now();
        if ($request->has('start_time') && $request->has('end_time')) {
            $start = Carbon::parse($request->query('start_time'))->startOfSecond();
            $end = Carbon::parse($request->query('end_time'))->endOfSecond();
            $baseQuery->whereBetween('locale_time', [$start, $end]);
        } elseif ($request->filled('time')) {
            switch ($request->query('time')) {
                case 'today':
                    $baseQuery->whereDate('locale_time', $now->toDateString());
                    break;
                case 'week':
                    $baseQuery->whereBetween('locale_time', [$now->startOfWeek(), $now->endOfWeek()]);
                    break;
                case 'month':
                    $baseQuery->whereBetween('locale_time', [$now->startOfMonth(), $now->endOfMonth()]);
                    break;
                case 'year':
                    $baseQuery->whereBetween('locale_time', [$now->startOfYear(), $now->endOfYear()]);
                    break;
            }
        }

        // SUM / COUNT
        if ($request->query('sum') === 'count_data') {
            $count = (clone $baseQuery)->count();
            return $this->responseSuccess(['count' => $count], 'Visitors Counted Successfully!');
        }

        // ==============
        // MATCH HANDLER
        // ==============
        if ($request->has('match') && $request->query('match') === 'true') {
            $queryIn = (clone $baseQuery)
                ->where('is_matched', true)
                ->where('is_registered', true)
                ->where('label', 'in');

            $queryOut = (clone $baseQuery)
                ->where('is_matched', true)
                ->where('is_registered', true)
                ->where('label', 'out');

            // Pagination
            $perPage = $request->query('per_page', 15);
            $dataIn = $queryIn->paginate($perPage, ['*'], 'in_page');
            $dataOut = $queryOut->paginate($perPage, ['*'], 'out_page');

            return $this->responseSuccess([
                'data_in' => $dataIn,
                'data_out' => $dataOut
            ], 'Matched Visitors (IN/OUT) Fetched Successfully!');
        }

        // ============================
        // Jika tidak match=true
        // ============================
        if ($request->has('label')) {
            $label = $request->query('label');
            if (in_array($label, ['in', 'out'])) {
                $baseQuery->where('label', $label);
            }
        }

        if ($request->has('is_registered') && $request->query('is_registered') === 'true') {
            $baseQuery->where('is_registered', true)
                ->whereNotNull('embedding_id');
        }

        // Sorting
        if ($request->filled('sort_by')) {
            $sortDir = $request->query('sort_dir', 'asc');
            $baseQuery->orderBy($request->query('sort_by'), $sortDir);
        } else {
            $baseQuery->latest();
        }

        // Pagination biasa
        $perPage = $request->query('per_page', 15);
        $visitors = $baseQuery->paginate($perPage);

        return $this->responseSuccess($visitors, 'Visitors Fetched Successfully!');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
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
    public function show(string $id): JsonResponse
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
    public function update(Request $request, string $id): JsonResponse
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
    public function destroy(string $id): JsonResponse
    {
        $visitor = Visitor::findOrFail($id);
        $visitor->delete();
        return $this->responseSuccess(null, 'Visitor Soft Deleted Successfully!');
    }

    /**
     * Restore the specified soft-deleted resource.
     */
    public function restore(string $id): JsonResponse
    {
        $visitor = Visitor::onlyTrashed()->findOrFail($id);
        $visitor->restore();
        return $this->responseSuccess($visitor, 'Visitor Restored Successfully!');
    }

    /**
     * Permanently remove the specified resource from storage.
     */
    public function forceDelete(string $id): JsonResponse
    {
        $visitor = Visitor::onlyTrashed()->findOrFail($id);
        $visitor->forceDelete();
        return $this->responseSuccess(null, 'Visitor Permanently Deleted Successfully!');
    }

    public function getReport(Request $request): JsonResponse
    {
        $query = Visitor::all();

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

        if ($query->isEmpty()) {
            return $this->responseSuccess([], 'No visitor data available.');
        }

        $totalVisitors = $query->count();

        $avgDuration = rand(3, 15);

        $genderStats = $query->groupBy('face_sex')->map(function ($group) {
            return $group->count();
        });

        $ageStats = [
            'muda' => $query->where('face_age', '<=', 30)->count(),
            'tua' => $query->where('face_age', '>', 30)->count(),
        ];

        $inOutData = collect([
            [
                'name' => 'Visitor 1',
                'label_in' => '2025-11-04 08:00:00',
                'label_out' => '2025-11-04 08:15:00',
                'duration_minutes' => 15,
            ],
            [
                'name' => 'Visitor 2',
                'label_in' => '2025-11-04 09:10:00',
                'label_out' => '2025-11-04 09:25:00',
                'duration_minutes' => 15,
            ],
            [
                'name' => 'Visitor 3',
                'label_in' => '2025-11-04 10:00:00',
                'label_out' => '2025-11-04 10:22:00',
                'duration_minutes' => 22,
            ],
        ]);

        $labelIn = $query->where('label', 'in')->take(10)->values();
        $labelOut = $query->where('label', 'out')->take(10)->values();

        try {
            $data = [
                'total_visitors' => $totalVisitors,
                'average_duration_minutes' => $avgDuration,
                'gender_statistics' => $genderStats,
                'age_category_statistics' => $ageStats,
                'visitors_in' => $labelIn,
                'visitors_out' => $labelOut,
                'visit_durations' => $inOutData,
            ];

            return $this->responseSuccess($data, 'Report Fetched Successfully!');
        } catch (\Exception $err) {
            return $this->responseError(null, $err->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getQueues(Request $request): JsonResponse
    {
        $query = VisitorQueue::query();

        if ($request->has('label')) {
            $label = $request->query('label');
            if (in_array($label, ['in', 'out'])) {
                $query->where('label', $label);
            }
        }

        if ($request->has('status') && !empty($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('search') && !empty($request->query('search'))) {
            $search = $request->query('search');
            $query->where('rec_no', 'like', "%{$search}%");
        }

        if ($request->has('sort_by') && !empty($request->query('sort_by'))) {
            $sortBy = $request->query('sort_by');
            $sortDir = $request->query('sort_dir', 'asc');
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->latest('id');
        }

        $perPage = $request->query('per_page', 15);
        $queues = $query->paginate($perPage);

        return $this->responseSuccess($queues, 'Visitor Queues Fetched Successfully!');
    }

    public function getMatch(string $id): JsonResponse
    {
        $visitorOut = VisitorDetection::find($id);
        $visitorIn = VisitorDetection::where('rec_no', $visitorOut->rec_no_in)->first();

        return $this->responseSuccess([
            'visitor_out' => $visitorOut,
            'visitor_in' => $visitorIn,
        ], 'Matched Data Fetched Successfully!');
    }

    public function getMatchedData(Request $request): JsonResponse
    {
        $query = VisitorDetection::query();

        if ($request->query('data_status') === 'with_embedding') {
            $query->whereNotNull('embedding_id')
                ->where('is_registered', true);
        }

        if ($request->query('trashed') === 'with') {
            $query->withTrashed();
        } elseif ($request->query('trashed') === 'only') {
            $query->onlyTrashed();
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $searchBy = $request->query('search_by');
            $searchableColumns = ['name', 'event_type', 'gate_name', 'face_sex', 'emotion'];
            if (!empty($searchBy) && in_array($searchBy, $searchableColumns)) {
                $query->where($searchBy, 'like', "%{$search}%");
            } else {
                $query->where(function ($q) use ($search, $searchableColumns) {
                    foreach ($searchableColumns as $col) {
                        $q->orWhere($col, 'like', "%{$search}%");
                    }
                });
            }
        }

        if ($request->query('visitor_image') === 'true') {
            $query->whereNotNull('person_pic_url');
        } elseif ($request->query('visitor_image') === 'false') {
            $query->whereNull('person_pic_url');
        }

        $filterableColumns = ['name', 'face_sex', 'gate_name', 'event_type', 'emotion'];
        foreach ($filterableColumns as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->query($column));
            }
        }

        $now = Carbon::now();
        if ($request->has('start_time') && $request->has('end_time')) {
            $start = Carbon::parse($request->query('start_time'))->startOfSecond();
            $end = Carbon::parse($request->query('end_time'))->endOfSecond();
            $query->whereBetween('locale_time', [$start, $end]);
        } elseif ($request->filled('time')) {
            switch ($request->query('time')) {
                case 'today':
                    $query->whereDate('locale_time', $now->toDateString());
                    break;
                case 'week':
                    $query->whereBetween('locale_time', [$now->startOfWeek(), $now->endOfWeek()]);
                    break;
                case 'month':
                    $query->whereBetween('locale_time', [$now->startOfMonth(), $now->endOfMonth()]);
                    break;
                case 'year':
                    $query->whereBetween('locale_time', [$now->startOfYear(), $now->endOfYear()]);
                    break;
            }
        }

        if ($request->query('sum') === 'count_data') {
            $count = (clone $query)->count();
            return $this->responseSuccess(['count' => $count], 'Matched Visitors Counted Successfully!');
        }

        $query->where('is_registered', true)
            // real filtering
            // ->where('is_matched', true)
            // ->whereNotNull('embedding_id')
            ->where('label', 'out');

        if ($request->filled('sort_by')) {
            $sortDir = $request->query('sort_dir', 'asc');
            $query->orderBy($request->query('sort_by'), $sortDir);
        } else {
            $query->latest();
        }

        $perPage = $request->query('per_page', 15);
        $dataOut = $query->paginate($perPage);

        $result = [];
        foreach ($dataOut as $out) {
            $in = VisitorDetection::where('rec_no', $out->rec_no_in)->first();
            $result[] = [$out, $in];
        }

        return $this->responseSuccess([
            'data' => $result,
            'pagination' => [
                'current_page' => $dataOut->currentPage(),
                'last_page' => $dataOut->lastPage(),
                'per_page' => $dataOut->perPage(),
                'total' => $dataOut->total(),
            ]
        ], 'Matched Visitor IN/OUT Data Fetched Successfully!');
    }
}
