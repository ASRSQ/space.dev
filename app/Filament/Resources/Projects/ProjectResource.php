<?php

namespace App\Filament\Resources\Projects;

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ProjectDocumentation;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Project;
use App\Models\User;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?string $navigationLabel = 'Projetos';

    protected static ?string $modelLabel = 'projeto';

    protected static ?string $pluralModelLabel = 'Projetos';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do projeto')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(150),
                    TextInput::make('slug')
                        ->label('Identificador')
                        ->required()
                        ->maxLength(150)
                        ->unique(ignoreRecord: true),
                    Textarea::make('description')
                        ->label('Descrição')
                        ->rows(4)
                        ->columnSpanFull(),
                    TextInput::make('repository_url')
                        ->label('URL do repositório')
                        ->url()
                        ->maxLength(255),
                    Select::make('owner_user_id')
                        ->label('Proprietário')
                        ->relationship(name: 'owner', titleAttribute: 'name')
                        ->getOptionLabelFromRecordUsing(fn (User $record): string => "{$record->name} (#{$record->getKey()})")
                        ->searchable()
                        ->required(),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do projeto')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label('Nome'),
                    TextEntry::make('slug')->label('Identificador'),
                    TextEntry::make('description')->label('Descrição')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('repository_url')->label('URL do repositório')->placeholder('—'),
                    TextEntry::make('owner.name')->label('Proprietário'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => self::statusLabel($state)),
                    TextEntry::make('archive_condition')
                        ->label('Condição de arquivamento')
                        ->state(fn (Project $record): string => $record->isArchived() ? 'Arquivado' : 'Não arquivado'),
                    TextEntry::make('is_archived')
                        ->label('Marcador is_archived')
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Sim' : 'Não'),
                    TextEntry::make('archived_at')->label('archived_at')->dateTime()->placeholder('—'),
                    TextEntry::make('created_at')->label('Criado em')->dateTime(),
                    TextEntry::make('updated_at')->label('Atualizado em')->dateTime(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Identificador')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('owner.name')
                    ->label('Proprietário')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'paused' => 'warning',
                        'archived' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('archive_condition')
                    ->label('Condição de arquivamento')
                    ->state(fn (Project $record): string => $record->isArchived() ? 'Arquivado' : 'Não arquivado')
                    ->badge()
                    ->color(fn (Project $record): string => $record->isArchived() ? 'gray' : 'success'),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions()),
            ])
            ->recordActions([
                Action::make('pause')
                    ->label('Pausar')
                    ->color('warning')
                    ->visible(fn (Project $record): bool => $record->canBePaused())
                    ->action(fn (Project $record) => self::performLifecycleTransition($record, 'pause', 'Projeto pausado.')),
                Action::make('activate')
                    ->label('Ativar')
                    ->color('success')
                    ->visible(fn (Project $record): bool => $record->canBeActivated())
                    ->action(fn (Project $record) => self::performLifecycleTransition($record, 'activate', 'Projeto ativado.')),
                Action::make('archive')
                    ->label('Arquivar')
                    ->color('gray')
                    ->visible(fn (Project $record): bool => $record->canBeArchived())
                    ->requiresConfirmation()
                    ->modalDescription('O projeto e seus registros relacionados serão preservados. Esta ação apenas atualiza o status e os marcadores de arquivamento.')
                    ->action(fn (Project $record) => self::performLifecycleTransition($record, 'archive', 'Projeto arquivado.')),
                Action::make('restore')
                    ->label('Restaurar')
                    ->color('success')
                    ->visible(fn (Project $record): bool => $record->canBeRestored())
                    ->requiresConfirmation()
                    ->modalDescription('O projeto será marcado como ativo e os marcadores de arquivamento serão limpos.')
                    ->action(fn (Project $record) => self::performLifecycleTransition($record, 'restore', 'Projeto restaurado.')),
                ViewAction::make()->label('Visualizar'),
                EditAction::make()->label('Editar'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'view' => ViewProject::route('/{record}'),
            'documentation' => ProjectDocumentation::route('/{record}/documentation'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return [
            'active' => 'Ativo',
            'paused' => 'Pausado',
            'archived' => 'Arquivado',
        ];
    }

    private static function statusLabel(string $status): string
    {
        return self::statusOptions()[$status] ?? $status;
    }

    private static function performLifecycleTransition(Project $project, string $transition, string $successTitle): void
    {
        try {
            $project->{$transition}();

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (DomainException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
