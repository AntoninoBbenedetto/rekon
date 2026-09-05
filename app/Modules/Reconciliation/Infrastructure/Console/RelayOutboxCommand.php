<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Console;

use App\Modules\Reconciliation\Infrastructure\MatchPendingTransactionJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class RelayOutboxCommand extends Command
{
    protected $signature = 'reconciliation:relay-outbox {--limit=500} {--stale-after=300}';

    protected $description = 'Publish pending outbox rows to their target queue and delete them (at-least-once delivery, ADR-009).';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $published = DB::transaction(function () use ($limit) {
            $rows = DB::table('outbox')
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            foreach ($rows as $row) {
                $this->relay($row);
                DB::table('outbox')->where('id', $row->id)->delete();
            }

            return $rows->count();
        });

        $this->warnIfBacklogIsStale((int) $this->option('stale-after'));

        $this->line("Relayed {$published} outbox message(s).");

        return self::SUCCESS;
    }

    private function relay(\stdClass $row): void
    {
        $payload = json_decode($row->payload, true);

        match ($row->message_type) {
            'match_pending_transaction' => MatchPendingTransactionJob::dispatch(
                $payload['transaction_id'],
                $row->correlation_id,
            ),
            default => Log::error('Unknown outbox message type; dropping row.', [
                'outbox_id' => $row->id,
                'message_type' => $row->message_type,
            ]),
        };
    }

    private function warnIfBacklogIsStale(int $staleAfterSeconds): void
    {
        $oldest = DB::table('outbox')->orderBy('created_at')->first();

        if ($oldest === null) {
            return;
        }

        $ageSeconds = now()->diffInSeconds(Carbon::parse($oldest->created_at), absolute: true);

        if ($ageSeconds > $staleAfterSeconds) {
            Log::warning('Outbox backlog aging beyond threshold; relay may be stalled.', [
                'age_seconds' => $ageSeconds,
                'threshold_seconds' => $staleAfterSeconds,
            ]);
        }
    }
}
