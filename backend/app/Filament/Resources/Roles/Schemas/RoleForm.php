<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Support\Permissions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * One CheckboxList per navigation group (Clinical, Billing, ...), each a
 * separate form field named `perm__{group-slug}` — Role has no real column
 * to bind these to, so the Create/Edit Role pages pull every `perm__*`
 * field back out of the submitted data and sync it into role_permissions
 * themselves (see handleRecordCreation/handleRecordUpdate on those pages).
 */
class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->live(onBlur: true)
                ->afterStateUpdated(function (string $operation, $state, callable $set) {
                    if ($operation === 'create') {
                        $set('slug', Str::slug($state));
                    }
                }),
            TextInput::make('slug')->required()->maxLength(255)->unique(ignoreRecord: true),
            Toggle::make('is_system')
                ->label('Built-in system role')
                ->helperText('Set automatically for the roles seeded from the legacy user role list — leave off for custom roles.')
                ->disabled()
                ->dehydrated(),
            ...self::permissionSections(),
        ]);
    }

    /**
     * @return array<int, Section>
     */
    protected static function permissionSections(): array
    {
        $sections = [];

        foreach (Permissions::grouped() as $group => $keys) {
            $sections[] = Section::make($group)
                ->columns(1)
                ->schema([
                    CheckboxList::make(self::fieldNameForGroup($group))
                        ->hiddenLabel()
                        ->columns(2)
                        ->options(collect($keys)->mapWithKeys(fn (string $key) => [$key => self::humanizeKey($key)])->all()),
                ]);
        }

        return $sections;
    }

    public static function fieldNameForGroup(string $group): string
    {
        return 'perm__'.Str::slug($group);
    }

    protected static function humanizeKey(string $key): string
    {
        [$prefix, $action] = array_pad(explode('.', $key, 2), 2, '');

        return Str::headline($prefix).' — '.Str::headline($action);
    }
}
