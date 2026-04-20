<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeOldAuditLogs extends Command
{
    protected $signature = 'vetops:purge-audit-logs
        {--dry-run : Preview the delete count without touching the table}';

    protected $description = 'Purge audit logs older than the configured retention period (default 7 years).';

    public function handle(): int
    {
        $retentionYears = (int) config('vetops.audit_retention_years', 7);
        $cutoff = now()->subYears($retentionYears);

        $query = DB::table('audit_logs')->where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $count = $query->count();
            $this->info("[dry-run] Would purge {$count} audit log(s) older than {$retentionYears} years.");
            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Purged {$deleted} audit log(s) older than {$retentionYears} years.");

        return self::SUCCESS;
    }
}
