<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TrashPurgeService;
use Illuminate\Console\Command;

class PurgeTrashCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trash:purge';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Elimina definitivamente los elementos en la Papelera de Reciclaje con más de 8 días calendario.';

    /**
     * Execute the console command.
     */
    public function handle(TrashPurgeService $purgeService): int
    {
        $purged = $purgeService->purgeExpired();

        $this->info("Purga de papelera completada. Registros eliminados definitivamente: {$purged}.");

        return self::SUCCESS;
    }
}

