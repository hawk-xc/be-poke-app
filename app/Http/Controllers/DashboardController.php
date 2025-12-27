<?php

namespace App\Http\Controllers;

use Exception;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\VisitorDetection;
use Illuminate\Support\Facades\Log;
use App\Repositories\AuthRepository;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ResponseTrait;

    protected $timezone = 'Asia/Jakarta';

    protected AuthRepository $authRepository;

    public function __construct(AuthRepository $ar)
    {
        $this->middleware(['permission:visitor:list'])->only(['index', 'show']);
        $this->middleware(['permission:visitor:create'])->only('store');
        $this->middleware(['permission:visitor:edit'])->only('update');
        $this->middleware(['permission:visitor:delete'])->only(['destroy', 'restore', 'forceDelete']);
        
        $this->authRepository = $ar;
    }

    public function index(Request $request)
    {
        $timezone = $this->timezone;
        $start = now($timezone)->startOfDay();
        $end = now($timezone)->endOfDay();
        $timeLabel = 'today'; // default

        try {
            if ($request->filled('time')) {
                switch ($request->time) {
                    case 'week':
                        $start = now($timezone)->startOfWeek();
                        $end = now($timezone)->endOfWeek();
                        $timeLabel = 'week';
                        break;
                    case 'month':
                        $start = now($timezone)->startOfMonth();
                        $end = now($timezone)->endOfMonth();
                        $timeLabel = 'month';
                        break;
                    case 'year':
                        $start = now($timezone)->startOfYear();
                        $end = now($timezone)->endOfYear();
                        $timeLabel = 'year';
                        break;
                    default:
                        $start = now($timezone)->startOfDay();
                        $end = now($timezone)->endOfDay();
                        $timeLabel = 'today';
                        break;
                }
            }

            if ($request->filled('start_date') && $request->filled('end_date')) {
                try {
                    $start = Carbon::parse($request->start_date, $timezone)->setTime(0, 1, 0);
                    $end = Carbon::parse($request->end_date, $timezone)->setTime(23, 59, 0);
                    $timeLabel = 'custom';
                } catch (\Exception $e) {
                    return $this->responseError('Format waktu tidak valid. Gunakan format YYYY-MM-DD HH:mm:ss', 422);
                }
            }

            $startStr = $start->toDateTimeString();
            $endStr = $end->toDateTimeString();

            $totalCounts = VisitorDetection::selectRaw("
                COUNT(*) as total_all,
                COUNT(CASE WHEN label = 'in' THEN 1 END) as total_in,
                COUNT(CASE WHEN label = 'out' THEN 1 END) as total_out
            ")
                ->whereRaw("locale_time::timestamp BETWEEN ?::timestamp AND ?::timestamp", [$startStr, $endStr])
                ->where('is_duplicate', false)
                ->first();

            $totalIn = $totalCounts->total_in ?? 0;
            $totalOut = $totalCounts->total_out ?? 0;
            $totalAll = $totalCounts->total_all ?? 0;

            $genderCounts = VisitorDetection::selectRaw("
                COUNT(CASE WHEN face_sex = 'Man' THEN 1 END) as male_count,
                COUNT(CASE WHEN face_sex = 'Woman' THEN 1 END) as female_count,
                COUNT(*) as total_in_all
            ")
                ->where('label', 'in')
                ->whereRaw("locale_time::timestamp BETWEEN ?::timestamp AND ?::timestamp", [$startStr, $endStr])
                ->where('is_duplicate', false)
                ->first();

            $maleCount = $genderCounts->male_count ?? 0;
            $femaleCount = $genderCounts->female_count ?? 0;
            $totalInAll = $genderCounts->total_in_all ?? 0;

            $malePercent = $totalInAll > 0 ? round(($maleCount / $totalInAll) * 100, 2) : 0;
            $femalePercent = $totalInAll > 0 ? round(($femaleCount / $totalInAll) * 100, 2) : 0;

            // OPTIMIZED: Age distribution langsung di database
            $ageCounts = VisitorDetection::selectRaw("
                COUNT(CASE WHEN face_age BETWEEN 0 AND 17 THEN 1 END) as age_0_17,
                COUNT(CASE WHEN face_age BETWEEN 18 AND 25 THEN 1 END) as age_18_25,
                COUNT(CASE WHEN face_age BETWEEN 26 AND 40 THEN 1 END) as age_26_40,
                COUNT(CASE WHEN face_age BETWEEN 41 AND 60 THEN 1 END) as age_41_60,
                COUNT(CASE WHEN face_age >= 61 THEN 1 END) as age_61_plus
            ")
                ->where('label', 'in')
                ->whereRaw("locale_time::timestamp BETWEEN ?::timestamp AND ?::timestamp", [$startStr, $endStr])
                ->where('is_duplicate', false)
                ->first();

            $ageCategories = [
                '0-17' => $ageCounts->age_0_17 ?? 0,
                '18-25' => $ageCounts->age_18_25 ?? 0,
                '26-40' => $ageCounts->age_26_40 ?? 0,
                '41-60' => $ageCounts->age_41_60 ?? 0,
                '61+' => $ageCounts->age_61_plus ?? 0,
            ];

            $agePercentages = [];
            foreach ($ageCategories as $range => $count) {
                $agePercentages[$range] = $totalInAll > 0 ? round(($count / $totalInAll) * 100, 2) : 0;
            }

            // RATA-RATA LAMA KUNJUNGAN - langsung di database
            $avgDuration = VisitorDetection::where('label', 'out')
                ->where('is_matched', true)
                ->whereRaw("locale_time::timestamp BETWEEN ?::timestamp AND ?::timestamp", [$startStr, $endStr])
                ->where('is_duplicate', false)
                ->avg('duration');

            $lengthOfVisit = $avgDuration ? round($avgDuration, 2) : 0;

            // PEAK HOURS (07.00 - 17.59)
            try {
                $startPeak = $start->copy()->setTime(7, 0)->toDateTimeString();
                $endPeak = $end->copy()->setTime(17, 59, 59)->toDateTimeString();

                $busyHoursData = VisitorDetection::selectRaw("
                    EXTRACT(HOUR FROM locale_time::timestamp)::integer as hour,
                    COUNT(*) as count
                ")
                    ->where('label', 'out')
                    ->whereRaw("locale_time::timestamp BETWEEN ?::timestamp AND ?::timestamp", [$startPeak, $endPeak])
                    ->groupByRaw("EXTRACT(HOUR FROM locale_time::timestamp)")
                    ->where('is_duplicate', false)
                    ->orderBy('hour')
                    ->get();

                $busyHours = [];
                foreach ($busyHoursData as $item) {
                    $busyHours[$item->hour] = $item->count;
                }

                Log::info('Busy Hours Query Success', ['count' => count($busyHours)]);
            } catch (\Exception $e) {
                Log::error('Error on Busy Hours Query', [
                    'message' => $e->getMessage(),
                    'line' => $e->getLine()
                ]);
                $busyHours = [];
            }

            $realTimeData = [
                'total' => [
                    'all' => $totalAll,
                    'visitor_inside' => $totalIn - $totalOut,
                    'visitor_in' => $totalIn,
                    'visitor_out' => $totalOut,
                    'length_of_visit' => $lengthOfVisit,
                ],
                'gender' => [
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
                'busy_hours' => $busyHours,
                'filter' => [
                    'start_time' => $start->toDateTimeString(),
                    'end_time' => $end->toDateTimeString(),
                ],
            ];

            Log::info('Dashboard Query Success', ['timeLabel' => $timeLabel]);

            return $this->responseSuccess(
                [
                    'realtime_data' => $realTimeData,
                    'time' => $timeLabel,
                ],
                'Dashboard Statistic Fetched Successfully!',
            );
        } catch (Exception $err) {
            Log::error('Error on Dashboard API', [
                'message' => $err->getMessage(),
                'line' => $err->getLine(),
                'file' => $err->getFile(),
                'trace' => $err->getTraceAsString()
            ]);

            return $this->responseError($err->getMessage(), 500);
        }
    }

    public function sidebar()
    {
        // sidebar list
        $pages = ['dashboard', 'visitor', 'raw_data', 'administrator', 'setting'];

        // permissions mapping
        $permissionPages = [
            'visitor:list' => ['dashboard', 'visitor', 'raw_data'],
            'roles:list' => ['administrator'],
        ];

        $user = auth()->user();

        if ($user === null) {
            return $this->responseError(null, 'User not found', 404);
        }

        $allowedPages = ['setting'];

        foreach ($permissionPages as $permission => $allowed) {
            if ($user->can($permission)) {
                $allowedPages = array_merge($allowedPages, $allowed);
            }
        }

        // hilangkan duplikasi
        $allowedPages = array_unique($allowedPages);

        // kirim ke view
        return $this->responseSuccess($allowedPages, 'Dashboard Sidebar Fetched Successfully!');
    }
}