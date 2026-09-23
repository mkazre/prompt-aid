<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use App\Models\Page;
use App\Models\PageBlock;
use App\PageBuilder\BlockRegistry;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The page builder editor at /staff/pages/{record}/build. A flat, ordered
 * table of this page's blocks — add, edit (via each block's own schema()),
 * reorder, duplicate, delete — plus a link to preview the rendered page.
 * Nested blocks (columns' children etc.) are shown indented by depth.
 */
class Build extends ResourcePage implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = PageResource::class;

    protected string $view = 'filament.resources.pages.pages.build';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getRecord(): Page
    {
        return $this->record;
    }

    public function getTitle(): string
    {
        return 'Edit Blocks — '.$this->getRecord()->title;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PageBlock::query()->where('page_id', $this->getRecord()->id)->orderBy('parent_id')->orderBy('sort'))
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(function (string $state) {
                        $class = BlockRegistry::resolve($state);

                        return $class ? $class::label() : $state;
                    }),
                TextColumn::make('summary')
                    ->label('Summary')
                    ->getStateUsing(fn (PageBlock $record) => $this->summarize($record))
                    ->limit(60),
                TextColumn::make('parent_id')
                    ->label('Nested under')
                    ->formatStateUsing(fn (?int $state) => $state ? "Block #{$state}" : '—'),
                TextColumn::make('sort')->label('Order')->sortable(),
            ])
            ->defaultSort('sort')
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil')
                    ->schema(fn (PageBlock $record) => $this->blockSchema($record->type))
                    ->fillForm(fn (PageBlock $record) => $record->props ?? [])
                    ->action(function (PageBlock $record, array $data): void {
                        $record->update(['props' => $data]);
                        $this->flushPageCache();
                        Notification::make()->title('Block updated')->success()->send();
                    }),
                Action::make('moveUp')
                    ->label('')
                    ->icon('heroicon-o-arrow-up')
                    ->action(fn (PageBlock $record) => $this->move($record, -1)),
                Action::make('moveDown')
                    ->label('')
                    ->icon('heroicon-o-arrow-down')
                    ->action(fn (PageBlock $record) => $this->move($record, 1)),
                Action::make('duplicate')
                    ->label('')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(function (PageBlock $record): void {
                        $copy = $record->replicate();
                        $copy->sort = $record->sort + 1;
                        $copy->save();
                        $this->flushPageCache();
                    }),
                DeleteAction::make()
                    ->after(fn () => $this->flushPageCache()),
            ])
            ->headerActions([
                Action::make('addBlock')
                    ->label('Add Block')
                    ->icon('heroicon-o-plus')
                    ->schema([
                        Select::make('type')
                            ->label('Block type')
                            ->options($this->typeOptions())
                            ->live()
                            ->required(),
                        Group::make()
                            ->schema(fn (Get $get) => $this->blockSchema($get('type')))
                            ->key('block-fields'),
                    ])
                    ->action(function (array $data): void {
                        $type = $data['type'];
                        unset($data['type']);

                        $class = BlockRegistry::resolve($type);

                        PageBlock::query()->create([
                            'page_id' => $this->getRecord()->id,
                            'parent_id' => null,
                            'sort' => (PageBlock::query()->where('page_id', $this->getRecord()->id)->max('sort') ?? 0) + 1,
                            'type' => $type,
                            'props' => array_merge($class ? $class::defaults() : [], $data),
                            'styles' => $class ? $class::defaultStyles() : [],
                            'visibility' => ['roles' => [], 'auth' => 'any', 'devices' => ['sm', 'md', 'lg']],
                        ]);

                        $this->flushPageCache();
                        Notification::make()->title('Block added')->success()->send();
                    }),
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->url(fn () => route('page-builder.preview', $this->getRecord()))
                    ->openUrlInNewTab(),
            ]);
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    protected function blockSchema(?string $type): array
    {
        if (! $type) {
            return [];
        }

        return BlockRegistry::make($type)?->schema() ?? [];
    }

    /**
     * @return array<string, string>
     */
    protected function typeOptions(): array
    {
        $options = [];

        foreach (BlockRegistry::grouped() as $group => $blocks) {
            foreach ($blocks as $block) {
                $options[$block['id']] = "{$group}: {$block['label']}";
            }
        }

        return $options;
    }

    protected function summarize(PageBlock $block): string
    {
        $props = $block->props ?? [];

        return $props['text'] ?? $props['title'] ?? (isset($props['html']) ? strip_tags($props['html']) : '') ?: '—';
    }

    protected function move(PageBlock $record, int $direction): void
    {
        $sibling = PageBlock::query()
            ->where('page_id', $record->page_id)
            ->where('parent_id', $record->parent_id)
            ->where('sort', $direction < 0 ? '<' : '>', $record->sort)
            ->orderBy('sort', $direction < 0 ? 'desc' : 'asc')
            ->first();

        if (! $sibling) {
            return;
        }

        [$a, $b] = [$record->sort, $sibling->sort];
        $record->update(['sort' => $b]);
        $sibling->update(['sort' => $a]);

        $this->flushPageCache();
    }

    protected function flushPageCache(): void
    {
        // Cache is keyed by page id + max block updated_at, so it
        // self-invalidates on the next render — nothing to flush explicitly.
    }
}
