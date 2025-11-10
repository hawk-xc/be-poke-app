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
        // Default range: kemarin s/d hari ini
        $start = now()->subDay()->startOfDay(); // kemarin
        $end = now()->endOfDay(); // hari ini

        // Jika ada parameter time (daily, monthly, yearly)
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
            }
        }

        // Jika user memberikan custom start_date / end_date
        if ($request->filled('start_date')) {
            $start = Carbon::parse($request->start_date)->startOfDay();
        }

        if ($request->filled('end_date')) {
            $end = Carbon::parse($request->end_date)->endOfDay();
        }

        // =========================
        // FILTERED DATA
        // =========================
        $query = VisitorDetection::whereBetween('locale_time', [$start, $end])->get();

        $totalIn = $query->where('label', 'in')->count();
        $totalOut = $query->where('label', 'out')->count();
        $totalAll = $query->count();

        // Visitor inside (tidak boleh minus)
        $visitorInside = max(0, $totalIn - $totalOut);

        // Gender
        $maleCount = $query->where('face_sex', 'Man')->count();
        $femaleCount = $query->where('face_sex', 'Woman')->count();

        $malePercent = $totalAll > 0 ? round(($maleCount / $totalAll) * 100, 2) : 0;
        $femalePercent = $totalAll > 0 ? round(($femaleCount / $totalAll) * 100, 2) : 0;

        // Age distribution
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

        // Average length of visit
        $lengthOfVisit = $query
            ->where('label', 'out')
            ->where('is_matched', true)
            ->avg('duration');
        $lengthOfVisit = $lengthOfVisit ? round($lengthOfVisit, 2) : 0;

        // Busy hours (07–17)
        $busyHours = [];
        for ($hour = 7; $hour <= 17; $hour++) {
            $busyHours[$hour] = $query
                ->filter(function ($item) use ($hour) {
                    return Carbon::parse($item->locale_time)->hour === $hour
                        && $item->label === 'out'
                        && $item->is_matched === true;
                })
                ->count();
        }

        // =========================
        // RESPONSE
        // =========================
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
