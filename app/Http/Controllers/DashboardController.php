<?php

namespace App\Http\Controllers;

use Exception;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\VisitorDetection;
use Illuminate\Support\Facades\Log;
use App\Repositories\AuthRepository;

class DashboardController extends Controller
{
    use ResponseTrait;

    protected $timezone = 'Asia/Jakarta';

    protected AuthRepository $authRepository;

    public function __construct(AuthRepository $ar)
    {
        // $this->middleware(['permission:visitor:list'])->only(['index', 'show']);
        // $this->middleware(['permission:visitor:create'])->only('store');
        // $this->middleware(['permission:visitor:edit'])->only('update');
        // $this->middleware(['permission:visitor:delete'])->only(['destroy', 'restore', 'forceDelete']);

        $this->authRepository = $ar;
    }

    public function index(Request $request)
    {
        $timezone = $this->timezone;
        $start = now($timezone)->startOfDay();
        $end = now($timezone)->endOfDay();
        $timeLabel = 'daily'; // default

        try {
            if ($request->filled('time')) {
                switch ($request->time) {
                    case 'monthly':
                        $start = now($timezone)->startOfMonth();
                        $end = now($timezone)->endOfMonth();
                        $timeLabel = 'monthly';
                        break;
                    case 'yearly':
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

            if ($request->filled('start_date') && $request->filled('end_date')) {
                try {
                    $start = Carbon::parse($request->start_date, $timezone)->setTime(0, 1, 0);
                    $end   = Carbon::parse($request->end_date, $timezone)->setTime(23, 59, 0);

                    $timeLabel = 'custom';
                } catch (\Exception $e) {
                    return $this->responseError('Format waktu tidak valid. Gunakan format YYYY-MM-DD HH:mm:ss', 422);
                }
            }

            $realTimeQuery = VisitorDetection::whereBetween('locale_time', [$start, $end])->get();
            $visitorIn = $realTimeQuery->where('label', 'in')->get();

            $totalIn = $realTimeQuery->where('label', 'in')->count();
            $totalOut = $realTimeQuery->where('label', 'out')->count();
            $totalAll = $realTimeQuery->count();
            $totalInAll = $visitorIn->count();

            $maleCount = $visitorIn->where('face_sex', 'Man')->count();
            $femaleCount = $visitorIn->where('face_sex', 'Woman')->count();

            $malePercent = $totalInAll > 0 ? round(($maleCount / $totalInAll) * 100, 2) : 0;
            $femalePercent = $totalInAll > 0 ? round(($femaleCount / $totalInAll) * 100, 2) : 0;

            $ageCategories = [
                '0-17' => $visitorIn->whereBetween('face_age', [0, 17])->count(),
                '18-25' => $visitorIn->whereBetween('face_age', [18, 25])->count(),
                '26-40' => $visitorIn->whereBetween('face_age', [26, 40])->count(),
                '41-60' => $visitorIn->whereBetween('face_age', [41, 60])->count(),
                '61+' => $visitorIn->where('face_age', '>=', 61)->count(),
            ];

            $agePercentages = [];
            foreach ($ageCategories as $range => $count) {
                $agePercentages[$range] = $totalInAll > 0 ? round(($count / $totalInAll) * 100, 2) : 0;
            }

            // RATA-RATA LAMA KUNJUNGAN
            $lengthOfVisit = $realTimeQuery->where('label', 'out')->where('is_matched', 1)->avg('duration');
            $lengthOfVisit = $lengthOfVisit ? round($lengthOfVisit, 2) : 0;

            // PEAK HOURS (07.00 - 17.59)
            $busyHours = VisitorDetection::selectRaw('HOUR(locale_time) as hour, COUNT(*) as count')
                ->whereBetween('locale_time', [
                    $start->copy()->setTime(7, 0),
                    $end->copy()->setTime(17, 59, 59),
                ])
                ->where('label', 'out')
                ->groupBy('hour')
                ->orderBy('hour')
                ->pluck('count', 'hour')
                ->toArray();

            // Isi jam kosong dengan 0
            $hours = range(7, 17);
            $busyHours = collect($hours)->mapWithKeys(fn($h) => [$h => $busyHours[$h] ?? 0])->toArray();

            // =======================
            // SUSUN HASIL RESPONSE
            // =======================
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

            return $this->responseSuccess([
                'realtime_data' => $realTimeData,
                'time' => $timeLabel,
            ], 'Dashboard Statistic Fetched Successfully!');
        } catch (Exception $errr) {
            Log::info('Error on Dashboard API: ' . $errr->getMessage());

            return $this->responseError('Server Error on Dashboard API', 500);
        }
    }
}
