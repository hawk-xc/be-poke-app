<?php

namespace App\Http\Controllers;

use App\Models\VisitorDetection;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use App\Repositories\AuthRepository;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    use ResponseTrait;

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
        $start = now()->startOfDay();
        $end = now()->endOfDay();

        if ($request->filled('time')) {
            switch ($request->time) {
                case 'daily':
                    $start = now()->startOfDay();
                    $end = now()->endOfDay();
                    break;

                case 'monthly':
                    $start = now()->startOfMonth();
                    $end = now()->endOfMonth();
                    break;

                case 'yearly':
                    $start = now()->startOfYear();
                    $end = now()->endOfYear();
                    break;

                default:
                    $start = now()->startOfDay();
                    $end = now()->endOfDay();
                    break;
            }
        }

        /**
         * =========================
         * REAL-TIME DATA
         * =========================
         */
        $realTimeQuery = VisitorDetection::whereBetween('locale_time', [$start, $end])->get();

        $totalIn = $realTimeQuery->where('label', 'in')->count();
        $totalOut = $realTimeQuery->where('label', 'out')->count();
        $totalAll = $realTimeQuery->count();

        // Gender
        $maleCount = $realTimeQuery->where('face_sex', 'Man')->count();
        $femaleCount = $realTimeQuery->where('face_sex', 'Woman')->count();

        $malePercent = $totalAll > 0 ? round(($maleCount / $totalAll) * 100, 2) : 0;
        $femalePercent = $totalAll > 0 ? round(($femaleCount / $totalAll) * 100, 2) : 0;

        // Age
        $ageCategories = [
            '0-17' => $realTimeQuery->whereBetween('face_age', [0, 17])->count(),
            '18-25' => $realTimeQuery->whereBetween('face_age', [18, 25])->count(),
            '26-40' => $realTimeQuery->whereBetween('face_age', [26, 40])->count(),
            '41-60' => $realTimeQuery->whereBetween('face_age', [41, 60])->count(),
            '61+' => $realTimeQuery->where('face_age', '>=', 61)->count(),
        ];

        $agePercentages = [];
        foreach ($ageCategories as $range => $count) {
            $agePercentages[$range] = $totalAll > 0 ? round(($count / $totalAll) * 100, 2) : 0;
        }

        // Average Length of Visit (durasi rata-rata)
        $lengthOfVisit = $realTimeQuery
            ->where('label', 'out')
            ->where('is_matched', true)
            ->avg('duration');

        $lengthOfVisit = $lengthOfVisit ? round($lengthOfVisit, 2) : 0;

        // Peak Hour (jam 07–17)
        $busyHours = [];
        for ($hour = 7; $hour <= 17; $hour++) {
            $count = $realTimeQuery
                ->filter(function ($item) use ($hour) {
                    return Carbon::parse($item->locale_time)->hour === $hour
                        && $item->label === 'out'
                        && $item->is_matched === true;
                })
                ->count();
            $busyHours[$hour] = $count;
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
                    'percent' => $malePercent
                ],
                'female' => [
                    'count' => $femaleCount,
                    'percent' => $femalePercent
                ],
            ],
            'age_distribution' => [
                'counts' => $ageCategories,
                'percentages' => $agePercentages
            ],
            'busy_hours' => $busyHours,
            'filter' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ],
        ];

        /**
         * =========================
         * CUSTOM DATA
         * =========================
         */
        $customData = null;
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $customQuery = VisitorDetection::whereBetween('locale_time', [
                $request->start_date . ' 00:00:00',
                $request->end_date . ' 23:59:59'
            ])->get();

            $customLength = $customQuery
                ->where('label', 'out')
                ->where('is_matched', true)
                ->avg('duration');

            $customBusyHours = [];
            for ($hour = 7; $hour <= 17; $hour++) {
                $count = $customQuery
                    ->filter(function ($item) use ($hour) {
                        return Carbon::parse($item->locale_time)->hour === $hour
                            && $item->label === 'out'
                            && $item->is_matched === true;
                    })
                    ->count();
                $customBusyHours[$hour] = $count;
            }

            $customData = [
                'length_of_visit' => $customLength ? round($customLength, 2) : 0,
                'busy_hours' => $customBusyHours,
                'range' => [
                    'start_date' => $request->start_date,
                    'end_date' => $request->end_date,
                ]
            ];
        }

        return $this->responseSuccess([
            'realtime_data' => $realTimeData,
            'custom_data' => $customData,
        ], 'Dashboard Statistic Fetched Successfully!');
    }
}
