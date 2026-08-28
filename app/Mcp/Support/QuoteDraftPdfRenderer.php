<?php

namespace App\Mcp\Support;

use App\Models\Quote;
use Barryvdh\DomPDF\PDF;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class QuoteDraftPdfRenderer
{
    public const STORAGE_DISK = 'local';

    public const STORAGE_PREFIX = 'quotes/drafts';

    public function __construct(private QuoteReportPresenter $presenter) {}

    public function render(Quote $quote): string
    {
        $report = $this->presenter->present($quote);
        unset($report['markdown']);

        return $this->pdf()->loadView('quotes.draft-pdf', ['report' => $report])
            ->setPaper('a4')
            ->output();
    }

    public function filename(Quote $quote): string
    {
        return $quote->number.'-draft.pdf';
    }

    public function storagePath(Quote $quote): string
    {
        return self::STORAGE_PREFIX.'/'.$this->filename($quote);
    }

    /**
     * Write the rendered PDF to the private local disk, overwriting an existing file for the same quote number.
     *
     * @return array{storage_disk: string, storage_path: string, filename: string}
     */
    public function store(Quote $quote): array
    {
        $filename = $this->filename($quote);
        $storagePath = $this->storagePath($quote);

        Storage::disk(self::STORAGE_DISK)->put($storagePath, $this->render($quote));

        return [
            'storage_disk' => self::STORAGE_DISK,
            'storage_path' => $storagePath,
            'filename' => $filename,
        ];
    }

    private function pdf(): PDF
    {
        $writableTemp = $this->writableTempDirectory();

        config([
            'dompdf.options.font_dir' => $writableTemp,
            'dompdf.options.font_cache' => $writableTemp,
            'dompdf.options.temp_dir' => $writableTemp,
        ]);

        app()->forgetInstance('dompdf.options');
        app()->forgetInstance('dompdf');
        app()->forgetInstance('dompdf.wrapper');

        return app('dompdf.wrapper');
    }

    private function writableTempDirectory(): string
    {
        $candidates = app()->environment('testing')
            ? [base_path('.phpunit-disks/dompdf')]
            : [
                storage_path('fonts'),
                storage_path('app/private/dompdf-tmp'),
                sys_get_temp_dir().DIRECTORY_SEPARATOR.'access-door-dompdf',
            ];

        foreach ($candidates as $directory) {
            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }

            if (is_dir($directory) && is_writable($directory)) {
                return $directory;
            }
        }

        throw new RuntimeException('Unable to find a writable temporary directory for PDF rendering.');
    }
}
