<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RentalService;
use Illuminate\Console\Command;

class MarkOverdueRentals extends Command
{
    protected $signature = 'vetops:mark-overdue-rentals';
    protected $description = 'Mark rental transactions as overdue after the configured threshold.';

    public function handle(RentalService $rentalService): int
    {
        $count = $rentalService->markOverdue();
        $this->info("Marked {$count} rental transaction(s) as overdue.");

        return self::SUCCESS;
    }
}
