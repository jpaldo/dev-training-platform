<?php

namespace App\Services;

use Illuminate\Support\Collection;

class TrainingExportService
{
    public function csv(array $header, Collection $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $header);
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);

        return stream_get_contents($stream);
    }
}
