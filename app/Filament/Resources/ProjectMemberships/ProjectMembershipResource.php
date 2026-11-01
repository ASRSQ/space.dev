<?php

namespace App\Filament\Resources\ProjectMemberships;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectAccess;
use App\Services\ProjectMembershipService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class ProjectMembershipResource extends Resource
{
    protected static ?string $model = ProjectMembership::class;

    protected static ?string $navigationLabel = 'Membros dos projetos';

    protected static ?string $modelLabel = 'participação';

    protected static ?string $pluralModelLabel = 'Membros dos projetos';

    protected static string|\UnitEnum|null $navigationGroup = 'Projetos';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('project', fn ($q) => $q->visibleTo(auth()->user()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('project_id')->label('Projeto')->options(fn () => Project::query()->visibleTo(auth()->user())->get()->filter(fn ($p) => Gate::allows('manageMembers', $p))->pluck('name', 'id'))->live()->searchable()->required(),
            Select::make('user_id')->label('Usuário')->options(function (Get $get): array {
                $project = Project::query()->visibleTo(auth()->user())->find($get('project_id'));
                if (! $project || Gate::denies('manageMembers', $project)) {
                    return [];
                }

                return User::query()->where('status', 'active')->when($project->organization_id, fn ($q) => $q->whereHas('organizationMemberships', fn ($m) => $m->where('organization_id', $project->organization_id)->where('status', 'active')))->orderBy('name')->get()->mapWithKeys(fn ($u) => [$u->id => "{$u->name} (#{$u->id})"])->all();
            })->searchable()->required()->helperText('O usuário deve estar ativo e, quando aplicável, ser membro ativo da organização. A inclusão habilita a elegibilidade no responsável das tarefas.'),
            Select::make('role_in_project')->label('Papel no projeto')->options(ProjectAccess::ROLES)->default('developer')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('project.name')->label('Projeto')->searchable(),
            TextColumn::make('user.name')->label('Usuário')->searchable(),
            TextColumn::make('role_in_project')->label('Papel')->formatStateUsing(fn ($state) => ProjectAccess::ROLES[$state] ?? $state),
            TextColumn::make('assigned_tasks')->label('Tarefas atribuídas')
                ->state(fn ($record) => Task::query()->where('project_id', $record->project_id)->where('assigned_user_id', $record->user_id)->count())
                ->url(fn ($record) => TaskResource::getUrl('list', ['tableFilters' => ['project_id' => ['value' => $record->project_id], 'assigned_user_id' => ['value' => $record->user_id]]])),
            TextColumn::make('can_edit_content')->label('Pode editar conteúdo')->state(fn ($record) => Gate::forUser($record->user)->allows('edit-project-documentation', $record->project) ? 'Sim' : 'Não'),
            TextColumn::make('can_report_problems')->label('Pode registrar problemas')->state(fn ($record) => app(ProjectAccess::class)->contribute($record->user, $record->project) ? 'Sim' : 'Não'),
            TextColumn::make('status')->label('Estado')->badge(),
            TextColumn::make('grantedByUser.name')->label('Concedido por'),
            TextColumn::make('revoked_at')->label('Revogado em')->dateTime(),
        ])->filters([
            SelectFilter::make('project_id')->label('Projeto')->options(fn () => Project::query()->visibleTo(auth()->user())->pluck('name', 'id')),
            SelectFilter::make('status')->label('Estado')->options(['active' => 'Ativos', 'revoked' => 'Revogados']),
        ])->recordActions([
            Action::make('changeRole')->label('Alterar papel')->visible(fn ($record) => $record->status === 'active' && Gate::allows('update', $record))
                ->schema([Select::make('role')->label('Papel')->options(ProjectAccess::ROLES)->required()])
                ->action(fn ($record, array $data) => app(ProjectMembershipService::class)->grant($record->project_id, $record->user_id, $data['role'])),
            Action::make('revoke')->label('Revogar')->requiresConfirmation()->visible(fn ($record) => $record->status === 'active' && Gate::allows('update', $record))
                ->action(fn ($record) => app(ProjectMembershipService::class)->revoke($record)),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageProjectMemberships::route('/')];
    }
}
