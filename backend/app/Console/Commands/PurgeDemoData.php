<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Admin\DemoDataPurger;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan demo:purge --dry-run   → show what would be deleted
 * php artisan demo:purge --force     → delete it (no prompt)
 * php artisan demo:purge --mark      → (re)flag seeded demo rows first
 */
class PurgeDemoData extends Command
{
    protected $signature = 'demo:purge
        {--dry-run : Only show what would be deleted}
        {--force : Delete without asking}
        {--mark : Re-apply the seeded-demo flag rules (is_demo) before planning}';

    protected $description = 'Delete the seeded demo data (users/clinics/pharmacies flagged is_demo and everything that depends on them)';

    public function handle(): int
    {
        if ($this->option('mark')) {
            $marked = DemoDataPurger::markSeededDemoData();
            $this->info('Flagged as demo: '.json_encode($marked));
        }

        $purger = new DemoDataPurger;
        $preview = $purger->preview();
        $this->report($preview);

        if ($preview['blockers']) {
            $this->error('Blocked:');
            foreach ($preview['blockers'] as $b) {
                $this->line(' - '.$b);
            }

            return self::FAILURE;
        }

        if ($preview['total'] === 0 && $preview['unlinked'] === 0) {
            $this->info('No demo data to delete.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Delete all of the above? Take a database backup first.', false)) {
            $this->comment('Cancelled.');

            return self::SUCCESS;
        }

        try {
            $result = (new DemoDataPurger)->purge();
        } catch (Throwable $e) {
            $this->error('Purge failed and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $admin = User::query()->where('role', User::ROLE_SUPER_ADMIN)->orderBy('id')->first();
            AuditLog::query()->create([
                'user_id' => null,
                'event' => 'demo_data_purged',
                'auditable_type' => User::class,
                'auditable_id' => $admin?->id ?? 0,
                'old_values' => null,
                'new_values' => ['via' => 'artisan demo:purge', 'total' => $result['total'], 'unlinked' => $result['unlinked'], 'files_deleted' => $result['files_deleted'], 'tables' => $result['tables']],
                'ip' => null,
            ]);
        } catch (Throwable $e) {
            // audit trail is best-effort from the CLI
        }

        $this->info("Deleted {$result['total']} rows, unlinked {$result['unlinked']}, removed {$result['files_deleted']} files.");

        return self::SUCCESS;
    }

    protected function report(array $p): void
    {
        $rows = [];
        foreach ($p['tables'] as $table => $c) {
            $rows[] = [$table, $c['delete'], $c['unlink'], $p['real_linked'][$table] ?? ''];
        }
        $this->table(['Table', 'Delete', 'Unlink (kept)', 'of which real-user records'], $rows);
        $this->line('Demo users by role: '.json_encode($p['demo_users']));
        if ($p['demo_staff']) {
            $this->warn('Demo staff logins that will be removed: '.implode(', ', $p['demo_staff']));
        }
        $this->line("Total rows: {$p['total']}; links set to NULL: {$p['unlinked']}; files: {$p['files']}");
    }
}
