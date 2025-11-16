<?php

namespace App\Http\Controllers\Visitor;

use Exception;
use Carbon\Carbon;
use App\Models\VisitorQueue;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use Illuminate\Http\Response;
use App\Models\VisitorDetection;
use App\Http\Controllers\Controller;
use App\Repositories\AuthRepository;
use Illuminate\Database\Eloquent\Builder;
use App\Models\VisitorDetection as Visitor;
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
    protected $revertColumns = [
        'is_registered' => false,
        'is_matched' => false,
        'face_token' => null,
        'faceset_token' => null,
        'class' => null,
        'status' => false,
        'embedding_id' => null,
        'similarity' => null,
        'duration' => 0,
        'rec_no_in' => null,
        'revert_by' => 'human'
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

        if ($request->filled('label')) {
            if ($request->query('label') === 'in') {
                $baseQuery->visitorIn();
            } elseif ($request->query('label') === 'out') {
                $baseQuery->visitorOut();
            }
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
                    $baseQuery->today();
                    break;
                case 'week':
                    $baseQuery->thisWeek();
                    break;
                case 'month':
                    $baseQuery->thisMonth();
                    break;
                case 'year':
                    $baseQuery->thisYear();
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
        try {
            $timezone = config('app.timezone', 'Asia/Jakarta'); // atau $this->timezone
            $start = now($timezone)->startOfDay();
            $end = now($timezone)->endOfDay();
            $timeLabel = 'daily'; // default

            // Filter berdasarkan time preset
            if ($request->filled('time')) {
                switch ($request->query('time')) {
                    case 'today':
                        $start = now($timezone)->startOfDay();
                        $end = now($timezone)->endOfDay();
                        $timeLabel = 'daily';
                        break;
                    case 'week':
                        $start = now($timezone)->startOfWeek();
                        $end = now($timezone)->endOfWeek();
                        $timeLabel = 'weekly';
                        break;
                    case 'month':
                        $start = now($timezone)->startOfMonth();
                        $end = now($timezone)->endOfMonth();
                        $timeLabel = 'monthly';
                        break;
                    case 'year':
                        $start = now($timezone)->startOfYear();
                        $end = now($timezone)->endOfYear();
                        $timeLabel = 'yearly';
                        break;
                    default:
                        $start = now($timezone)->startOfDay();
                        $end = now($timezone)->endOfDay();
                        $timeLabel = 'daily';
                        break;
                }
            }

            // Filter berdasarkan custom date range (override preset)
            if ($request->filled('start_date') && $request->filled('end_date')) {
                try {
                    $start = Carbon::parse($request->start_date, $timezone)->startOfSecond();
                    $end = Carbon::parse($request->end_date, $timezone)->endOfSecond();
                    $timeLabel = 'custom';
                } catch (\Exception $e) {
                    return $this->responseError(
                        'Format waktu tidak valid. Gunakan format YYYY-MM-DD HH:mm:ss',
                        'Invalid Date Format',
                        422
                    );
                }
            }

            // Base query dengan time filter
            $baseQuery = VisitorDetection::whereBetween('locale_time', [$start, $end])->matched();

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

            // Execute query sekali untuk efisiensi
            $visitors = $baseQuery->get();

            // ========================
            // TOTAL VISITOR
            // ========================
            $totalIn = $visitors->where('label', 'in')->count();
            $totalOut = $visitors->where('label', 'out')->count();
            $totalAll = $visitors->count();
            $visitorInside = $totalIn - $totalOut;

            // ========================
            // DURATION STATISTICS
            // ========================
            $matchedVisitors = $visitors->where('label', 'out')
                ->where('is_matched', 1)
                ->whereNotNull('duration');

            // Total seluruh jam (dari duration)
            $totalHours = $matchedVisitors->sum('duration') / 60; // Convert minutes to hours
            $totalMinutes = $matchedVisitors->sum('duration');

            // Rata-rata jam (length of visit)
            $avgDuration = $matchedVisitors->avg('duration');
            $lengthOfVisit = $avgDuration ? round($avgDuration, 2) : 0;
            $lengthOfVisitHours = $avgDuration ? round($avgDuration / 60, 2) : 0;

            // ========================
            // PEAK HOURS (07:00 - 17:59)
            // ========================
            $peakHoursData = VisitorDetection::selectRaw('
                HOUR(locale_time) as hour, 
                COUNT(*) as visit_count,
                AVG(duration) as avg_duration
            ')
                ->whereBetween('locale_time', [
                    $start->copy()->setTime(7, 0),
                    $end->copy()->setTime(17, 59, 59),
                ])
                ->where('label', 'out')
                ->where('is_matched', 1)
                ->groupBy('hour')
                ->orderBy('hour')
                ->get()
                ->keyBy('hour');

            // Isi jam 7-17 dengan format array of objects
            $hours = range(7, 17);
            $hourlyVisits = [];
            $peakHourCount = 0;
            $peakHour = null;

            foreach ($hours as $h) {
                $hourData = $peakHoursData->get($h);

                $visitCount = $hourData ? $hourData->visit_count : 0;
                $avgDurationMinutes = $hourData && $hourData->avg_duration
                    ? round($hourData->avg_duration, 2)
                    : 0;

                // Tambahkan ke array
                $hourlyVisits[] = [
                    'hour' => $h,
                    'visitor' => $visitCount,
                    'length_of_visit' => $avgDurationMinutes,
                ];

                // Track peak hour
                if ($visitCount > $peakHourCount) {
                    $peakHourCount = $visitCount;
                    $peakHour = $h;
                }
            }

            $peakHourFormatted = $peakHour ? sprintf('%02d:00', $peakHour) : null;

            // ========================
            // GENDER STATISTICS
            // ========================
            $maleCount = $visitors->where('face_sex', 'Man')->count();
            $femaleCount = $visitors->where('face_sex', 'Woman')->count();

            $malePercent = $totalAll > 0 ? round(($maleCount / $totalAll) * 100, 2) : 0;
            $femalePercent = $totalAll > 0 ? round(($femaleCount / $totalAll) * 100, 2) : 0;

            // ========================
            // AGE DISTRIBUTION
            // ========================
            $ageCategories = [
                '0-17' => $visitors->whereBetween('face_age', [0, 17])->count(),
                '18-25' => $visitors->whereBetween('face_age', [18, 25])->count(),
                '26-40' => $visitors->whereBetween('face_age', [26, 40])->count(),
                '41-60' => $visitors->whereBetween('face_age', [41, 60])->count(),
                '61+' => $visitors->where('face_age', '>=', 61)->count(),
            ];

            $agePercentages = [];
            foreach ($ageCategories as $range => $count) {
                $agePercentages[$range] = $totalAll > 0 ? round(($count / $totalAll) * 100, 2) : 0;
            }

            // ========================
            // SUSUN RESPONSE
            // ========================
            $reportData = [
                'summary' => [
                    'total_visitors' => $totalAll,
                    'visitor_in' => $totalIn,
                    'visitor_out' => $totalOut,
                    'visitor_inside' => $visitorInside,
                    'matched_visitors' => $matchedVisitors->count(),
                ],
                'duration_statistics' => [
                    'total_hours' => round($totalHours, 2),
                    'total_minutes' => round($totalMinutes, 2),
                    'average_duration_minutes' => $lengthOfVisit,
                    'average_duration_hours' => $lengthOfVisitHours,
                ],
                'peak_hours' => [
                    'busiest_hour' => $peakHourFormatted,
                    'busiest_hour_count' => $peakHourCount,
                    'hourly_visits' => $hourlyVisits,
                ],
                'gender_distribution' => [
                    'male' => [
                        'count' => $maleCount,
                        'percent' => $malePercent,
                    ],
                    'female' => [
                        'count' => $femaleCount,
                        'percent' => $femalePercent,
                    ],
                ],
                'age_distribution' => [
                    'counts' => $ageCategories,
                    'percentages' => $agePercentages,
                ],
                'filter_info' => [
                    'time_range' => $timeLabel,
                    'start_date' => $start->toDateTimeString(),
                    'end_date' => $end->toDateTimeString(),
                ],
            ];

            return $this->responseSuccess($reportData, 'Report Fetched Successfully!');
        } catch (\Exception $err) {
            return $this->responseError(
                null,
                $err->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function getQueues(Request $request): JsonResponse
    {
        $query = VisitorQueue::query();
        $query->where('is_registered', true);
        $query->where('is_matched', false);
        $query->whereNull('rec_no_in');
        $query->whereNotNull('face_token');

        if ($request->filled('label')) {
            if ($request->query('label') === 'in') {
                $query->visitorIn();
            } elseif ($request->query('label') === 'out') {
                $query->visitorOut();
            }
        }

        // Filter search
        if ($request->filled('search')) {
            $search = $request->query('search');
            $searchBy = $request->query('search_by');

            if (!empty($searchBy) && in_array($searchBy, $this->searchableColumns)) {
                $query->where($searchBy, 'like', "%{$search}%");
            } else {
                $query->where(function ($q) use ($search) {
                    foreach ($this->searchableColumns as $col) {
                        $q->orWhere($col, 'like', "%{$search}%");
                    }
                });
            }
        }

        // Filter waktu
        $now = Carbon::now();
        if ($request->has('start_time') && $request->has('end_time')) {
            $start = Carbon::parse($request->query('start_time'))->startOfSecond();
            $end = Carbon::parse($request->query('end_time'))->endOfSecond();
            $query->whereBetween('locale_time', [$start, $end]);
        } elseif ($request->filled('time')) {
            switch ($request->query('time')) {
                case 'today':
                    $query->today();
                    break;
                case 'week':
                    $query->thisWeek();
                    break;
                case 'month':
                    $query->thisMonth();
                    break;
                case 'year':
                    $query->thisYear();
                    break;
            }
        }

        // Sorting
        if ($request->filled('sort_by')) {
            $sortDir = $request->query('sort_dir', 'asc');
            $query->orderBy($request->query('sort_by'), $sortDir);
        } else {
            $query->latest();
        }

        if ($request->query('sum') === 'count_data') {
            $count = (clone $query)->count();
            return $this->responseSuccess(['count' => $count], 'Visitors Counted Successfully!');
        }

        $perPage = $request->query('per_page', 15);
        $queues = $query->paginate($perPage);

        return $this->responseSuccess($queues, 'Visitor Queues Fetched Successfully!');
    }

    public function getMatch(string $id): JsonResponse
    {
        $visitorOut = VisitorDetection::find($id);
        $visitorIn = $visitorOut->visitorIn;

        if (is_null($visitorIn)) {
            return $this->responseError('Visitor In Not Found!', 'Visitor Match Data Not Found!', 404);
        }

        return $this->responseSuccess($visitorOut, 'Matched Data Fetched Successfully!');
    }

    public function getMatchedData(Request $request): JsonResponse
    {
        $query = VisitorDetection::query();

        $query = $query->matched();

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
                    $query->today();
                    break;
                case 'week':
                    $query->thisWeek();
                    break;
                case 'month':
                    $query->thisMonth();
                    break;
                case 'year':
                    $query->thisYear();
                    break;
            }
        }

        if ($request->query('sum') === 'count_data') {
            $count = (clone $query)->count();
            return $this->responseSuccess(['count' => $count], 'Matched Visitors Counted Successfully!');
        }

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
            $in = $out->visitorIn;
            $result[] = $out;
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

    public function revert(string $id): JsonResponse
    {
        $visitor = Visitor::findOrFail($id);

        try {
            // revert action
            $visitor->update($this->revertColumns);

            return $this->responseSuccess(null, 'Visitor Reverted Successfully!');
        } catch (Exception $err) {
            return $this->responseError($err->getMessage(), 'Visitor Revert Failed!', 500);
        }
    }

    public function revertMatchedData(string $id): JsonResponse
    {
        $visitor_out = Visitor::findOrFail($id);

        try {
            if (
                $visitor_out->label === 'out' &&
                !is_null($visitor_out->rec_no_in) &&
                $visitor_out->is_matched == true &&
                !is_null($visitor_out->face_token)
            ) {
                $visitor_in = $visitor_out->visitorIn;

                if (!$visitor_in) {
                    return $this->responseError(
                        'Visitor In Not Found!',
                        'Visitor Match Data Revert Failed!',
                        500
                    );
                }

                // revert
                $visitor_out->update($this->revertColumns);
                $visitor_in->update($this->revertColumns);

                return $this->responseSuccess(null, 'Visitor Match Data Reverted Successfully!');
            }

            // kondisi utama tidak terpenuhi
            return $this->responseError(
                'Visitor Out Data Not Valid!',
                'Visitor Match Data Revert Failed!',
                500
            );
        } catch (Exception $err) {
            return $this->responseError(
                $err->getMessage(),
                'Visitor Match Data Revert Failed!',
                500
            );
        }
    }
}
