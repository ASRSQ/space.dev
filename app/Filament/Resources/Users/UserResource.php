<?php

namespace App\Filament\Resources\Users;

use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'Usuários';

    protected static ?string $modelLabel = 'usuário';

    protected static ?string $pluralModelLabel = 'Usuários';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Administração';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Conta')->columns(2)->schema([
            TextInput::make('name')->label('Nome')->required()->maxLength(150),
            TextInput::make('email')->label('E-mail')->email()->required()->maxLength(191)->unique(ignoreRecord: true),
            Select::make('role_id')->label('Papel global')->options(fn () => Role::query()->when(auth()->user()?->role?->slug !== 'super-admin', fn ($q) => $q->where('slug', '!=', 'super-admin'))->pluck('name', 'id'))->required()
                ->helperText('Programador e editor podem coexistir no papel contextual do projeto. Não é necessário conceder administração global.'),
            Select::make('status')->label('Estado')->options(['active' => 'Ativo', 'inactive' => 'Inativo', 'suspended' => 'Suspenso'])->default('active')->required(),
            TextInput::make('password')->label('Nova senha')->password()->revealable()->autocomplete('new-password')->required(fn (string $operation) => $operation === 'create')->minLength(12)->confirmed()
                ->rule(Password::min(12)->mixedCase()->numbers()->symbols())
                ->helperText('Mínimo de 12 caracteres, maiúscula, minúscula, número e símbolo. Deixe em branco para manter a senha atual.')
                ->afterStateHydrated(fn ($component) => $component->state(null)),
            TextInput::make('password_confirmation')->label('Confirmar nova senha')->password()->autocomplete('new-password')->afterStateHydrated(fn ($component) => $component->state(null)),
        ])]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('Nome'), TextEntry::make('email')->label('E-mail'), TextEntry::make('role.name')->label('Papel global'), TextEntry::make('status')->label('Estado'),
            RepeatableEntry::make('projectMemberships')->label('Participações e histórico')->schema([TextEntry::make('project.name')->label('Projeto'), TextEntry::make('role_in_project')->label('Papel'), TextEntry::make('status')->label('Estado')]),
            RepeatableEntry::make('assignedTasks')->label('Tarefas atribuídas')->schema([TextEntry::make('title')->label('Tarefa'), TextEntry::make('project.name')->label('Projeto'), TextEntry::make('status')->label('Estado')]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Nome')->searchable(), TextColumn::make('email')->label('E-mail')->searchable(), TextColumn::make('role.name')->label('Papel global'), TextColumn::make('status')->label('Estado')->badge()])
            ->filters([SelectFilter::make('team')->label('Equipe')->options(fn () => Team::query()->whereNull('archived_at')->pluck('name', 'id'))->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $id) => $q->whereHas('teamMemberships', fn ($m) => $m->where('team_id', $id)->where('status', 'active'))))])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUsers::route('/'), 'create' => Pages\CreateUser::route('/create'), 'view' => Pages\ViewUser::route('/{record}'), 'edit' => Pages\EditUser::route('/{record}/edit')];
    }
}
