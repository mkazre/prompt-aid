<?php

namespace App\Filament\Widgets;

use App\Models\DoctorProfile;
use App\Models\User;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

class TopDoctorsWidget extends TableWidget
{
    protected int | string | array $columnSpan = [
        'default' => 'full',
        'lg' => 1,
    ];

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return in_array(Auth::user()?->role, [User::ROLE_SUPER_ADMIN, User::ROLE_CLINIC_ADMIN], true);
    }

    public function table(Table $table): Table
    {
        /** @var User|null $user */
        $user = Auth::user();

        $query = DoctorProfile::query()
            ->where('status', 'active')
            ->withCount(['appointments' => fn ($q) => $q->where('status', 'completed')])
            ->with('user')
            ->orderByDesc('appointments_count');

        if ($user?->role === User::ROLE_CLINIC_ADMIN) {
            $clinicIds = $user->clinicsAdministered()->pluck('id');
            $query->whereHas('clinics', fn ($q) => $q->whereIn('clinics.id', $clinicIds));
        }

        return $table
            ->heading('Top Doctors')
            ->query($query)
            ->paginated(false)
            ->columns([
                ImageColumn::make('user.avatar')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn ($record) => 'https://i.pravatar.cc/150?u=doctor'.$record->id),
                TextColumn::make('user.name')->label('Doctor')->weight('bold'),
                TextColumn::make('specialization')->badge()->color('info'),
                TextColumn::make('appointments_count')->label('Patients Served')->sortable()->alignEnd(),
            ]);
    }
}
