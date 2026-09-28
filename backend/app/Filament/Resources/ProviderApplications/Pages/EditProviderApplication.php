<?php

namespace App\Filament\Resources\ProviderApplications\Pages;

use App\Contracts\NotificationDispatcherInterface;
use App\Filament\Resources\ProviderApplications\ProviderApplicationResource;
use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\Pharmacy;
use App\Models\ProviderApplication;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class EditProviderApplication extends EditRecord
{
    protected static string $resource = ProviderApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (ProviderApplication $record): bool => in_array($record->status, [
                    ProviderApplication::STATUS_SUBMITTED, ProviderApplication::STATUS_UNDER_REVIEW,
                ], true))
                ->action(function (ProviderApplication $record): void {
                    try {
                        $this->approve($record);
                        $this->refreshFormData(['status', 'reviewed_by', 'reviewed_at']);
                    } catch (RuntimeException $e) {
                        Notification::make()
                            ->title('Could not approve')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (ProviderApplication $record): bool => in_array($record->status, [
                    ProviderApplication::STATUS_SUBMITTED, ProviderApplication::STATUS_UNDER_REVIEW,
                ], true))
                ->schema([
                    Textarea::make('rejection_reason')->required()->columnSpanFull(),
                ])
                ->action(function (array $data, ProviderApplication $record, NotificationDispatcherInterface $notifier): void {
                    $record->update([
                        'status' => ProviderApplication::STATUS_REJECTED,
                        'rejection_reason' => $data['rejection_reason'],
                        'reviewed_by' => Auth::id(),
                        'reviewed_at' => now(),
                    ]);

                    // Applicant may not have a User account yet — build a
                    // transient (unsaved) one purely to carry name/email
                    // through the shared notifier contract.
                    $applicant = new User(['name' => $record->contact_name, 'email' => $record->email]);
                    $notifier->email(
                        $applicant,
                        'Your Prompt Aid provider application',
                        "Hi {$record->contact_name}, your application for {$record->business_name} was not approved.\n\nReason: {$data['rejection_reason']}\n\nYou're welcome to re-apply once this is addressed.",
                    );

                    $this->refreshFormData(['status', 'rejection_reason', 'reviewed_by', 'reviewed_at']);
                    Notification::make()->title('Application rejected')->success()->send();
                }),
            DeleteAction::make(),
        ];
    }

    /**
     * Approving creates the real, live provider record so the pipeline
     * actually onboards someone rather than just changing a status label.
     * Doctor/pharmacy applications also need a login, so a User is created
     * with a system-generated temporary password and emailed to them —
     * clinics don't need one (Clinic::clinic_admin_id is nullable), so an
     * admin attaches a clinic_admin later via the Clinic resource.
     */
    protected function approve(ProviderApplication $record): void
    {
        DB::transaction(function () use ($record): void {
            match ($record->type) {
                ProviderApplication::TYPE_CLINIC => $this->approveClinic($record),
                ProviderApplication::TYPE_DOCTOR => $this->approveDoctor($record),
                ProviderApplication::TYPE_PHARMACY => $this->approvePharmacy($record),
                default => $this->approveManually($record),
            };

            $record->update([
                'status' => ProviderApplication::STATUS_APPROVED,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
            ]);
        });
    }

    protected function approveClinic(ProviderApplication $record): void
    {
        $clinic = Clinic::create([
            'name' => $record->business_name,
            'slug' => $this->uniqueSlug(Clinic::class, $record->business_name),
            'phone' => $record->phone,
            'email' => $record->email,
            'address' => 'To be confirmed',
            'status' => 'active',
        ]);

        Notification::make()
            ->title('Clinic created')
            ->body("\"{$clinic->name}\" is now live with placeholder details. Visit its profile to fill in the address, hours, specialties and assign a clinic admin.")
            ->success()
            ->send();
    }

    protected function approveDoctor(ProviderApplication $record): void
    {
        $user = $this->createOrReuseUser($record, User::ROLE_DOCTOR);

        if ($user->doctorProfile) {
            Notification::make()
                ->title('Doctor profile already exists')
                ->body("{$record->email} already has a doctor profile — nothing new was created.")
                ->warning()
                ->send();

            return;
        }

        DoctorProfile::create([
            'user_id' => $user->id,
            'specialization' => 'General practice',
            'registration_no' => $record->hpcsa_sapc_no ?: $record->registration_no,
            'status' => 'active',
            'is_accepting_appointments' => false,
        ]);

        Notification::make()
            ->title('Doctor profile created')
            ->body("A login was created for {$record->email} and a temporary password was emailed to them. Visit their profile to fill in specialization, fees and availability before they start accepting bookings.")
            ->success()
            ->send();
    }

    protected function approvePharmacy(ProviderApplication $record): void
    {
        $user = $this->createOrReuseUser($record, User::ROLE_PHARMACY_ADMIN);

        $pharmacy = Pharmacy::create([
            'vendor_id' => $user->id,
            'name' => $record->business_name,
            'slug' => $this->uniqueSlug(Pharmacy::class, $record->business_name),
            'phone' => $record->phone,
            'email' => $record->email,
            'status' => 'active',
        ]);

        Notification::make()
            ->title('Pharmacy created')
            ->body("\"{$pharmacy->name}\" is now live with placeholder details, and a login was emailed to {$record->email}. Visit its profile to fill in the address, delivery fee and product catalogue.")
            ->success()
            ->send();
    }

    protected function approveManually(ProviderApplication $record): void
    {
        Notification::make()
            ->title('Marked approved')
            ->body("This provider type needs a manual record — create it via the matching resource, using {$record->business_name} / {$record->email} as a starting point.")
            ->warning()
            ->send();
    }

    /**
     * Reuses an existing User by email rather than risking a duplicate
     * account (and a unique-constraint failure) if this applicant already
     * has a login. Only creates + emails a temporary password when new.
     *
     * @throws RuntimeException if an existing account exists under a
     *         different role — thrown (rather than a notification + null)
     *         so it unwinds the whole DB::transaction in approve(), leaving
     *         the application record at its original status instead of
     *         being marked approved with nothing actually created.
     */
    protected function createOrReuseUser(ProviderApplication $record, string $role): User
    {
        $existing = User::query()->where('email', $record->email)->first();

        if ($existing) {
            // Reusing an account is only safe when it's already the right
            // kind of account — silently attaching a DoctorProfile/Pharmacy
            // to e.g. an existing patient login would leave them unable to
            // reach it (role still 'patient') while quietly exposing
            // clinical/vendor data under an account nobody's watching for it.
            if ($existing->role !== $role) {
                throw new RuntimeException("{$record->email} already has a '{$existing->role}' account — approving would attach a {$role} profile to the wrong kind of login. Resolve this manually (e.g. a different email, or change that account's role first) before approving.");
            }

            return $existing;
        }

        $temporaryPassword = Str::password(16);

        $user = User::create([
            'name' => $record->contact_name,
            'email' => $record->email,
            'phone' => $record->phone,
            'password' => Hash::make($temporaryPassword),
            'role' => $role,
            'status' => 'active',
        ]);

        app(NotificationDispatcherInterface::class)->email(
            $user,
            'Your Prompt Aid account is ready',
            "Hi {$record->contact_name}, your provider application for {$record->business_name} was approved.\n\nLogin email: {$record->email}\nTemporary password: {$temporaryPassword}\n\nPlease log in and change your password as soon as possible.",
        );

        return $user;
    }

    protected function uniqueSlug(string $modelClass, string $name): string
    {
        $base = Str::slug($name) ?: 'provider';
        $slug = $base;
        $suffix = 1;

        while ($modelClass::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-".(++$suffix);
        }

        return $slug;
    }
}
