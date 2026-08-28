<?php

namespace App\Http\Controllers;

use App\Actions\Quotes\CreateQuoteFromSellerNotesAction;
use App\Mcp\Support\SellerQuoteNotesDisk;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StoreSellerQuoteNotesController extends Controller
{
    public function __invoke(
        Request $request,
        SellerQuoteNotesDisk $disk,
        CreateQuoteFromSellerNotesAction $createFromNotes,
    ): RedirectResponse {
        $request->validate([
            'notes' => ['required', 'file', 'max:64'],
        ]);

        $user = $request->user();

        if (! $user instanceof Account) {
            abort(403);
        }

        $stored = $disk->storeUtf8File($request->file('notes'));
        $text = $disk->get($stored['storage_path']);

        try {
            $result = $createFromNotes->handle(
                $user,
                $text,
                $stored['filename'],
                $stored['storage_path'],
            );
        } catch (ValidationException $exception) {
            return redirect()
                ->route('home')
                ->with('filename', $stored['filename'])
                ->with('storage_path', $stored['storage_path'])
                ->withErrors($exception->errors());
        }

        return redirect()
            ->route('home')
            ->with('filename', $result['filename'])
            ->with('storage_path', $result['storage_path'])
            ->with('quote_report', $result['quote_report']);
    }
}
