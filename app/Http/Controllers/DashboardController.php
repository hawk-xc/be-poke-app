<?php

namespace App\Http\Controllers;

use App\Models\VisitorDetection;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ResponseTrait;

    public function index(Request $request)
    {
        $query = VisitorDetection::query();

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('locale_time', [
                $request->start_date . ' 00:00:00',
                $request->end_date . ' 23:59:59'
            ]);
        }

        // get data from filter
        $data = $query->get();

        // ✅ visitor in & out total
        $totalIn = $data->where('label', 'in')->count();
        $totalOut = $data->where('label', 'out')->count();
        $totalAll = $data->count();

        // ✅ Gender distribution
        $maleCount = $data->where('face_sex', 'Man')->count();
        $femaleCount = $data->where('face_sex', 'Woman')->count();

        $malePercent = $totalAll > 0 ? round(($maleCount / $totalAll) * 100, 2) : 0;
        $femalePercent = $totalAll > 0 ? round(($femaleCount / $totalAll) * 100, 2) : 0;

        // ✅ Age Statistic
        $ageCategories = [
            '0-17' => $data->whereBetween('face_age', [0, 17])->count(),
            '18-25' => $data->whereBetween('face_age', [18, 25])->count(),
            '26-40' => $data->whereBetween('face_age', [26, 40])->count(),
            '41-60' => $data->whereBetween('face_age', [41, 60])->count(),
            '61+' => $data->where('face_age', '>=', 61)->count(),
        ];

        // Count percentage
        $agePercentages = [];
        foreach ($ageCategories as $range => $count) {
            $agePercentages[$range] = $totalAll > 0 ? round(($count / $totalAll) * 100, 2) : 0;
        }

        // ✅ Peak Hour (per 1H)
        $busyHours = $data
            ->groupBy(function ($item) {
                return date('H:00', strtotime($item->locale_time));
            })
            ->map(function ($group) {
                return $group->count();
            })
            ->sortKeys();

        return $this->responseSuccess([
            'total' => [
                'all' => $totalAll,
                'in' => $totalIn,
                'out' => $totalOut,
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
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
            ],
        ], 'Dashboard Statistic Fetched Successfully!');
    }
}
