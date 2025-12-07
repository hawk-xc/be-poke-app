<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class MatchedVisitorsExport implements 
    FromQuery, 
    WithHeadings, 
    WithMapping, 
    WithChunkReading
{
    protected $query;

    public function __construct($query)
    {
        $this->query = $query;
    }

    public function query()
    {
        return $this->query->with('visitorIn');
    }

    public function headings(): array
    {
        return [
            'IN ID',
            'OUT ID',
            'GATE IN',
            'GATE OUT',
            'TIME IN',
            'TIME OUT',
            'EMOTION',
            'SEX',
        ];
    }

    public function map($out): array
    {
        $in = $out->visitorIn()
            ->orderBy('locale_time', 'desc')
            ->first();

        if (!$in) {
            return [
                null,
                $out->id,
                null,
                $out->gate_name,
                null,
                $out->locale_time,
                $out->emotion,
                $out->face_sex,
            ];
        }

        return [
            $in->id,
            $out->id,
            $in->gate_name,
            $out->gate_name,
            $in->locale_time,
            $out->locale_time,
            $out->emotion,
            $out->face_sex,
        ];
    }

    public function chunkSize(): int
    {
        return 500;
    }
}
