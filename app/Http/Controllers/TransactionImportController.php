<?php

namespace App\Http\Controllers;

use App\Actions\Imports\ImportAmexCsv;
use App\Http\Requests\Transactions\TransactionImportRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class TransactionImportController extends Controller
{
    /**
     * Import an American Express statement.
     *
     * The outcome is flashed rather than turned into a toast, because the
     * dialog that started the import stays open to report it.
     */
    public function store(TransactionImportRequest $request, ImportAmexCsv $import): RedirectResponse
    {
        $account = $this->amexAccount($request);
        $file = $request->file('file');

        try {
            $batch = $import->handle(
                $account,
                $file->getRealPath(),
                $file->getClientOriginalName(),
            );
        } catch (Throwable $exception) {
            Log::error('AMEX import failed.', ['exception' => $exception->getMessage()]);

            return to_route('transactions.index')->with('importResult', [
                'status' => 'error',
                'filename' => $file->getClientOriginalName(),
                'message' => $exception->getMessage(),
            ]);
        }

        return to_route('transactions.index')->with('importResult', [
            'status' => 'success',
            'filename' => $batch->filename,
            'total' => $batch->rows_total,
            'imported' => $batch->rows_imported,
            'updated' => $batch->rows_updated,
            'skipped' => $batch->rows_skipped,
        ]);
    }

    /**
     * The one card statements are imported for, created on first upload.
     */
    private function amexAccount(TransactionImportRequest $request): Account
    {
        return Account::query()->firstOrCreate(
            [
                'user_id' => $request->user()->id,
                'provider' => 'amex',
            ],
            [
                'name' => 'Amex',
                'currency' => 'GBP',
            ],
        );
    }
}
