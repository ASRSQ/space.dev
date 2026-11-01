<?php

namespace App\Filament\Resources\Organizations;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\OrganizationAdministrationService;
use App\Services\ProjectAccess;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $navigationLabel = 'Organizações';

    protected static ?string $modelLabel = 'organização';

    protected static ?string $pluralModelLabel = 'Organizações';

    protected static string|\UnitEnum|null $navigationGroup = 'Administração';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->when(! app(ProjectAccess::class)->platform(auth()->user()), fn ($q) => $q->whereNull('archived_at')->whereHas('memberships', fn ($m) => $m->where('user_id', auth()->id())->where('status', 'active')));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->label('Nome')->required()->maxLength(150), TextInput::make('slug')->label('Identificador')->required()->alphaDash()->unique(ignoreRecord: true)->maxLength(150), Textarea::make('description')->label('Descrição')]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('name')->label('Nome'), TextEntry::make('description')->label('Descrição'),
            RepeatableEntry::make('memberships')->label('Membros')->schema([TextEntry::make('user.name')->label('Nome'), TextEntry::make('role')->label('Papel'), TextEntry::make('status')->label('Estado')]),
            RepeatableEntry::make('projects')->label('Projetos')->state(fn ($record) => $record->projects()->visibleTo(auth()->user())->get())->schema([TextEntry::make('name')->label('Nome')]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Organização')->searchable(), TextColumn::make('archived_at')->label('Arquivada em')->dateTime()])->recordActions([
            ViewAction::make(), EditAction::make()->using(fn ($record, array $data) => app(OrganizationAdministrationService::class)->save($data, $record)),
            Action::make('member')->label('Gerenciar membro')->visible(fn ($record) => Gate::allows('update', $record))->schema([
                Select::make('user_id')->label('Usuário')->searchable()->required()->options(fn ($record) => User::query()->when(! app(ProjectAccess::class)->platform(auth()->user()), fn ($q) => $q->whereHas('organizationMemberships', fn ($m) => $m->where('organization_id', $record->id)))->pluck('name', 'id')),
                Select::make('role')->label('Papel na organização')->options(['member' => 'Membro', 'admin' => 'Administrador da organização'])->required(),
                Select::make('status')->label('Estado')->options(['active' => 'Ativo', 'revoked' => 'Revogado'])->required(),
            ])->action(fn ($record, array $data) => app(OrganizationAdministrationService::class)->setMember($record->id, (int) $data['user_id'], $data['role'], $data['status'])),
            Action::make('linkProject')->label('Vincular projeto')->visible(fn ($record) => Gate::allows('update', $record))->schema([
                Select::make('project_id')->label('Projeto')->searchable()->required()->options(fn ($record) => Project::query()->visibleTo(auth()->user())->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $record->id))->get()->filter(fn ($p) => Gate::allows('manageMembers', $p))->pluck('name', 'id')),
            ])->action(fn ($record, array $data) => app(OrganizationAdministrationService::class)->linkProject($record, (int) $data['project_id'])),
            Action::make('archive')->label('Arquivar')->requiresConfirmation()->visible(fn ($record) => ! $record->archived_at && Gate::allows('update', $record))->action(fn ($record) => app(OrganizationAdministrationService::class)->archive($record, true)),
            Action::make('restore')->label('Restaurar')->requiresConfirmation()->visible(fn ($record) => $record->archived_at && Gate::allows('update', $record))->action(fn ($record) => app(OrganizationAdministrationService::class)->archive($record, false)),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageOrganizations::route('/')];
    }
}
