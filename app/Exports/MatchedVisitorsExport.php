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
    protected $publicUrl;

    public function __construct($query)
    {
        $this->query = $query;
        $this->publicUrl = env('MINIO_PUBLIC_ENDPOINT') . '/' . env('MINIO_BUCKET');
    }

    public function query()
    {
        return $this->query->with(['visitorIn' => function ($q) {
            $q->orderBy('locale_time', 'desc');
        }]);
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
            'IN IMAGE',
            'OUT IMAGE',
            'DURATION',
            'EMOTION',
            'SEX',
        ];
    }

    public function map($out): array
    {
        $in = $out->visitorIn->first();

        return [
            $in?->id,
            $out->id,
            $in?->gate_name,
            $out->gate_name,
            $in?->locale_time,
            $out->locale_time,
            $this->publicUrl . $in->person_pic_url,
            $this->publicUrl . $out->person_pic_url,
            $out->duration,
            $out->emotion,
            $out->face_sex,
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}
