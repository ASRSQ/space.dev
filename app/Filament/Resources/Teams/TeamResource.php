<?php

namespace App\Filament\Resources\Teams;

use App\Filament\Resources\Organizations\OrganizationResource;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\OrganizationAdministrationService;
use App\Services\ProjectAccess;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static ?string $navigationLabel = 'Equipes';

    protected static ?string $modelLabel = 'equipe';

    protected static ?string $pluralModelLabel = 'Equipes';

    protected static string|\UnitEnum|null $navigationGroup = 'Administração';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('organization', fn ($q) => $q->when(! app(ProjectAccess::class)->platform(auth()->user()), fn ($q) => $q->whereNull('archived_at')->whereHas('memberships', fn ($m) => $m->where('user_id', auth()->id())->where('status', 'active'))));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('organization_id')->label('Organização')->options(fn () => OrganizationResource::getEloquentQuery()->get()->filter(fn ($org) => Gate::allows('update', $org) && ! $org->archived_at)->pluck('name', 'id'))->live()->required(),
            TextInput::make('name')->label('Nome')->maxLength(150)->required(),
            Select::make('lead_user_id')->label('Responsável')->searchable()->options(fn (Get $get) => User::query()->where('status', 'active')->whereHas('organizationMemberships', fn ($m) => $m->where('organization_id', $get('organization_id'))->where('status', 'active')->whereHas('organization', fn ($q) => $q->whereIn('id', OrganizationResource::getEloquentQuery()->select('id'))))->pluck('name', 'id')),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('name')->label('Equipe'), TextEntry::make('organization.name')->label('Organização'), TextEntry::make('lead.name')->label('Responsável'),
            RepeatableEntry::make('memberships')->label('Membros')->schema([TextEntry::make('user.name')->label('Nome'), TextEntry::make('status')->label('Estado')]),
            RepeatableEntry::make('projects')->label('Projetos associados (sem concessão automática de acesso)')->state(fn ($record) => $record->projects()->visibleTo(auth()->user())->get())->schema([TextEntry::make('name')->label('Projeto')]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Equipe')->searchable(), TextColumn::make('organization.name')->label('Organização'), TextColumn::make('lead.name')->label('Responsável')])->recordActions([
            ViewAction::make(), EditAction::make()->using(fn ($record, array $data) => app(OrganizationAdministrationService::class)->saveTeam($data, $record)),
            Action::make('member')->label('Gerenciar membro')->visible(fn ($record) => Gate::allows('update', $record))->schema([
                Select::make('user_id')->label('Usuário')->required()->searchable()->options(fn ($record) => User::query()->whereHas('organizationMemberships', fn ($m) => $m->where('organization_id', $record->organization_id)->where('status', 'active'))->pluck('name', 'id')),
                Select::make('status')->label('Estado')->required()->options(['active' => 'Ativo', 'revoked' => 'Revogado']),
            ])->action(fn ($record, array $data) => app(OrganizationAdministrationService::class)->setTeamMember($record, (int) $data['user_id'], $data['status'])),
            Action::make('project')->label('Associar/remover projeto')->visible(fn ($record) => Gate::allows('update', $record))->schema([
                Select::make('project_id')->label('Projeto')->required()->options(fn ($record) => Project::query()->visibleTo(auth()->user())->where('organization_id', $record->organization_id)->pluck('name', 'id')),
                Select::make('operation')->label('Operação')->required()->options(['attach' => 'Associar', 'detach' => 'Remover associação']),
            ])->action(fn ($record, array $data) => app(OrganizationAdministrationService::class)->linkTeamProject($record, (int) $data['project_id'], $data['operation'] === 'attach')),
            Action::make('archive')->label('Arquivar')->requiresConfirmation()->visible(fn ($record) => ! $record->archived_at && Gate::allows('update', $record))->action(fn ($record) => app(OrganizationAdministrationService::class)->archiveTeam($record, true)),
            Action::make('restore')->label('Restaurar')->visible(fn ($record) => $record->archived_at && Gate::allows('update', $record))->action(fn ($record) => app(OrganizationAdministrationService::class)->archiveTeam($record, false)),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTeams::route('/')];
    }
}
