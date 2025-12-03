<?php

namespace App\Http\Controllers\Visitor;

use Exception;
use Carbon\Carbon;
use App\Models\VisitorQueue;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use Illuminate\Http\Response;
use App\Models\VisitorDetection;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Repositories\AuthRepository;
use App\Models\VisitorDetection as Visitor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VisitorDetectionController extends Controller
{
    use ResponseTrait;

    /**
     * @var AuthRepository
     */
    protected AuthRepository $authRepository;

    protected $searchableColumns = ['id', 'label', 'rec_no', 'channel', 'code', 'action', 'class', 'event_type', 'name', 'locale_time', 'face_sex', 'object_action', 'object_sex', 'emotion', 'passerby_group_id', 'passerby_uid', 'person_uid', 'person_name', 'person_sex', 'person_group_name', 'person_group_type', 'person_pic_url', 'similarity', 'status'];

    protected $selectColumns = ['id', 'rec_no', 'rec_no_in', 'face_token', 'faceset_token', 'channel', 'class', 'event_type', 'locale_time', 'out_locale_time', 'utc', 'real_utc', 'glasses', 'mustache', 'gate_name', 'face_age', 'face_sex', 'emotion', 'person_id', 'embedding_id', 'duration', 'person_group_name', 'person_group_type', 'person_pic_url', 'similarity', 'status', 'deleted_at', 'created_at', 'updated_at', 'label', 'revert_by', 'is_registered', 'is_matched'];

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
        'revert_by' => 'human',
    ];

    protected $exportFileName;

    /**
     * AuthController constructor.
     */
    public function __construct(AuthRepository $ar)
    {
        // $this->middleware(['permission:visitor:list'])->only(['index', 'show', 'getReport', 'getQueues', 'getMatch', 'getMatchedData']);
        // $this->middleware(['permission:visitor:create'])->only('store');
        // $this->middleware(['permission:visitor:edit'])->only('update', 'revert', 'revertMatchedData');
        // $this->middleware(['permission:visitor:delete'])->only(['destroy', 'restore', 'forceDelete']);

        $this->exportFileName = "twc_visitors_" . now()->format('Ymd_His') . ".csv";

        $this->authRepository = $ar;
    }

    /**
     * Get all visitors.
     *
     * @query int per_page Number of items per page.
     * @query string sort_by Column name to sort by.
     * @query string sort_dir Sorting direction (asc/desc).
     * @query string search Search query.
     * @query string search_by Column name to search by.
     * @query string trashed Filter soft delete (with/only).
     * @query string data_status Filter by embedding & registered (with_embedding).
     * @query string label Filter by label (in/out).
     * @query string visitor_image Filter by visitor image (true/false).
     * @query string time Filter by time (today/week/month/year).
     * @query string match Get matched visitors (true/false).
     * @query string is_registered Filter by is registered (true/false).
     * @query string sum SUM / COUNT query.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'channel' => 'sometimes',
            'only_processed' => 'sometimes|boolean',
            'only_matched' => 'sometimes|boolean',
            'reverted_data' => 'sometimes|boolean',
            'face_status' => 'sometimes|boolean',
            'visitor_image' => 'sometimes|boolean',
            'data_status' => 'sometimes|in:with_embedding',
            'match' => 'sometimes|boolean',
            'is_registered' => 'sometimes|boolean',
            'trashed' => 'sometimes|in:with,only',
            'label' => 'sometimes|in:in,out',
            'sum' => 'sometimes|in:count_data',
            'time' => 'sometimes|in:today,week,month,year',
            'start_time' => 'sometimes|date_format:Y-m-d H:i:s',
            'end_time' => 'sometimes|date_format:Y-m-d H:i:s',
            'start_date' => 'sometimes|date_format:Y-m-d',
            'end_date' => 'sometimes|date_format:Y-m-d',
        ]);

        $timezone = config('app.timezone', 'Asia/Jakarta');

        $baseQuery = Visitor::query();
        $baseQuery->select($this->selectColumns);
        $filterableColumns = ['name', 'gender', 'phone_number', 'address', 'is_active'];

        // Filter by Processed Data
        if ($request->filled('only_processed')) {
            $baseQuery->whereNotNull('face_token')->where('is_registered', $request->query('only_processed') === 'true');
        }

        // Filter by Processed Data
        if ($request->filled('only_matched')) {
            $baseQuery->where('is_matched', $request->query('only_matched') === 'true');
        }

        // Filter embedding & registered
        if ($request->filled('data_status')) {
            $baseQuery->whereNotNull('embedding_id')->where('is_registered', $request->query('data_status') === 'with_embedding');
        }

        // Filter By Channel
        if ($request->filled('channel') && $request->query('channel') !== 'all') {
            $channels = $request->query('channel');
            if (is_array($channels)) {
                $baseQuery->whereIn('channel', $channels);
            } else {
                $baseQuery->where('channel', $channels);
            }
        }

        // Filter By Reverted Data
        if ($request->filled('reverted_data')) {
            $request->query('reverted_data') ? $baseQuery->whereNotNull('revert_by') : $baseQuery->whereNull('revert_by');
        }

        // Filter By Face Status Data
        if ($request->filled('face_status')) {
            $baseQuery->where('status', $request->query('face_status') === 'true');
        }

        // Filter soft delete
        if ($request->filled('trashed')) {
            $request->query('trashed') === 'with' ? $baseQuery->withTrashed() : $baseQuery->onlyTrashed();
        }

        if ($request->filled('label')) {
            $request->query('label') === 'in' ? $baseQuery->visitorIn() : $baseQuery->visitorOut();
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

        if ($request->filled('visitor_image')) {
            $request->query('visitor_image') ? $baseQuery->whereNotNull('person_pic_url') : $baseQuery->whereNull('person_pic_url');
        }

        foreach ($filterableColumns as $column) {
            if ($request->filled($column)) {
                $baseQuery->where($column, $request->query($column));
            }
        }

        if ($request->filled('start_time') && $request->filled('end_time')) {
            // $start = Carbon::parse($request->query('start_time'))->setTime(0, 1, 0);
            // $end = Carbon::parse($request->query('end_time'))->setTime(23, 59, 0);

            $start = Carbon::createFromFormat('Y-m-d H:i:s', $request->query('start_time'), $timezone);
                $end = Carbon::createFromFormat('Y-m-d H:i:s', $request->query('end_time'), $timezone);

            $baseQuery->whereBetween('locale_time', [$start, $end]);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            try {
                $start = Carbon::parse($request->start_date, $timezone)->startOfSecond();
                $end = Carbon::parse($request->end_date, $timezone)->endOfSecond();
                $timeLabel = 'custom';
            } catch (\Exception $e) {
                return $this->responseError('Format waktu tidak valid. Gunakan format YYYY-MM-DD HH:mm:ss', 'Invalid Date Format', 422);
            }
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

        if ($request->filled('sum') && $request->query('sum') === 'count_data') {
            $count = (clone $baseQuery)->count();
            return $this->responseSuccess(['count' => $count], 'Visitors Counted Successfully!');
        }

        if ($request->filled('match') && $request->query('match') === 'true') {
            $queryIn = (clone $baseQuery)->where('is_matched', true)->where('is_registered', true)->where('label', 'in');

            $queryOut = (clone $baseQuery)->where('is_matched', true)->where('is_registered', true)->where('label', 'out');

            // Pagination
            $perPage = $request->query('per_page', 15);
            $dataIn = $queryIn->paginate($perPage, ['*'], 'in_page');
            $dataOut = $queryOut->paginate($perPage, ['*'], 'out_page');

            return $this->responseSuccess(
                [
                    'data_in' => $dataIn,
                    'data_out' => $dataOut,
                ],
                'Matched Visitors (IN/OUT) Fetched Successfully!',
            );
        }

        if ($request->filled('is_registered') && $request->query('is_registered') === 'true') {
            $baseQuery->where('is_registered', true)->whereNotNull('embedding_id');
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
     *
     * @param \Illuminate\Http\Request $request
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *
     * @bodyParam name string required. The name of the visitor. Max length is 255 characters.
     * @bodyParam gender string required. The gender of the visitor. Must be either Male or Female.
     * @bodyParam phone_number string nullable. The phone number of the visitor. Max length is 20 characters.
     * @bodyParam address string nullable. The address of the visitor.
     * @bodyParam is_active boolean optional. Whether the visitor is active or not. Defaults to true.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *
     * @throws \Exception
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
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        $visitor = Visitor::withTrashed()->findOrFail($id);

        $visitor_data = [
            'current' => $visitor,
            'related' => [],
        ];

        if (!is_null($visitor->embedding_id)) {
            $relatedVisitors = Visitor::withTrashed()->where('embedding_id', $visitor->embedding_id)->where('id', '!=', $visitor->id)->orderBy('created_at', 'asc')->get();

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
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'gender' => 'sometimes|required|in:male,female',
            'age' => 'sometimes|integer',
        ]);

        $visitor = Visitor::withTrashed()->findOrFail($id);

        try {
            $visitor->face_sex = (string) $request->input('gender') == 'male' ? 'Man' : 'Woman';
            $visitor->face_age = (int) $request->input('age');

            $visitor->save();

            return $this->responseSuccess($visitor, 'Visitor Updated Successfully!');
        } catch (Exception $e) {
            return $this->responseError($e->getMessage(), 'Visitor Update Failed!', 500);
        }
    }

    /**
     * Soft delete the specified visitor.
     *
     * @param string $id The ID of the visitor to be deleted.
     *
     * @return JsonResponse
     */
    public function destroy(string $id): JsonResponse
    {
        $visitor = Visitor::findOrFail($id);

        $visitor->delete();
        return $this->responseSuccess(null, 'Visitor Soft Deleted Successfully!');
    }

    /**
     * Restore the specified soft-deleted visitor.
     *
     * @param string $id The ID of the visitor to restore.
     * @return JsonResponse The restored visitor with a success response.
     * @throws Exception If the visitor is not found.
     */
    public function restore(string $id): JsonResponse
    {
        $visitor = Visitor::onlyTrashed()->findOrFail($id);

        $visitor->restore();
        return $this->responseSuccess($visitor, 'Visitor Restored Successfully!');
    }

    /**
     * Permanently remove the specified resource from storage.
     *
     * @param string $id
     * @return JsonResponse
     */
    public function forceDelete(string $id): JsonResponse
    {
        $visitor = Visitor::onlyTrashed()->findOrFail($id);

        $visitor->forceDelete();
        return $this->responseSuccess(null, 'Visitor Permanently Deleted Successfully!');
    }

    /**
     * Get a report of visitor statistics based on the given time range.
     *
     * Time range presets:
     * - today: Today's date
     * - week: This week's date
     * - month: This month's date
     * - year: This year's date
     *
     * You can also specify a custom date range by providing 'start_date' and 'end_date' parameters.
     * The date format should be in 'YYYY-MM-DD HH:mm:ss' format.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getReport(Request $request): JsonResponse
    {
        $request->validate([
            'time' => 'sometimes|in:today,week,month,year',
            'start_time' => 'sometimes|date_format:Y-m-d H:i:s',
            'end_time' => 'sometimes|date_format:Y-m-d H:i:s',
            'start_date' => 'sometimes|date_format:Y-m-d',
            'end_date' => 'sometimes|date_format:Y-m-d',
        ]);
        try {
            $timezone = config('app.timezone', 'Asia/Jakarta');
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

            if ($request->filled('start_time') && $request->filled('end_time')) {
                try {
                    $start = Carbon::createFromFormat('Y-m-d H:i:s', $request->query('start_time'), $timezone);
                    $end = Carbon::createFromFormat('Y-m-d H:i:s', $request->query('end_time'), $timezone);
                    $timeLabel = 'custom';
                } catch (\Exception $e) {
                    return $this->responseError('Format waktu tidak valid. Gunakan format YYYY-MM-DD HH:mm:ss', 'Invalid Date Format', 422);
                }
            }

            if ($request->filled('start_date') && $request->filled('end_date')) {
                try {
                    $start = Carbon::parse($request->start_date, $timezone)->startOfSecond();
                    $end = Carbon::parse($request->end_date, $timezone)->endOfSecond();
                    $timeLabel = 'custom';
                } catch (\Exception $e) {
                    return $this->responseError('Format waktu tidak valid. Gunakan format YYYY-MM-DD HH:mm:ss', 'Invalid Date Format', 422);
                }
            }

            $baseQuery = VisitorDetection::whereBetween('locale_time', [$start, $end])->matched();

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

            $visitors = $baseQuery->get();

            $totalIn = $visitors->where('label', 'in')->count();
            $totalOut = $visitors->where('label', 'out')->count();
            $totalAll = $visitors->count();
            $visitorInside = $totalIn - $totalOut;

            $matchedVisitors = $visitors->where('label', 'out')->where('is_matched', 1)->whereNotNull('duration');

            $totalHours = $matchedVisitors->sum('duration') / 60;
            $totalMinutes = $matchedVisitors->sum('duration');

            $avgDuration = $matchedVisitors->avg('duration');
            $lengthOfVisit = $avgDuration ? round($avgDuration, 2) : 0;
            $lengthOfVisitHours = $avgDuration ? round($avgDuration / 60, 2) : 0;

            $peakHoursData = VisitorDetection::selectRaw(
                '
                HOUR(locale_time) as hour,
                COUNT(*) as visit_count,
                AVG(duration) as avg_duration
            ',
            )
                ->whereBetween('locale_time', [$start->copy()->setTime(7, 0), $end->copy()->setTime(17, 59, 59)])
                ->where('label', 'out')
                ->where('is_matched', 1)
                ->groupBy('hour')
                ->orderBy('hour')
                ->get()
                ->keyBy('hour');

            $hours = range(7, 17);
            $hourlyVisits = [];
            $peakHourCount = 0;
            $peakHour = null;

            foreach ($hours as $h) {
                $hourData = $peakHoursData->get($h);

                $visitCount = $hourData ? $hourData->visit_count : 0;
                $avgDurationMinutes = $hourData && $hourData->avg_duration ? round($hourData->avg_duration, 2) : 0;

                $start_hour = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';
                $end_hour = str_pad($h + 1, 2, '0', STR_PAD_LEFT) . ':00';

                $hourlyVisits[] = [
                    'hour' => "$start_hour - $end_hour",
                    'visitor' => $visitCount,
                    'length_of_visit' => $avgDurationMinutes,
                ];

                // Peak Hour
                if ($visitCount > $peakHourCount) {
                    $peakHourCount = $visitCount;
                    $peakHour = "$start_hour - $end_hour";
                }
            }

            $peakHourFormatted = $peakHour ? sprintf('%02d:00', $peakHour) : null;

            $maleCount = $visitors->where('face_sex', 'Man')->count();
            $femaleCount = $visitors->where('face_sex', 'Woman')->count();

            $malePercent = $totalAll > 0 ? round(($maleCount / $totalAll) * 100, 2) : 0;
            $femalePercent = $totalAll > 0 ? round(($femaleCount / $totalAll) * 100, 2) : 0;

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
            return $this->responseError(null, $err->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get Visitor Queues (Unmatched Visitors)
     *
     * @queryParam label string Filter by label (in/out)
     * @queryParam search string Search by face_token, name, phone_number, address, person_group, sex
     * @queryParam search_by string Search by column (face_token, name, phone_number, address, person_group, sex)
     * @queryParam start_time string Filter by start time (format: Y-m-d H:i:s)
     * @queryParam end_time string Filter by end time (format: Y-m-d H:i:s)
     * @queryParam time string Filter by time (today, week, month, year)
     * @queryParam sort_by string Sort by column (id, face_token, name, phone_number, address, person_group, sex, locale_time)
     * @queryParam sort_dir string Sort direction (asc/desc)
     * @queryParam per_page int Number of items to return per page
     * @queryParam sum string Count data if given (count_data)
     *
     * @return \Illuminate\Http\JsonResponse
     */
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

    /**
     * Get the matched data for a visitor detection record
     *
     * @param string $id The record ID to fetch the matched data for
     *
     * @return JsonResponse The matched data with a success response
     *
     * @throws JsonResponse If the visitor in record is not found
     */
    public function getMatch(string $id): JsonResponse
    {
        $visitorOut = VisitorDetection::find($id);
        $visitorIn = $visitorOut->visitorIn ?? null;

        if (is_null($visitorIn)) {
            return $this->responseError('Visitor In Not Found!', 'Visitor Match Data Not Found!', 404);
        }

        return $this->responseSuccess($visitorOut, 'Matched Data Fetched Successfully!');
    }

    /**
     * Get the matched data for a visitor detection record
     *
     * @queryParam search string Search by face_token, name, phone_number, address, person_group, sex
     * @queryParam search_by string Search by column (face_token, name, phone_number, address, person_group, sex)
     * @queryParam start_time string Filter by start time (format: Y-m-d H:i:s)
     * @queryParam end_time string Filter by end time (format: Y-m-d H:i:s)
     * @queryParam time string Filter by time (today, week, month, year)
     * @queryParam sort_by string Sort by column (id, face_token, name, phone_number, address, person_group, sex, locale_time)
     * @queryParam sort_dir string Sort direction (asc/desc)
     * @queryParam per_page int Number of items to return per page
     * @queryParam sum string Count data if given (count_data)
     *
     * @return JsonResponse The matched data with a success response
     */
    // public function getMatchedData(Request $request): JsonResponse|StreamedResponse
    // {
    //     $request->validate([
    //         'search' => 'sometimes|string',
    //         'start_time' => 'sometimes|date_format:Y-m-d H:i:s',
    //         'end_time' => 'sometimes|date_format:Y-m-d H:i:s',
    //         'start_date' => 'sometimes|date_format:Y-m-d',
    //         'end_date' => 'sometimes|date_format:Y-m-d',
    //         'time' => 'sometimes|in:today,week,month,year',
    //         'export' => 'sometimes|boolean',
    //         'sum' => 'sometimes|in:count_data',
    //     ]);

    //     $query = VisitorDetection::query()->matched();
    //     $timezone = conf    ig('app.timezone', 'Asia/Jakarta');

    //     if ($request->filled('search')) {
    //         $search = $request->query('search');
    //         $searchBy = $request->query('search_by');
    //         $searchableColumns = ['name', 'event_type', 'gate_name', 'face_sex', 'emotion'];
    //         if (!empty($searchBy) && in_array($searchBy, $searchableColumns)) {
    //             $query->where($searchBy, 'like', "%{$search}%");
    //         } else {
    //             $query->where(function ($q) use ($search, $searchableColumns) {
    //                 foreach ($searchableColumns as $col) {
    //                     $q->orWhere($col, 'like', "%{$search}%");
    //                 }
    //             });
    //         }
    //     }

    //     $filterableColumns = ['name', 'face_sex', 'gate_name', 'event_type', 'emotion'];
    //     foreach ($filterableColumns as $column) {
    //         if ($request->filled($column)) {
    //             $query->where($column, $request->query($column));
    //         }
    //     }

    //     if ($request->filled('start_time') && $request->filled('end_time')) {
    //         try {
    //             $start = Carbon::createFromFormat('Y-m-d H:i:s', $request->query('start_time'), $timezone);
    //             $end = Carbon::createFromFormat('Y-m-d H:i:s', $request->query('end_time'), $timezone);
    //             $query->whereBetween('locale_time', [$start, $end]);
    //             $timeLabel = 'custom';
    //         } catch (\Exception $e) {
    //             return $this->responseError('Format waktu tidak valid. Gunakan format YYYY-MM-DD HH:mm:ss', 'Invalid Date Format', 422);
    //         }
    //     }

    //     if ($request->has('start_date') && $request->has('end_date')) {
    //         $start = Carbon::parse($request->start_date, $timezone)->startOfSecond();
    //         $end = Carbon::parse($request->end_date, $timezone)->endOfSecond();
    //         $timeLabel = 'custom';
    //         $query->whereBetween('locale_time', [$start, $end]);
    //     } elseif ($request->filled('time')) {
    //         switch ($request->query('time')) {
    //             case 'today':
    //                 $query->today();
    //                 $start = now($timezone)->startOfDay();
    //                 $end = now($timezone)->endOfDay();
    //                 $timeLabel = 'today';
    //                 break;
    //             case 'week':
    //                 $query->thisWeek();
    //                 $start = now($timezone)->startOfWeek();
    //                 $end = now($timezone)->endOfWeek();
    //                 $timeLabel = 'week';
    //                 break;
    //             case 'month':
    //                 $query->thisMonth();
    //                 $start = now($timezone)->startOfMonth();
    //                 $end = now($timezone)->endOfMonth();
    //                 $timeLabel = 'month';
    //                 break;
    //             case 'year':
    //                 $query->thisYear();
    //                 $start = now($timezone)->startOfYear();
    //                 $end = now($timezone)->endOfYear();
    //                 $timeLabel = 'year';
    //                 break;
    //         }
    //     }

    //     if ($request->query('sum') === 'count_data') {
    //         $count = (clone $query)->count();
    //         return $this->responseSuccess(['count' => $count], 'Matched Visitors Counted Successfully!');
    //     }

    //     if ($request->boolean('export') === true) {
    //         return exportMatchedCSV(clone $query);
    //     }

    //     if ($request->filled('sort_by')) {
    //         $sortDir = $request->query('sort_dir', 'asc');
    //         $query->orderBy($request->query('sort_by'), $sortDir);
    //     } else {
    //         $query->latest();
    //     }

    //     $perPage = $request->query('per_page', 15);
    //     $dataOut = $query->paginate($perPage);

    //     $result = [];
    //     foreach ($dataOut as $out) {
    //         $result[] = $out;
    //     }

    //     return $this->responseSuccess(
    //         [
    //             'data' => $result,
    //             'pagination' => [
    //                 'current_page' => $dataOut->currentPage(),
    //                 'last_page' => $dataOut->lastPage(),
    //                 'per_page' => $dataOut->perPage(),
    //                 'total' => $dataOut->total(),
    //             ],
    //             'filter_info' => [
    //                 'time_range' => $timeLabel ?? 'all',
    //                 'start_date' => $start ? $start->toDateTimeString() : 'all',
    //                 'end_date' => $end ? $end->toDateTimeString() : 'now',
    //             ],
    //         ],
    //         'Matched Visitor IN/OUT Data Fetched Successfully!',
    //     );
    // }

    public function getMatchedData(Request $request): JsonResponse|StreamedResponse
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
            $start = Carbon::parse($request->query('start_time'))->setTime(0, 1, 0);
            $end = Carbon::parse($request->query('end_time'))->setTime(23, 59, 0);

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

        if ($request->boolean('export') === true) {
            return exportMatchedCSV(clone $query);
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
            $in = $out->visitorIn->orderBy('locale_time', 'desc');
            $result[] = $out;
        }

        return $this->responseSuccess(
            [
                'data' => $result,
                'pagination' => [
                    'current_page' => $dataOut->currentPage(),
                    'last_page' => $dataOut->lastPage(),
                    'per_page' => $dataOut->perPage(),
                    'total' => $dataOut->total(),
                ],
            ],
            'Matched Visitor IN/OUT Data Fetched Successfully!',
        );
    }

    /**
     * Revert the specified visitor.
     *
     * @param string $id The ID of the visitor.
     *
     * @return JsonResponse
     *
     * @throws Exception
     */
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

    /**
     * Revert the matched visitor data.
     *
     * This function will revert the matched visitor data.
     * The matched visitor data is the data that has the same
     * rec_no_in and rec_no_out and has the is_matched flag set to true.
     *
     * @param string $id The ID of the visitor.
     *
     * @return JsonResponse
     *
     * @throws Exception
     */
    public function revertMatchedData(string $id): JsonResponse
    {
        $visitor_out = Visitor::findOrFail($id);

        try {
            if ($visitor_out->label === 'out' && !is_null($visitor_out->rec_no_in) && $visitor_out->is_matched == true && !is_null($visitor_out->face_token)) {
                $visitor_in = $visitor_out->visitorIn;

                if (!$visitor_in) {
                    return $this->responseError('Visitor In Not Found!', 'Visitor Match Data Revert Failed!', 500);
                }

                // revert
                $visitor_out->update($this->revertColumns);
                $visitor_in->update($this->revertColumns);

                return $this->responseSuccess(null, 'Visitor Match Data Reverted Successfully!');
            }

            // kondisi utama tidak terpenuhi
            return $this->responseError('Visitor Out Data Not Valid!', 'Visitor Match Data Revert Failed!', 500);
        } catch (Exception $err) {
            return $this->responseError($err->getMessage(), 'Visitor Match Data Revert Failed!', 500);
        }
    }

    /**
     * Get visitor statistics data.
     *
     * This function will fetch visitor statistics data based on the given filters.
     *
     * Available filters:
     * - channel: Filter by channel (string or array of strings).
     * - start_time: Filter by start time (format: Y-m-d H:i:s).
     * - end_time: Filter by end time (format: Y-m-d H:i:s).
     * - start_date: Filter by start date (format: Y-m-d).
     * - end_date: Filter by end date (format: Y-m-d).
     * - time: Filter by time (today/week/month/year).
     *
     * The response will contain the following statistics:
     * - no_face_detected: The number of visitors without face detection.
     * - registered: The number of registered visitors.
     * - matched: The number of matched visitors.
     * - reverted: The number of reverted visitors.
     * - filter_info: An array containing information about the filters used.
     *
     * @return JsonResponse The visitor statistics data with a success response.
     */
    public function getStatisticData(Request $request): JsonResponse
    {
        $request->validate([
            'channel' => 'sometimes',
            'time' => 'sometimes|in:today,week,month,year',
            'start_time' => 'sometimes|date_format:Y-m-d H:i:s',
            'end_time' => 'sometimes|date_format:Y-m-d H:i:s',
            'start_date' => 'sometimes|date_format:Y-m-d',
            'end_date' => 'sometimes|date_format:Y-m-d',
        ]);

        $baseQuery = Visitor::query();
        $timezone = config('app.timezone', 'Asia/Jakarta');
        $start = null;
        $end = null;
        $timeLabel = null;

        // Filter by channel
        if ($request->filled('channel') && $request->query('channel') !== 'all') {
            $channels = $request->query('channel');
            if (is_array($channels)) {
                $baseQuery->whereIn('channel', $channels);
            } else {
                $baseQuery->where('channel', $channels);
            }
        }

        if ($request->filled('start_time') && $request->filled('end_time')) {
            try {
                $start = Carbon::createFromFormat('Y-m-d H:i:s', $request->query('start_time'), $timezone);
                $end = Carbon::createFromFormat('Y-m-d H:i:s', $request->query('end_time'), $timezone);
                $baseQuery->whereBetween('locale_time', [$start, $end]);
                $timeLabel = 'custom';
            } catch (\Exception $e) {
                return $this->responseError('Format waktu tidak valid. Gunakan format YYYY-MM-DD HH:mm:ss', 'Invalid Date Format', 422);
            }
        }

        // Filter by date and date
        if ($request->has('start_date') && $request->has('end_date')) {
            $start = Carbon::parse($request->start_date, $timezone)->startOfSecond();
            $end = Carbon::parse($request->end_date, $timezone)->endOfSecond();
            $timeLabel = 'custom';
            $baseQuery->whereBetween('locale_time', [$start, $end]);
        } elseif ($request->filled('time')) {
            switch ($request->query('time')) {
                case 'today':
                    $baseQuery->today();
                    $start = now($timezone)->startOfDay();
                    $end = now($timezone)->endOfDay();
                    $timeLabel = 'today';
                    break;
                case 'week':
                    $baseQuery->thisWeek();
                    $start = now($timezone)->startOfWeek();
                    $end = now($timezone)->endOfWeek();
                    $timeLabel = 'week';
                    break;
                case 'month':
                    $baseQuery->thisMonth();
                    $start = now($timezone)->startOfMonth();
                    $end = now($timezone)->endOfMonth();
                    $timeLabel = 'month';
                    break;
                case 'year':
                    $baseQuery->thisYear();
                    $start = now($timezone)->startOfYear();
                    $end = now($timezone)->endOfYear();
                    $timeLabel = 'year';
                    break;
            }
        }

        // Get counts for each statistic
        $noFaceDetected = (clone $baseQuery)->where('status', false)->where('is_matched', false)->count();
        $registered = (clone $baseQuery)->where('is_registered', true)->count();
        $matched = (clone $baseQuery)->matched()->count();
        $reverted = (clone $baseQuery)->whereNotNull('revert_by')->count();

        $statistics = [
            'no_face_detected' => $noFaceDetected,
            'registered' => $registered,
            'matched' => $matched,
            'reverted' => $reverted,
            'filter_info' => [
                'channel' => $request->query('channel') ?? 'all',
                'time_range' => $timeLabel ?? 'all',
                'start_date' => $start ? $start->toDateTimeString() : 'all',
                'end_date' => $end ? $end->toDateTimeString() : 'now',
            ],
        ];

        return $this->responseSuccess($statistics, 'Visitor statistics fetched successfully!');
    }

    /**
     * Delete multiple visitors in one request.
     *
     * @param Request $request
     * @return JsonResponse
     *
     * @throws Exception
     *
     * @bodyParam ids required The IDs of the visitors to be deleted.
     * @bodyParam ids.* required integer The IDs of the visitors to be deleted.
     *
     * @response 200 {"message":"Bulk delete processed successfully!","data":{"requested_ids":[],"deleted_ids":[],"not_deleted_matched_ids":[],"not_found_ids":[],"deleted_count":0}}
     * @response 404 {"message":"Visitor not found","data":[]}
     */
    public function deleteBulk(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer'
        ]);

        if ($request->filled('ids')) {
            $visitors = Visitor::whereIn('id', $request->ids)->get();

            if ($visitors->isEmpty()) {
                return $this->responseError('Visitor not found', 'Visitor not found', 404);
            }

            try {
                $deletable = $visitors->where('is_matched', false)->pluck('id')->toArray();
                $notDeletableMatched = $visitors->where('is_matched', true)->pluck('id')->toArray();

                $foundIds = $visitors->pluck('id')->toArray();
                $notFound = array_values(array_diff($request->ids, $foundIds));

                $deletedCount = 0;

                if (!empty($deletable)) {
                    $deletedCount = Visitor::whereIn('id', $deletable)->delete();
                }

                return $this->responseSuccess([
                    'requested_ids' => $request->ids,
                    'deleted_ids' => $deletable,
                    'not_deleted_matched_ids' => $notDeletableMatched,
                    'not_found_ids' => $notFound,
                    'deleted_count' => $deletedCount
                ], 'Bulk delete processed successfully!');
            } catch (Exception $e) {
                sendTelegram('delete bulk error : ' . $e->getMessage());
                Log::error('delete bulk error : ' . $e->getMessage());
                return $this->responseError([], 'Visitor not found', 404);
            }
        }

        return $this->responseError([], 'Visitor not found');
    }
}
