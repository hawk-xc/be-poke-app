<?php

namespace App\Http\Controllers;

use App\Models\VisitorDetection;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use App\Repositories\AuthRepository;
use Carbon\Carbon;

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
        $timezone = 'Asia/Jakarta'; // pastikan konsisten
        $start = now($timezone)->startOfDay();
        $end = now($timezone)->endOfDay();

        // Jika ada parameter time (daily, monthly, yearly)
        if ($request->filled('time')) {
            switch ($request->time) {
                case 'daily':
                    $start = now($timezone)->startOfDay();
                    $end = now($timezone)->endOfDay();
                    break;
                case 'monthly':
                    $start = now($timezone)->startOfMonth();
                    $end = now($timezone)->endOfMonth();
                    break;
                case 'yearly':
                    $start = now($timezone)->startOfYear();
                    $end = now($timezone)->endOfYear();
                    break;
                default:
                    $start = now($timezone)->startOfDay();
                    $end = now($timezone)->endOfDay();
                    break;
            }
        }

        // ambil semua data real-time
        $realTimeQuery = VisitorDetection::whereBetween('locale_time', [$start, $end])->get();

        $totalIn = $query->where('label', 'in')->count();
        $totalOut = $query->where('label', 'out')->count();
        $totalAll = $query->count();

        // Visitor inside (tidak boleh minus)
        $visitorInside = max(0, $totalIn - $totalOut);

        // GENDER
        $maleCount = $realTimeQuery->where('face_sex', 'Man')->count();
        $femaleCount = $realTimeQuery->where('face_sex', 'Woman')->count();

        $malePercent = $totalAll > 0 ? round(($maleCount / $totalAll) * 100, 2) : 0;
        $femalePercent = $totalAll > 0 ? round(($femaleCount / $totalAll) * 100, 2) : 0;

        // AGE DISTRIBUTION
        $ageCategories = [
            '0-17' => $query->whereBetween('face_age', [0, 17])->count(),
            '18-25' => $query->whereBetween('face_age', [18, 25])->count(),
            '26-40' => $query->whereBetween('face_age', [26, 40])->count(),
            '41-60' => $query->whereBetween('face_age', [41, 60])->count(),
            '61+' => $query->where('face_age', '>=', 61)->count(),
        ];

        $agePercentages = [];
        foreach ($ageCategories as $range => $count) {
            $agePercentages[$range] = $totalAll > 0 ? round(($count / $totalAll) * 100, 2) : 0;
        }

        // RATA-RATA LAMA KUNJUNGAN
        $lengthOfVisit = $realTimeQuery
            ->where('label', 'out')
            ->avg('duration');
        $lengthOfVisit = $lengthOfVisit ? round($lengthOfVisit, 2) : 0;

        // === PEAK HOUR FIX ===
        $busyHours = VisitorDetection::selectRaw('HOUR(locale_time) as hour, COUNT(*) as count')
            ->whereBetween('locale_time', [
                $start->copy()->setTime(7, 0),
                $end->copy()->setTime(17, 59, 59)
            ])
            ->where('label', 'in')
            ->groupBy('hour')
            ->orderBy('hour')
            ->pluck('count', 'hour')
            ->toArray();

        $hours = range(7, 17);
        $busyHours = collect($hours)->mapWithKeys(fn($h) => [$h => $busyHours[$h] ?? 0])->toArray();

        // =======================
        // SUSUN HASIL
        // =======================
        $realTimeData = [
            'total' => [
                'all' => $totalAll,
                'visitor_inside' => $visitorInside,
                'visitor_in' => $totalIn,
                'visitor_out' => $totalOut,
                'length_of_visit' => $lengthOfVisit,
            ],
            'gender' => [
                'male' => ['count' => $maleCount, 'percent' => $malePercent],
                'female' => ['count' => $femaleCount, 'percent' => $femalePercent],
            ],
            'age_distribution' => [
                'counts' => $ageCategories,
                'percentages' => $agePercentages,
            ],
            'busy_hours' => $busyHours,
            'filter' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ],
        ];

        return $this->responseSuccess([
            'realtime_data' => $realTimeData,
        ], 'Dashboard Statistic Fetched Successfully!');
    }
}
