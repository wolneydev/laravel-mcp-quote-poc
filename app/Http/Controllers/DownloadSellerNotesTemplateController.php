<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

class DownloadSellerNotesTemplateController extends Controller
{
    public function __invoke(): Response
    {
        $path = resource_path('seller-notes/template.txt');
        $contents = file_get_contents($path);

        abort_unless(is_string($contents), 404);

        return response($contents, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="seller-notes-template.txt"',
        ]);
    }
}
