<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PatientProfile;
use App\Models\Ride;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamed CSV downloads — no queue worker needed (the host has none, so
 * Filament's queued ExportAction is not an option).
 *
 *  - CsvExport::stream($filename, $headers, $rows) streams any iterable of
 *    rows straight to the browser (used by the Reports page too).
 *  - CsvExport::headerAction('users') / bulkAction('users') are Filament
 *    table actions. "Export CSV" takes the table's CURRENT query (filters,
 *    search and sort applied, plus the resource's own tenant scoping), keeps
 *    the matching ids for 10 minutes under a random token tied to the
 *    signed-in user, and sends the browser to a download route
 *    (Platform\CsvExportController) that streams the rows in chunks.
 *    "Export selected" does the same with the ticked rows.
 *
 * Add a table: define its columns in definitions() and drop the two
 * actions into its Table class.
 */
class CsvExport
{
    public const MAX_ROWS = 100000;

    public const TTL_SECONDS = 600;

    /**
     * @return array<string, array{label: string, model: class-string<Model>, resource: class-string, with: array<int, string>, columns: Closure(): array<string, Closure(Model): mixed>}>
     */
    public static function definitions(): array
    {
        $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('Y-m-d') : '';
        $dateTime = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('Y-m-d H:i') : '';
        $money = fn ($v) => $v === null || $v === '' ? '' : number_format((float) $v, 2, '.', '');
        $yesNo = fn ($v) => $v ? 'Yes' : 'No';

        return [
            'users' => [
                'label' => 'Users',
                'model' => User::class,
                'resource' => \App\Filament\Resources\Users\UserResource::class,
                'with' => [],
                'columns' => fn () => [
                    'ID' => fn (User $u) => $u->id,
                    'Name' => fn (User $u) => $u->name,
                    'Email' => fn (User $u) => $u->email,
                    'Phone' => fn (User $u) => $u->phone,
                    'Role' => fn (User $u) => Str::headline((string) $u->role),
                    'Status' => fn (User $u) => $u->status,
                    'Email verified' => fn (User $u) => $dateTime($u->email_verified_at),
                    'Created' => fn (User $u) => $dateTime($u->created_at),
                ],
            ],
            'appointments' => [
                'label' => 'Appointments',
                'model' => Appointment::class,
                'resource' => \App\Filament\Resources\Appointments\AppointmentResource::class,
                'with' => ['patient.user', 'doctor.user', 'clinic'],
                'columns' => fn () => [
                    'Booking ref' => fn (Appointment $a) => $a->booking_ref,
                    'Patient' => fn (Appointment $a) => $a->patient?->user?->name,
                    'Doctor' => fn (Appointment $a) => $a->doctor?->user?->name,
                    'Clinic' => fn (Appointment $a) => $a->clinic?->name,
                    'Date' => fn (Appointment $a) => $date($a->date),
                    'Start' => fn (Appointment $a) => $a->start_time ? substr((string) $a->start_time, 0, 5) : '',
                    'End' => fn (Appointment $a) => $a->end_time ? substr((string) $a->end_time, 0, 5) : '',
                    'Visit type' => fn (Appointment $a) => $a->visit_type,
                    'Mode' => fn (Appointment $a) => $a->mode,
                    'Status' => fn (Appointment $a) => $a->status,
                    'Requested specialty' => fn (Appointment $a) => $a->requested_specialty,
                    'Shuttle requested' => fn (Appointment $a) => $yesNo($a->ride_requested),
                    'Cancel reason' => fn (Appointment $a) => $a->cancel_reason,
                    'Created' => fn (Appointment $a) => $dateTime($a->created_at),
                ],
            ],
            'rides' => [
                'label' => 'Rides',
                'model' => Ride::class,
                'resource' => \App\Filament\Resources\Rides\RideResource::class,
                'with' => ['patient.user', 'driver.user'],
                'columns' => fn () => [
                    'Ride ref' => fn (Ride $r) => $r->ride_ref,
                    'Patient' => fn (Ride $r) => $r->patient?->user?->name,
                    'Driver' => fn (Ride $r) => $r->driver?->user?->name,
                    'Pickup' => fn (Ride $r) => $r->pickup_address,
                    'Drop-off' => fn (Ride $r) => $r->dropoff_address,
                    'Vehicle' => fn (Ride $r) => $r->vehicle_type,
                    'Distance (km)' => fn (Ride $r) => $r->distance_km,
                    'Status' => fn (Ride $r) => $r->status,
                    'Fare estimate (R)' => fn (Ride $r) => $money($r->fare_estimate),
                    'Fare final (R)' => fn (Ride $r) => $money($r->fare_final),
                    'Cancellation fee (R)' => fn (Ride $r) => $money($r->cancellation_fee),
                    'Return leg' => fn (Ride $r) => $yesNo($r->is_return),
                    'Requested at' => fn (Ride $r) => $dateTime($r->requested_at),
                    'Scheduled for' => fn (Ride $r) => $dateTime($r->scheduled_for),
                    'Completed at' => fn (Ride $r) => $dateTime($r->completed_at),
                    'Cancelled at' => fn (Ride $r) => $dateTime($r->cancelled_at),
                    'Patient rating' => fn (Ride $r) => $r->patient_rating,
                ],
            ],
            'orders' => [
                'label' => 'Orders',
                'model' => Order::class,
                'resource' => \App\Filament\Resources\Orders\OrderResource::class,
                'with' => ['patient.user', 'pharmacy'],
                'columns' => fn () => [
                    'Order no' => fn (Order $o) => $o->order_no,
                    'Patient' => fn (Order $o) => $o->patient?->user?->name,
                    'Pharmacy' => fn (Order $o) => $o->pharmacy?->name,
                    'Subtotal (R)' => fn (Order $o) => $money($o->subtotal),
                    'Delivery fee (R)' => fn (Order $o) => $money($o->delivery_fee),
                    'Total (R)' => fn (Order $o) => $money($o->total),
                    'Commission (R)' => fn (Order $o) => $money($o->commission_amount),
                    'Status' => fn (Order $o) => $o->status,
                    'Payment status' => fn (Order $o) => $o->payment_status,
                    'Delivery address' => fn (Order $o) => $o->delivery_address,
                    'Created' => fn (Order $o) => $dateTime($o->created_at),
                ],
            ],
            'invoices' => [
                'label' => 'Invoices',
                'model' => Invoice::class,
                'resource' => \App\Filament\Resources\Invoices\InvoiceResource::class,
                'with' => ['patient.user', 'clinic'],
                'columns' => fn () => [
                    'Invoice no' => fn (Invoice $i) => $i->invoice_no,
                    'Patient' => fn (Invoice $i) => $i->patient?->user?->name,
                    'Clinic' => fn (Invoice $i) => $i->clinic?->name,
                    'Subtotal (R)' => fn (Invoice $i) => $money($i->subtotal),
                    'Tax (R)' => fn (Invoice $i) => $money($i->tax),
                    'Discount (R)' => fn (Invoice $i) => $money($i->discount),
                    'Total (R)' => fn (Invoice $i) => $money($i->total),
                    'Status' => fn (Invoice $i) => $i->status,
                    'Due date' => fn (Invoice $i) => $date($i->due_date),
                    'Created' => fn (Invoice $i) => $dateTime($i->created_at),
                ],
            ],
            'patient-profiles' => [
                'label' => 'Patients',
                'model' => PatientProfile::class,
                'resource' => \App\Filament\Resources\PatientProfiles\PatientProfileResource::class,
                'with' => ['user'],
                'columns' => fn () => [
                    'Patient ID' => fn (PatientProfile $p) => $p->id,
                    'Name' => fn (PatientProfile $p) => $p->user?->name,
                    'Email' => fn (PatientProfile $p) => $p->user?->email,
                    'Phone' => fn (PatientProfile $p) => $p->user?->phone,
                    'Date of birth' => fn (PatientProfile $p) => $date($p->dob),
                    'Gender' => fn (PatientProfile $p) => $p->gender,
                    'Blood group' => fn (PatientProfile $p) => $p->blood_group,
                    'Address' => fn (PatientProfile $p) => $p->address,
                    'Emergency contact' => fn (PatientProfile $p) => $p->emergency_contact_name,
                    'Emergency contact phone' => fn (PatientProfile $p) => $p->emergency_contact_phone,
                    'Allergies' => fn (PatientProfile $p) => $p->allergies,
                    'Chronic conditions' => fn (PatientProfile $p) => $p->chronic_conditions,
                    'Registered' => fn (PatientProfile $p) => $dateTime($p->created_at),
                ],
            ],
        ];
    }

    /** "Export CSV" table header action — current filters, search and sort. */
    public static function headerAction(string $exporter): Action
    {
        return Action::make('exportCsv')
            ->label('Export CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->tooltip('Download every row that matches the current filters and search')
            ->action(function (HasTable $livewire) use ($exporter) {
                $query = $livewire->getFilteredSortedTableQuery();
                if ($query === null) {
                    return;
                }
                $model = $query->getModel();
                $ids = $query->toBase()->limit(self::MAX_ROWS + 1)->pluck($model->getQualifiedKeyName())->all();

                static::startDownload($livewire, $exporter, $ids);
            });
    }

    /** "Export selected" bulk action. */
    public static function bulkAction(string $exporter): BulkAction
    {
        return BulkAction::make('exportSelectedCsv')
            ->label('Export selected (CSV)')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, HasTable $livewire) use ($exporter) {
                static::startDownload($livewire, $exporter, $records->modelKeys());
            });
    }

    /** @param  array<int, int|string>  $ids */
    protected static function startDownload(HasTable $livewire, string $exporter, array $ids): void
    {
        if ($ids === []) {
            Notification::make()->title('Nothing to export')->body('No rows match the current filters.')->warning()->send();

            return;
        }

        $truncated = count($ids) > self::MAX_ROWS;
        $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, self::MAX_ROWS);

        $token = Str::random(40);
        Cache::put('csv-export:'.$token, [
            'user_id' => auth()->id(),
            'exporter' => $exporter,
            'ids' => implode(',', $ids),
        ], self::TTL_SECONDS);

        Notification::make()
            ->title('Your CSV is downloading')
            ->body(number_format(count($ids)).' rows'.($truncated ? ' (limited to the first '.number_format(self::MAX_ROWS).' — narrow the filters for the rest)' : '').'.')
            ->success()
            ->send();

        $livewire->redirect(route('platform.csv-export', ['token' => $token]));
    }

    /**
     * Stream a stored export (Platform\CsvExportController). Returns null
     * when the token is unknown, expired or belongs to someone else.
     */
    public static function downloadFromToken(string $token, User $user): ?StreamedResponse
    {
        $payload = Cache::get('csv-export:'.$token);
        if (! is_array($payload) || (int) ($payload['user_id'] ?? 0) !== (int) $user->id) {
            return null;
        }

        $definition = static::definitions()[$payload['exporter'] ?? ''] ?? null;
        if (! $definition) {
            return null;
        }

        // Re-check access at download time (the link can be replayed for 10 minutes).
        $resource = $definition['resource'];
        if (! $resource::canViewAny()) {
            return null;
        }

        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $payload['ids']))));
        $columns = ($definition['columns'])();
        $modelClass = $definition['model'];
        $with = $definition['with'];

        static::audit($user, $payload['exporter'], count($ids));

        $rows = (function () use ($ids, $modelClass, $with, $columns) {
            foreach (array_chunk($ids, 500) as $chunk) {
                // Keep the table's order: fetch the chunk, then emit in id-list order.
                $models = $modelClass::query()->with($with)->whereKey($chunk)->get()->keyBy(fn (Model $m) => (int) $m->getKey());
                foreach ($chunk as $id) {
                    $model = $models->get($id);
                    if ($model) {
                        yield array_map(fn (Closure $col) => $col($model), $columns);
                    }
                }
            }
        })();

        $name = Str::slug($definition['label']).'-'.now()->format('Y-m-d-His').'.csv';

        return static::stream($name, array_keys($columns), $rows);
    }

    /**
     * Stream rows as a CSV download (UTF-8 with BOM so Excel reads accents).
     *
     * @param  array<int, string>  $headers
     * @param  iterable<array<int|string, mixed>>  $rows
     */
    public static function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ',', '"', '');
            $n = 0;
            foreach ($rows as $row) {
                fputcsv($out, array_map([static::class, 'cell'], array_values((array) $row)), ',', '"', '');
                if (++$n % 500 === 0) {
                    flush();
                }
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** Plain scalar, and never a spreadsheet formula (CSV injection). */
    public static function cell(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i');
        } elseif ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        }

        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value)) {
            $value = "'".$value;
        }

        return $value;
    }

    protected static function audit(User $user, string $exporter, int $rows): void
    {
        try {
            AuditLog::query()->create([
                'user_id' => $user->id,
                'event' => 'csv_export',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'old_values' => null,
                'new_values' => ['export' => $exporter, 'rows' => $rows],
                'ip' => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            // Never block a download on the audit trail.
        }
    }
}
