<?php

namespace App\Services\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Finds and deletes the seeded demo data (System → Demo data, `php artisan demo:purge`).
 *
 * Starting points ("seeds"): users / clinics / pharmacies with is_demo = 1
 * (see migration 2026_10_17_900100 for the flagging rules). Super admins and
 * the user running the purge are never seeds.
 *
 * Everything else is found from the live database schema, so tables added
 * later by other features are covered without code changes:
 *  - every foreign key (Schema::getForeignKeys) pointing at a row being
 *    deleted: ON DELETE CASCADE or NOT NULL → the child row is deleted too;
 *    a nullable link → the link is set to NULL and the row is kept;
 *  - polymorphic / un-constrained references listed in MORPHS / SOFT_FKS.
 *
 * Real users who interacted with demo providers: a nullable link (e.g. a
 * real patient's ride with a demo driver) is set to NULL and, if the record
 * was still open, it is closed (status → cancelled). A record that cannot
 * exist without the demo row (NOT NULL column, e.g. a real patient's
 * appointment with a demo doctor) is deleted and counted under
 * "real-user records removed" so the admin sees it before confirming.
 * Nullable CASCADE links on a record that also belongs to a real person are
 * unlinked rather than deleted.
 *
 * Never deleted: rows in PROTECTED_TABLES (site config, CMS, logs, reference
 * data), users that are not flagged demo, and people/places (profiles,
 * clinics, pharmacies) that belong to a real account. If a demo row could only
 * be removed by deleting one of those, the purge is blocked and the preview
 * lists why.
 */
class DemoDataPurger
{
    /** Rows in these tables are never deleted (a link to demo data is nulled, or the purge is blocked). */
    public const PROTECTED_TABLES = [
        'migrations', 'settings', 'theme_settings', 'site_contents',
        'pages', 'page_blocks', 'page_templates', 'page_revisions', 'menus', 'menu_items',
        'app_widgets', 'health_articles', 'health_topics', 'testimonials', 'conditions',
        'faqs', 'faq_categories', 'announcements', 'emergency_contacts',
        'ride_rate_cards', 'legal_documents', 'roles', 'role_permissions',
        'triage_configs', 'medical_schemes', 'service_categories', 'promo_codes', 'subscription_plans',
        'audit_logs', 'data_access_logs', 'security_incidents',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    /** People / places. A row here is only deleted when it is itself demo. */
    protected const PEOPLE_TABLES = [
        'users', 'patient_profiles', 'doctor_profiles', 'driver_profiles', 'third_party_profiles', 'clinics', 'pharmacies',
    ];

    /** Polymorphic references (no FK constraint). 'class' = Eloquent class names. */
    protected const MORPHS = [
        ['table' => 'documents', 'type' => 'owner_type', 'id' => 'owner_id', 'map' => 'class'],
        ['table' => 'personal_access_tokens', 'type' => 'tokenable_type', 'id' => 'tokenable_id', 'map' => 'class'],
        ['table' => 'notifications', 'type' => 'notifiable_type', 'id' => 'notifiable_id', 'map' => 'class'],
        ['table' => 'claims', 'type' => 'claimable_type', 'id' => 'claimable_id', 'map' => 'class'],
        ['table' => 'feedback_surveys', 'type' => 'subject_type', 'id' => 'subject_id', 'map' => ['appointment' => 'appointments', 'ride' => 'rides']],
        ['table' => 'document_expiry_notices', 'type' => 'document_type', 'id' => 'document_id', 'map' => ['driver' => 'driver_documents', 'provider' => 'provider_documents']],
    ];

    /** Plain columns without a FK constraint. */
    protected const SOFT_FKS = [
        ['table' => 'sessions', 'column' => 'user_id', 'ref' => 'users', 'on_delete' => 'cascade', 'nullable' => false],
        ['table' => 'prescription_uploads', 'column' => 'order_id', 'ref' => 'orders', 'on_delete' => 'set null', 'nullable' => true],
    ];

    /** Records that get status = cancelled when their link to a demo provider is removed while still open. */
    protected const CLOSE_ON_UNLINK = [
        'appointments' => ['completed', 'cancelled', 'no_show'],
        'rides' => ['completed', 'cancelled'],
        'orders' => ['delivered', 'cancelled'],
    ];

    /** Column names that hold uploaded-file paths. */
    protected const FILE_COLUMN_PATTERN = '/^(path|file|file_path|attachment|attachments|attachment_path|avatar|image|cover_image|logo|photo|vehicle_photo|signature|recording_path|export_path)$/';

    protected const CHUNK = 500;

    /** @var array<string, array{columns: array<string,bool>, has_id: bool}> */
    protected array $tables = [];

    /** @var array<int, array{table:string,column:string,ref:string,on_delete:string,nullable:bool}> */
    protected array $fks = [];

    /** @var array<string, array<int|string, true>> table => ids to delete */
    protected array $delete = [];

    /** @var array<string, array<int, array{column:string, ref:string}>> conditional deletes for tables without an id column */
    protected array $leafDeletes = [];

    /** @var array<string, array<int|string, array{row: object, cols: array, via: string}>> candidate real-anchored rows that will be deleted */
    protected array $realLinked = [];

    /** @var array<int, string> */
    protected array $blockers = [];

    protected ?int $actingUserId = null;

    /** @var array<int, true> super/clinic admins: never count as a "real customer" anchor */
    protected array $staffUserIds = [];

    public function __construct(?int $actingUserId = null)
    {
        $this->actingUserId = $actingUserId;
    }

    /** Seeded demo user emails (never super_admin — checked separately). */
    public const DEMO_EMAIL_PATTERN = '/^(clinicadmin|doctor|patient|driver|thirdparty|pharmacy)[0-9]+@promptaid\.health$/i';

    /** Seeded clinic / pharmacy names; also require email "{slug}@promptaid.health". */
    public const DEMO_CLINICS = ['Prompt Aid Sandton Clinic', 'Prompt Aid Rosebank Clinic', 'Prompt Aid Pretoria Clinic'];

    public const DEMO_PHARMACIES = ['Prompt Aid Pharmacy Sandton', 'Wellness Corner Pharmacy'];

    /**
     * Flag the records DatabaseSeeder creates (same rules as migration
     * 2026_10_17_900100). Called at the end of DatabaseSeeder.
     *
     * @return array<string,int> rows flagged per table
     */
    public static function markSeededDemoData(): array
    {
        $out = ['users' => 0, 'clinics' => 0, 'pharmacies' => 0];
        if (Schema::hasColumn('users', 'is_demo')) {
            $ids = DB::table('users')
                ->where('email', 'like', '%@promptaid.health')
                ->where('role', '!=', 'super_admin')
                ->get(['id', 'email'])
                ->filter(fn ($u) => preg_match(self::DEMO_EMAIL_PATTERN, (string) $u->email))
                ->pluck('id')->all();
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $out['users'] += DB::table('users')->whereIn('id', $chunk)->update(['is_demo' => true]);
            }
        }
        foreach (['clinics' => self::DEMO_CLINICS, 'pharmacies' => self::DEMO_PHARMACIES] as $table => $names) {
            if (! Schema::hasColumn($table, 'is_demo')) {
                continue;
            }
            foreach ($names as $name) {
                $out[$table] += DB::table($table)->where('name', $name)
                    ->where('email', \Illuminate\Support\Str::slug($name).'@promptaid.health')
                    ->update(['is_demo' => true]);
            }
        }

        return $out;
    }

    /** Whether there are any is_demo rows at all (cheap check for the page / scope). */
    public static function hasDemoFlags(): bool
    {
        foreach (['users', 'clinics', 'pharmacies'] as $table) {
            if (Schema::hasColumn($table, 'is_demo') && DB::table($table)->where('is_demo', true)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * What purge() would do, without changing anything.
     *
     * @return array{tables: array<string, array{delete:int, unlink:int}>, total:int, unlinked:int, real_linked: array<string,int>,
     *   demo_users: array<string,int>, demo_staff: array<int,string>, files:int, blockers: array<int,string>}
     */
    public function preview(): array
    {
        $plan = $this->plan();

        return [
            'tables' => $plan['tables'],
            'total' => array_sum(array_column($plan['tables'], 'delete')),
            'unlinked' => array_sum(array_column($plan['tables'], 'unlink')),
            'real_linked' => $plan['real_linked'],
            'demo_users' => $plan['demo_users'],
            'demo_staff' => $plan['demo_staff'],
            'files' => count($plan['files']),
            'blockers' => $plan['blockers'],
        ];
    }

    /**
     * Delete all demo data in one transaction, then the demo users' files, then clear caches.
     *
     * @return array same shape as preview() plus files_deleted
     */
    public function purge(): array
    {
        $plan = $this->plan();
        if ($plan['blockers']) {
            throw new RuntimeException('Demo data purge blocked: '.implode(' ', $plan['blockers']));
        }

        DB::transaction(function () use ($plan) {
            // 1. Unlink real records from demo rows (and close open ones).
            foreach ($plan['nulls'] as $op) {
                $this->applyNull($op);
            }

            // 2. Tables without an id column (pivots, sessions keyed by string) by condition.
            foreach ($this->leafDeletes as $table => $conds) {
                foreach ($conds as $cond) {
                    foreach (array_chunk(array_keys($this->delete[$cond['ref']] ?? []), self::CHUNK) as $chunk) {
                        DB::table($table)->whereIn($cond['column'], $chunk)->delete();
                    }
                }
            }

            // 3. Self references inside the delete set (e.g. users.referred_by_id) → NULL first.
            foreach ($this->fks as $fk) {
                if ($fk['table'] === $fk['ref'] && $fk['nullable'] && ! empty($this->delete[$fk['table']])) {
                    foreach (array_chunk(array_keys($this->delete[$fk['table']]), self::CHUNK) as $chunk) {
                        DB::table($fk['table'])->whereIn('id', $chunk)->update([$fk['column'] => null]);
                    }
                }
            }

            // 4. Delete children before parents.
            [$ordered, $cyclic] = $this->deleteOrder();
            foreach ($ordered as $table) {
                $this->deleteIds($table);
            }
            if ($cyclic) {
                Schema::withoutForeignKeyConstraints(function () use ($cyclic) {
                    foreach ($cyclic as $table) {
                        $this->deleteIds($table);
                    }
                });
            }
        });

        $filesDeleted = $this->deleteFiles($plan['files']);

        try {
            Artisan::call('cache:clear');
        } catch (Throwable $e) {
            // Cache clearing is best-effort.
        }

        return [
            'tables' => $plan['tables'],
            'total' => array_sum(array_column($plan['tables'], 'delete')),
            'unlinked' => array_sum(array_column($plan['tables'], 'unlink')),
            'real_linked' => $plan['real_linked'],
            'demo_users' => $plan['demo_users'],
            'demo_staff' => $plan['demo_staff'],
            'files' => count($plan['files']),
            'files_deleted' => $filesDeleted,
            'blockers' => [],
        ];
    }

    // ------------------------------------------------------------------
    // Planning
    // ------------------------------------------------------------------

    protected function plan(): array
    {
        $this->loadSchema();
        $this->delete = [];
        $this->leafDeletes = [];
        $this->realLinked = [];
        $this->blockers = [];
        $this->staffUserIds = array_fill_keys(
            DB::table('users')->whereIn('role', ['super_admin', 'clinic_admin'])->pluck('id')->all(),
            true
        );

        $this->seed();

        // Grow the delete set until nothing new is found.
        $guard = 0;
        do {
            $changed = false;
            foreach ($this->fks as $fk) {
                $changed = $this->followForeignKey($fk) || $changed;
            }
            foreach (self::MORPHS as $morph) {
                $changed = $this->followMorph($morph) || $changed;
            }
            if (++$guard > 50) {
                throw new RuntimeException('Demo data plan did not settle after 50 passes.');
            }
        } while ($changed);

        $nulls = $this->planNulls();

        $tables = [];
        foreach ($this->delete as $table => $ids) {
            if ($ids) {
                $tables[$table]['delete'] = count($ids);
            }
        }
        foreach ($this->leafDeletes as $table => $conds) {
            $tables[$table]['delete'] = ($tables[$table]['delete'] ?? 0) + $this->countLeaf($table, $conds);
        }
        foreach ($nulls as $op) {
            $tables[$op['table']]['unlink'] = ($tables[$op['table']]['unlink'] ?? 0) + count($op['ids']);
        }
        foreach ($tables as $t => $row) {
            $tables[$t] = ['delete' => (int) ($row['delete'] ?? 0), 'unlink' => (int) ($row['unlink'] ?? 0)];
            if ($tables[$t]['delete'] === 0 && $tables[$t]['unlink'] === 0) {
                unset($tables[$t]);
            }
        }
        ksort($tables);

        $realLinked = [];
        foreach ($this->realLinked as $table => $candidates) {
            $n = 0;
            foreach ($candidates as $c) {
                if ($this->isRealAnchored($c['row'], $c['cols'], $c['via'])) {
                    $n++;
                }
            }
            if ($n) {
                $realLinked[$table] = $n;
            }
        }

        [$demoUsers, $demoStaff] = $this->describeDemoUsers();

        return [
            'tables' => $tables,
            'nulls' => $nulls,
            'real_linked' => $realLinked,
            'demo_users' => $demoUsers,
            'demo_staff' => $demoStaff,
            'files' => $this->collectFiles(),
            'blockers' => array_values(array_unique($this->blockers)),
        ];
    }

    protected function seed(): void
    {
        if ($this->hasColumn('users', 'is_demo')) {
            $q = DB::table('users')->where('is_demo', true)->where('role', '!=', 'super_admin');
            if ($this->actingUserId) {
                $q->where('id', '!=', $this->actingUserId);
            }
            foreach ($q->pluck('id') as $id) {
                $this->delete['users'][$id] = true;
            }
        }
        foreach (['clinics', 'pharmacies'] as $table) {
            if ($this->hasColumn($table, 'is_demo')) {
                foreach (DB::table($table)->where('is_demo', true)->pluck('id') as $id) {
                    $this->delete[$table][$id] = true;
                }
            }
        }
    }

    /** @return bool whether new rows were added */
    protected function followForeignKey(array $fk): bool
    {
        $parentIds = array_keys($this->delete[$fk['ref']] ?? []);
        $child = $fk['table'];
        if (! $parentIds || ! isset($this->tables[$child])) {
            return false;
        }
        if (! $this->tables[$child]['has_id']) {
            // Pivot-style rows (no id) are disposable link rows: delete them by condition.
            if (in_array($child, self::PROTECTED_TABLES, true)) {
                $this->blockers[] = "Protected table {$child} links to demo {$fk['ref']} through {$fk['column']}.";
            } else {
                $this->addLeaf($child, $fk['column'], $fk['ref']);
            }

            return false;
        }

        $deletes = $fk['on_delete'] === 'cascade' || ! $fk['nullable'];
        if (! $deletes) {
            return false; // nullable, non-cascading → unlinked in planNulls()
        }

        $peopleCols = $this->peopleColumns($child);
        $select = array_unique(array_merge(['id'], array_keys($peopleCols)));
        $changed = false;

        foreach (array_chunk($parentIds, self::CHUNK) as $chunk) {
            $rows = DB::table($child)->whereIn($fk['column'], $chunk)->get($select);
            foreach ($rows as $row) {
                $id = $row->id;
                if (isset($this->delete[$child][$id])) {
                    continue;
                }
                if ($this->isProtectedRow($child, $row)) {
                    continue; // handled (unlink or blocker) in planNulls()
                }
                $anchored = $this->isRealAnchored($row, $peopleCols, $fk['column']);
                if ($anchored && $fk['nullable']) {
                    continue; // nullable cascade on a real person's record → unlink instead
                }
                $this->delete[$child][$id] = true;
                if ($anchored) {
                    // re-checked against the final sets in plan()
                    $this->realLinked[$child][$id] = ['row' => $row, 'cols' => $peopleCols, 'via' => $fk['column']];
                }
                $changed = true;
            }
        }

        return $changed;
    }

    protected function followMorph(array $morph): bool
    {
        $table = $morph['table'];
        if (! $this->hasColumn($table, $morph['type']) || ! $this->hasColumn($table, $morph['id'])) {
            return false;
        }
        $changed = false;
        foreach ($this->morphTypes($morph) as $type => $parentTable) {
            $parentIds = array_keys($this->delete[$parentTable] ?? []);
            if (! $parentIds) {
                continue;
            }
            foreach (array_chunk($parentIds, self::CHUNK) as $chunk) {
                $ids = DB::table($table)->where($morph['type'], $type)->whereIn($morph['id'], $chunk)->pluck('id');
                foreach ($ids as $id) {
                    if (! isset($this->delete[$table][$id])) {
                        $this->delete[$table][$id] = true;
                        $changed = true;
                    }
                }
            }
        }

        return $changed;
    }

    /** @return array<string, string> stored type value => table */
    protected function morphTypes(array $morph): array
    {
        if (is_array($morph['map'])) {
            return $morph['map'];
        }
        $out = [];
        foreach ($this->modelTables() as $class => $table) {
            $out[$class] = $table;
        }

        return $out;
    }

    /**
     * Links from rows we keep to rows we delete. Nullable → set NULL; otherwise
     * the kept row is protected → blocker.
     *
     * @return array<int, array{table:string, column:string, ids: array<int,int|string>}>
     */
    protected function planNulls(): array
    {
        $ops = [];
        foreach ($this->fks as $fk) {
            $parentIds = array_keys($this->delete[$fk['ref']] ?? []);
            $child = $fk['table'];
            if (! $parentIds || ! isset($this->tables[$child]) || ! $this->tables[$child]['has_id']) {
                continue;
            }
            if ($child === $fk['ref'] && $fk['nullable']) {
                continue; // self reference inside the delete set handled in purge(); kept rows below
            }
            $keep = [];
            foreach (array_chunk($parentIds, self::CHUNK) as $chunk) {
                $rows = DB::table($child)->whereIn($fk['column'], $chunk)->pluck('id');
                foreach ($rows as $id) {
                    if (! isset($this->delete[$child][$id])) {
                        $keep[] = $id;
                    }
                }
            }
            if (! $keep) {
                continue;
            }
            if ($fk['nullable']) {
                $ops[] = ['table' => $child, 'column' => $fk['column'], 'ids' => $keep];
            } else {
                $this->blockers[] = count($keep)." {$child} record(s) that are not demo data point at demo {$fk['ref']} through {$child}.{$fk['column']} and cannot be unlinked (e.g. {$child} #{$keep[0]}). Reassign or delete them first.";
            }
        }
        // Self references from kept rows to deleted rows of the same table.
        foreach ($this->fks as $fk) {
            if ($fk['table'] !== $fk['ref'] || ! $fk['nullable'] || empty($this->delete[$fk['table']])) {
                continue;
            }
            $keep = [];
            foreach (array_chunk(array_keys($this->delete[$fk['table']]), self::CHUNK) as $chunk) {
                foreach (DB::table($fk['table'])->whereIn($fk['column'], $chunk)->pluck('id') as $id) {
                    if (! isset($this->delete[$fk['table']][$id])) {
                        $keep[] = $id;
                    }
                }
            }
            if ($keep) {
                $ops[] = ['table' => $fk['table'], 'column' => $fk['column'], 'ids' => $keep];
            }
        }

        return $ops;
    }

    protected function isProtectedRow(string $table, object $row): bool
    {
        if (in_array($table, self::PROTECTED_TABLES, true)) {
            return true;
        }
        if ($table === 'users') {
            return true; // users are only ever deleted as seeds (is_demo)
        }
        if (in_array($table, ['clinics', 'pharmacies'], true) && $this->hasColumn($table, 'is_demo')) {
            return true; // only is_demo clinics/pharmacies are removed (seeds)
        }
        if (in_array($table, self::PEOPLE_TABLES, true) && property_exists($row, 'user_id')) {
            return ! isset($this->delete['users'][$row->user_id]);
        }

        return false;
    }

    /** Whether the row also belongs to a person/place that stays (other than through $viaColumn). */
    protected function isRealAnchored(object $row, array $peopleCols, string $viaColumn): bool
    {
        foreach ($peopleCols as $col => $refTable) {
            if ($col === $viaColumn) {
                continue;
            }
            $v = $row->{$col} ?? null;
            if ($refTable === 'users' && $v !== null && isset($this->staffUserIds[$v])) {
                continue; // a staff member who handled the record does not make it a real customer's record
            }
            if ($v !== null && ! isset($this->delete[$refTable][$v])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> column => people table */
    protected function peopleColumns(string $table): array
    {
        $out = [];
        foreach ($this->fks as $fk) {
            if ($fk['table'] === $table && in_array($fk['ref'], self::PEOPLE_TABLES, true)) {
                $out[$fk['column']] = $fk['ref'];
            }
        }

        return $out;
    }

    protected function addLeaf(string $table, string $column, string $ref): void
    {
        foreach ($this->leafDeletes[$table] ?? [] as $c) {
            if ($c['column'] === $column && $c['ref'] === $ref) {
                return;
            }
        }
        $this->leafDeletes[$table][] = ['column' => $column, 'ref' => $ref];
    }

    protected function countLeaf(string $table, array $conds): int
    {
        $q = DB::table($table);
        $any = false;
        $q->where(function ($w) use ($conds, &$any) {
            foreach ($conds as $c) {
                $ids = array_keys($this->delete[$c['ref']] ?? []);
                if ($ids) {
                    $any = true;
                    $w->orWhereIn($c['column'], $ids);
                }
            }
        });

        return $any ? $q->count() : 0;
    }

    /** @return array{0: array<string,int>, 1: array<int,string>} demo users per role, demo staff logins */
    protected function describeDemoUsers(): array
    {
        $ids = array_keys($this->delete['users'] ?? []);
        $byRole = [];
        $staff = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (DB::table('users')->whereIn('id', $chunk)->get(['id', 'email', 'role']) as $u) {
                $byRole[$u->role] = ($byRole[$u->role] ?? 0) + 1;
                if (in_array($u->role, ['clinic_admin', 'pharmacy_admin'], true)) {
                    $staff[] = $u->email;
                }
            }
        }
        ksort($byRole);
        sort($staff);

        return [$byRole, $staff];
    }

    // ------------------------------------------------------------------
    // Execution helpers
    // ------------------------------------------------------------------

    protected function applyNull(array $op): void
    {
        $close = self::CLOSE_ON_UNLINK[$op['table']] ?? null;
        foreach (array_chunk($op['ids'], self::CHUNK) as $chunk) {
            DB::table($op['table'])->whereIn('id', $chunk)->update([$op['column'] => null]);

            if ($close && $this->hasColumn($op['table'], 'status')) {
                $update = ['status' => 'cancelled'];
                foreach (['cancellation_reason', 'cancel_reason'] as $col) {
                    if ($this->hasColumn($op['table'], $col)) {
                        $update[$col] = 'Provider removed (demo data cleared)';
                        break;
                    }
                }
                DB::table($op['table'])->whereIn('id', $chunk)->whereNotIn('status', $close)->update($update);
            }
        }
    }

    protected function deleteIds(string $table): void
    {
        foreach (array_chunk(array_keys($this->delete[$table] ?? []), self::CHUNK) as $chunk) {
            DB::table($table)->whereIn('id', $chunk)->delete();
        }
    }

    /** @return array{0: array<int,string>, 1: array<int,string>} tables in child-first order, tables caught in cycles */
    protected function deleteOrder(): array
    {
        $tables = array_keys(array_filter($this->delete));
        $set = array_flip($tables);
        // parent => children that still have rows to delete
        $children = array_fill_keys($tables, []);
        foreach ($this->fks as $fk) {
            if ($fk['table'] !== $fk['ref'] && isset($set[$fk['table']], $set[$fk['ref']])) {
                $children[$fk['ref']][$fk['table']] = true;
            }
        }
        $ordered = [];
        $remaining = $set;
        while ($remaining) {
            $progress = false;
            foreach (array_keys($remaining) as $t) {
                $pending = array_intersect_key($children[$t], $remaining);
                if (! $pending) {
                    $ordered[] = $t;
                    unset($remaining[$t]);
                    $progress = true;
                }
            }
            if (! $progress) {
                break;
            }
        }

        return [$ordered, array_keys($remaining)];
    }

    /** @return array<int, array{disk:?string, path:string}> */
    protected function collectFiles(): array
    {
        $files = [];
        foreach ($this->delete as $table => $ids) {
            if (! $ids || ! isset($this->tables[$table])) {
                continue;
            }
            $cols = array_values(array_filter(array_keys($this->tables[$table]['columns']), fn ($c) => preg_match(self::FILE_COLUMN_PATTERN, $c)));
            if (! $cols) {
                continue;
            }
            $hasDisk = $this->hasColumn($table, 'disk');
            $select = array_merge(['id'], $cols, $hasDisk ? ['disk'] : []);
            foreach (array_chunk(array_keys($ids), self::CHUNK) as $chunk) {
                foreach (DB::table($table)->whereIn('id', $chunk)->get($select) as $row) {
                    foreach ($cols as $col) {
                        foreach ($this->pathsFrom($row->{$col}) as $path) {
                            $files[($hasDisk ? $row->disk : '').'|'.$path] = ['disk' => $hasDisk ? $row->disk : null, 'path' => $path];
                        }
                    }
                }
            }
        }

        return array_values($files);
    }

    /** @return array<int, string> */
    protected function pathsFrom(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }
        $candidates = [$value];
        if ($value[0] === '[' || $value[0] === '{') {
            $decoded = json_decode($value, true);
            $candidates = [];
            array_walk_recursive($decoded, function ($v) use (&$candidates) {
                if (is_string($v)) {
                    $candidates[] = $v;
                }
            });
        }

        return array_values(array_filter($candidates, function ($p) {
            return strlen($p) < 500
                && ! preg_match('#^(https?:)?//|^data:#i', $p)
                && ! str_contains($p, '..')
                && preg_match('#^[A-Za-z0-9_\-./ ]+$#', $p)
                && str_contains($p, '/') && str_contains($p, '.');
        }));
    }

    protected function deleteFiles(array $files): int
    {
        $n = 0;
        foreach ($files as $f) {
            $disks = $f['disk'] ? [$f['disk']] : ['public', 'local'];
            foreach ($disks as $disk) {
                try {
                    $path = ltrim(preg_replace('#^/?storage/#', '', $f['path']), '/');
                    if (Storage::disk($disk)->exists($path)) {
                        Storage::disk($disk)->delete($path);
                        $n++;
                    }
                } catch (Throwable $e) {
                    // Missing disk / permission problem: leave the file.
                }
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------
    // Schema
    // ------------------------------------------------------------------

    protected function loadSchema(): void
    {
        if ($this->tables) {
            return;
        }
        $prefix = DB::getTablePrefix();
        $driver = DB::connection()->getDriverName();
        $schema = in_array($driver, ['mysql', 'mariadb'], true) ? DB::connection()->getDatabaseName() : null;

        $names = [];
        foreach (Schema::getTables($schema) as $t) {
            $name = $t['name'];
            if ($prefix !== '' && str_starts_with($name, $prefix)) {
                $name = substr($name, strlen($prefix));
            }
            if (str_starts_with($name, 'sqlite_')) {
                continue;
            }
            $names[] = $name;
        }

        foreach ($names as $name) {
            $cols = [];
            foreach (Schema::getColumns($name) as $c) {
                $cols[$c['name']] = (bool) $c['nullable'];
            }
            $this->tables[$name] = ['columns' => $cols, 'has_id' => array_key_exists('id', $cols)];
        }

        foreach ($names as $name) {
            foreach (Schema::getForeignKeys($name) as $fk) {
                if (count($fk['columns']) !== 1 || ($fk['foreign_columns'][0] ?? null) !== 'id') {
                    continue;
                }
                $ref = $fk['foreign_table'];
                if ($prefix !== '' && str_starts_with($ref, $prefix)) {
                    $ref = substr($ref, strlen($prefix));
                }
                $col = $fk['columns'][0];
                $this->fks[] = [
                    'table' => $name,
                    'column' => $col,
                    'ref' => $ref,
                    'on_delete' => strtolower((string) ($fk['on_delete'] ?? 'restrict')),
                    'nullable' => $this->tables[$name]['columns'][$col] ?? false,
                ];
            }
        }

        foreach (self::SOFT_FKS as $soft) {
            if (! $this->hasColumn($soft['table'], $soft['column']) || ! isset($this->tables[$soft['ref']])) {
                continue;
            }
            foreach ($this->fks as $fk) {
                if ($fk['table'] === $soft['table'] && $fk['column'] === $soft['column']) {
                    continue 2;
                }
            }
            $this->fks[] = $soft;
        }
    }

    protected function hasColumn(string $table, string $column): bool
    {
        $this->loadSchema();

        return array_key_exists($column, $this->tables[$table]['columns'] ?? []);
    }

    /** @return array<class-string, string> Eloquent class => table, for polymorphic *_type values */
    protected function modelTables(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            try {
                if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                    $map[$class] = (new $class)->getTable();
                }
            } catch (Throwable $e) {
                // skip models that cannot be instantiated
            }
        }

        return $map;
    }
}
