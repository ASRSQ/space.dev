<?php

namespace App\Filament\Resources\Tasks;

use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\TaskKanban;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskAssignmentService;
use App\Services\TaskLifecycleService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Tarefas';

    protected static ?string $modelLabel = 'tarefa';

    protected static ?string $pluralModelLabel = 'Tarefas';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da tarefa')
                ->columns(2)
                ->schema([
                    Select::make('project_id')
                        ->label('Projeto')
                        ->options(fn (string $operation): array => $operation === 'create'
                            ? app(TaskAssignmentService::class)->projectOptionsForTaskCreation()
                            : Project::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required()
                        ->live()
                        ->disabledOn('edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->rules(['required', 'integer', Rule::exists('projects', 'id')]),
                    TextInput::make('title')
                        ->label('Título')
                        ->required()
                        ->maxLength(200),
                    Select::make('task_type')
                        ->label('Tipo')
                        ->options(self::typeOptions())
                        ->default('feature')
                        ->required()
                        ->rules(['required', Rule::in(Task::TYPES)]),
                    Select::make('priority')
                        ->label('Prioridade')
                        ->options(self::priorityOptions())
                        ->default('medium')
                        ->required()
                        ->rules(['required', Rule::in(Task::PRIORITIES)]),
                    Select::make('assigned_user_id')
                        ->label('Responsável')
                        ->options(fn (Get $get): array => app(TaskAssignmentService::class)
                            ->optionsForProject(filled($get('project_id')) ? (int) $get('project_id') : null))
                        ->searchable()
                        ->placeholder('Sem responsável')
                        ->rules(['nullable', 'integer']),
                    ...self::deadlineFields(),
                    Textarea::make('description')
                        ->label('Descrição')
                        ->rows(5)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da tarefa')
                ->columns(2)
                ->schema([
                    TextEntry::make('title')->label('Título'),
                    TextEntry::make('project.name')->label('Projeto'),
                    TextEntry::make('task_type')->label('Tipo')->formatStateUsing(fn (string $state): string => self::typeOptions()[$state] ?? $state),
                    TextEntry::make('priority')->label('Prioridade')->formatStateUsing(fn (string $state): string => self::priorityOptions()[$state] ?? $state),
                    TextEntry::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => self::statusOptions()[$state] ?? $state),
                    TextEntry::make('assignedUser.name')->label('Responsável')->placeholder('Sem responsável'),
                    TextEntry::make('reporter.name')->label('Relator'),
                    TextEntry::make('description')->label('Descrição')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('is_archived')->label('Arquivada')->formatStateUsing(fn (bool $state): string => $state ? 'Sim' : 'Não'),
                    TextEntry::make('archived_at')->label('Arquivada em')->dateTime()->placeholder('—'),
                    TextEntry::make('created_at')->label('Criada em')->dateTime(),
                    TextEntry::make('updated_at')->label('Atualizada em')->dateTime(),
                ]),
            Section::make('Prazos e acompanhamento')->columns(2)->schema([
                TextEntry::make('start_date')->label('Data de início')->date('d/m/Y')->placeholder('Não definida'),
                TextEntry::make('due_date')->label('Data de entrega')->date('d/m/Y')->placeholder('Não definida'),
                TextEntry::make('completed_at')->label('Data de conclusão')->dateTime('d/m/Y H:i')->placeholder('Não definida'),
                TextEntry::make('days_remaining')->label('Dias restantes')->placeholder('Não se aplica'),
                TextEntry::make('deadline_status')->label('Situação do prazo')->badge(),
            ]),
            Section::make('Comentários')
                ->schema([
                    RepeatableEntry::make('comments')
                        ->label('Comentários')
                        ->schema([
                            TextEntry::make('user.name')->label('Autor'),
                            TextEntry::make('comment_text')->label('Comentário'),
                            TextEntry::make('created_at')->label('Criado em')->dateTime(),
                        ]),
                ]),
            Section::make('Relações entre tarefas')
                ->schema([
                    RepeatableEntry::make('outgoingRelations')
                        ->label('Relações de saída')
                        ->schema([
                            TextEntry::make('relation_type')->label('Tipo'),
                            TextEntry::make('targetTask.title')->label('Tarefa relacionada'),
                        ]),
                    RepeatableEntry::make('incomingRelations')
                        ->label('Relações de entrada')
                        ->schema([
                            TextEntry::make('relation_type')->label('Tipo'),
                            TextEntry::make('sourceTask.title')->label('Tarefa relacionada'),
                        ]),
                ]),
            Section::make('Histórico de status')
                ->schema([
                    RepeatableEntry::make('statusHistories')
                        ->label('Alterações')
                        ->schema([
                            TextEntry::make('old_status')->label('Status anterior'),
                            TextEntry::make('new_status')->label('Novo status'),
                            TextEntry::make('changedByUser.name')->label('Alterado por'),
                            TextEntry::make('reason')->label('Motivo')->placeholder('—'),
                            TextEntry::make('created_at')->label('Data')->dateTime(),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Título')->searchable()->sortable(),
                TextColumn::make('project.name')->label('Projeto')->sortable(),
                TextColumn::make('assignedUser.name')->label('Responsável')->placeholder('—'),
                TextColumn::make('reporter.name')->label('Relator'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusOptions()[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('priority')->label('Prioridade')->formatStateUsing(fn (string $state): string => self::priorityOptions()[$state] ?? $state)->sortable(),
                TextColumn::make('task_type')->label('Tipo')->formatStateUsing(fn (string $state): string => self::typeOptions()[$state] ?? $state)->sortable(),
                TextColumn::make('due_date')->label('Entrega')->date('d/m/Y')->placeholder('Não definida')->sortable(),
                TextColumn::make('deadline_status')->label('Prazo')->badge(),
                TextColumn::make('updated_at')->label('Atualizada em')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('deadline')->label('Prazo')->options(self::deadlineFilterOptions())
                    ->query(fn ($query, array $data) => $query->deadline($data['value'] ?? null)),
                SelectFilter::make('project_id')->label('Projeto')->relationship('project', 'name'),
                SelectFilter::make('assigned_user_id')->label('Responsável')->relationship('assignedUser', 'name'),
                SelectFilter::make('status')->label('Status')->options(self::statusOptions()),
                SelectFilter::make('priority')->label('Prioridade')->options(self::priorityOptions()),
                SelectFilter::make('task_type')->label('Tipo')->options(self::typeOptions()),
            ])
            ->recordActions([
                Action::make('changeStatus')
                    ->label('Alterar status')
                    ->schema([
                        Select::make('new_status')
                            ->label('Novo status')
                            ->options(self::operationalStatusOptions())
                            ->required()
                            ->rules(['required', Rule::in(TaskLifecycleService::OPERATIONAL_STATUSES)]),
                        TextInput::make('reason')->label('Motivo')->maxLength(255),
                    ])
                    ->authorize(fn (Task $record): bool => Gate::allows('update', $record))
                    ->visible(fn (Task $record): bool => self::canMutate($record) && ! $record->isArchived())
                    ->action(function (Task $record, array $data): void {
                        Gate::authorize('update', $record);

                        try {
                            app(TaskLifecycleService::class)->transitionTo(
                                $record,
                                (string) ($data['new_status'] ?? ''),
                                $data['reason'] ?? null,
                            );
                        } catch (DomainException) {
                            self::notifyLifecycleFailure();
                        }
                    }),
                Action::make('archive')
                    ->label('Arquivar')
                    ->requiresConfirmation()
                    ->authorize(fn (Task $record): bool => Gate::allows('update', $record))
                    ->visible(fn (Task $record): bool => self::canMutate($record)
                        && ! $record->isArchived()
                        && in_array($record->status, TaskLifecycleService::OPERATIONAL_STATUSES, true))
                    ->action(function (Task $record): void {
                        Gate::authorize('update', $record);

                        try {
                            app(TaskLifecycleService::class)->archive($record);
                        } catch (DomainException) {
                            self::notifyLifecycleFailure();
                        }
                    }),
                Action::make('restore')
                    ->label('Restaurar')
                    ->requiresConfirmation()
                    ->authorize(fn (Task $record): bool => Gate::allows('update', $record))
                    ->visible(fn (Task $record): bool => self::canMutate($record) && $record->isArchived())
                    ->action(function (Task $record): void {
                        Gate::authorize('update', $record);

                        try {
                            app(TaskLifecycleService::class)->restore($record);
                        } catch (DomainException) {
                            self::notifyLifecycleFailure();
                        }
                    }),
                ViewAction::make()->label('Visualizar')->authorize(fn (Task $record): bool => Gate::allows('view', $record)),
                EditAction::make()->label('Editar')->authorize(fn (Task $record): bool => Gate::allows('update', $record)),
            ])
            ->defaultSort('updated_at', 'desc')
            ->paginationPageOptions([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return [
            'index' => TaskKanban::route('/'),
            'list' => Pages\ListTasks::route('/list'),
            'create' => CreateTask::route('/create'),
            'view' => ViewTask::route('/{record}'),
            'edit' => EditTask::route('/{record}/edit'),
        ];
    }

    public static function deadlineFields(): array
    {
        return [
            DatePicker::make('start_date')->label('Data de início')->live()->nullable(),
            DatePicker::make('due_date')->label('Data de entrega')->nullable()
                ->afterOrEqual(fn (Get $get): ?string => filled($get('start_date')) ? $get('start_date') : null),
        ];
    }

    public static function deadlineFilterOptions(): array
    {
        return ['overdue' => 'Atrasadas', 'none' => 'Sem prazo', 'soon' => 'Prazo próximo (até 7 dias)'];
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            'todo' => 'A fazer',
            'in_progress' => 'Em andamento',
            'review' => 'Em revisão',
            'done' => 'Concluída',
            'archived' => 'Arquivada',
        ];
    }

    /** @return array<string, string> */
    private static function operationalStatusOptions(): array
    {
        return array_intersect_key(self::statusOptions(), array_flip(TaskLifecycleService::OPERATIONAL_STATUSES));
    }

    /** @return array<string, string> */
    public static function priorityOptions(): array
    {
        return ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta', 'urgent' => 'Urgente'];
    }

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return [
            'feature' => 'Funcionalidade',
            'bug' => 'Bug',
            'refactor' => 'Refatoração',
            'documentation' => 'Documentação',
            'chore' => 'Manutenção',
        ];
    }

    private static function canMutate(Task $task): bool
    {
        return ! $task->project->isArchived();
    }

    private static function notifyLifecycleFailure(): void
    {
        Notification::make()
            ->title('Transição não realizada')
            ->body('O estado atual da tarefa ou do projeto não permite essa operação.')
            ->warning()
            ->send();
    }
}
