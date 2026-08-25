<?php

namespace App\Services;

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Models\Account;
use App\Models\File;
use App\Models\ImportedMovement;
use App\Models\ImportRow;
use App\Services\FileReader\FileReaderFactory;
use Illuminate\Support\Facades\DB;
use Throwable;

class CsvImportService
{
    public function __construct(private readonly TransactionMatchingService $matching) {}

    public function create(Account $account, string $storedPath, string $originalName, string $absolutePath): File
    {
        $fingerprint = hash_file('sha256', $absolutePath);
        $reader = FileReaderFactory::make($absolutePath);

        return File::query()->firstOrCreate(
            ['account_id' => $account->id, 'file_fingerprint' => $fingerprint],
            [
                'filename' => $storedPath,
                'original_filename' => mb_substr($originalName, 0, 255),
                'source_name' => $reader->sourceName(),
                'source_type' => 'csv',
                'status' => ImportStatus::Pending,
            ]
        );
    }

    public function process(File $import, string $absolutePath): void
    {
        $import->update(['status' => ImportStatus::Processing, 'error_message' => null]);
        $total = $processed = $failed = 0;
        $occurrences = [];

        try {
            $reader = FileReaderFactory::make($absolutePath);
            foreach ($reader->rows() as $data) {
                $total++;
                try {
                    if (isset($data['error'])) {
                        throw new \RuntimeException($data['error']);
                    }
                    $fingerprint = $this->movementFingerprint($import, $data['normalized']);
                    $occurrence = ($occurrences[$fingerprint] ?? 0) + 1;
                    $occurrences[$fingerprint] = $occurrence;
                    $this->processRow($import, $data, $reader->sourceName(), $fingerprint, $occurrence);
                    $processed++;
                } catch (Throwable $exception) {
                    $failed++;
                    ImportRow::query()->updateOrCreate(
                        ['import_id' => $import->id, 'line_number' => $data['line_number']],
                        [
                            'account_id' => $import->account_id,
                            'raw_payload' => $data['raw'],
                            'normalized_payload' => $data['normalized'] ?? null,
                            'fingerprint' => hash('sha256', implode('|', [$import->tenant_id, $import->id, $data['line_number']])),
                            'status' => ImportRowStatus::Failed,
                            'error_message' => $exception->getMessage(),
                        ]
                    );
                }
            }
        } catch (Throwable $exception) {
            $import->update([
                'status' => ImportStatus::Failed,
                'total_rows' => $total,
                'processed_rows' => $processed,
                'failed_rows' => $failed,
                'error_message' => $exception->getMessage(),
            ]);
            throw $exception;
        }

        $import->update([
            'status' => $failed > 0 ? ImportStatus::CompleteWithErrors : ImportStatus::Complete,
            'total_rows' => $total,
            'processed_rows' => $processed,
            'failed_rows' => $failed,
        ]);
    }

    private function movementFingerprint(File $import, array $normalized): string
    {
        if ($normalized['currency'] !== $import->account?->currency) {
            throw new \RuntimeException('CSV row currency does not match the selected account currency.');
        }

        $expectedAccountNumber = $import->account?->account_number;
        if ($expectedAccountNumber !== null
            && $normalized['account_number'] !== null
            && trim($expectedAccountNumber) !== trim($normalized['account_number'])) {
            throw new \RuntimeException('The CSV row belongs to a different bank account.');
        }

        return hash('sha256', implode('|', [
            $import->tenant_id,
            $import->account_id,
            $normalized['bank_reference'] ?? '',
            $normalized['transaction_date'],
            Money::decimal(Money::units($normalized['amount'])),
            preg_replace('/\s+/', ' ', mb_strtolower(trim($normalized['description']))),
        ]));
    }

    private function processRow(
        File $import,
        array $data,
        string $source,
        string $fingerprint,
        int $occurrence
    ): void {
        $normalized = $data['normalized'];

        DB::transaction(function () use ($import, $data, $source, $normalized, $fingerprint, $occurrence): void {
            $row = ImportRow::query()->firstOrCreate(
                ['import_id' => $import->id, 'line_number' => $data['line_number']],
                [
                    'account_id' => $import->account_id,
                    'raw_payload' => $data['raw'],
                    'normalized_payload' => $normalized,
                    'fingerprint' => $fingerprint,
                    'occurrence' => $occurrence,
                    'status' => ImportRowStatus::Pending,
                ]
            );
            if (! $row->wasRecentlyCreated) {
                return;
            }

            $identity = [
                'tenant_id' => $import->tenant_id,
                'account_id' => $import->account_id,
                'source_name' => mb_strtolower($source),
                'fingerprint' => $fingerprint,
                'occurrence' => $occurrence,
            ];
            DB::table('imported_movements')->insertOrIgnore([
                ...$identity,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $movement = ImportedMovement::query()
                ->where($identity)
                ->lockForUpdate()
                ->firstOrFail();
            $row->update(['imported_movement_id' => $movement->id]);

            if ($movement->transaction_id !== null) {
                $row->update([
                    'transaction_id' => $movement->transaction_id,
                    'status' => ImportRowStatus::Duplicate,
                ]);

                return;
            }

            $transaction = $this->matching->process($row, [
                'transaction_date' => $normalized['transaction_date'],
                'description' => $normalized['description'],
                'amount' => Money::decimal(Money::units($normalized['amount'])),
            ]);
            $movement->update(['transaction_id' => $transaction->id]);
        });
    }
}
