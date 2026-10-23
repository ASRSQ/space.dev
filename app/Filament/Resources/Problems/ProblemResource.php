<?php

namespace App\Filament\Resources\Problems;

use App\Filament\Resources\Problems\Pages\CreateProblem;
use App\Filament\Resources\Problems\Pages\EditProblem;
use App\Filament\Resources\Problems\Pages\ListProblems;
use App\Filament\Resources\Problems\Pages\ViewProblem;
use App\Models\Problem;
use App\Models\Project;
use App\Support\TaskMarkdown;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

class ProblemResource extends Resource
{
    protected static ?string $model = Problem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Erros';

    protected static ?string $modelLabel = 'erro';

    protected static ?string $pluralModelLabel = 'Erros';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do erro')->columns(2)->schema([
                Select::make('project_id')->label('Projeto')->searchable()->required()
                    ->options(fn (string $operation): array => Project::query()
                        ->when($operation === 'create', fn ($query) => $query->where('status', 'active')->where('is_archived', false)->whereNull('archived_at'))
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->disabledOn('edit')->dehydrated(fn (string $operation): bool => $operation === 'create')
                    ->rules(['integer', Rule::exists('projects', 'id')]),
                TextInput::make('title')->label('Título')->required()->maxLength(200),
                Select::make('severity')->label('Gravidade')->options(self::severityOptions())->default('medium')->required()->rules([Rule::in(array_keys(self::severityOptions()))]),
                Select::make('status')->label('Status')->options(self::statusOptions())->default('open')->required()->rules([Rule::in(array_keys(self::statusOptions()))]),
                MarkdownEditor::make('description')->label('Descrição')->required()->fileAttachments(false)
                    ->commonMarkOptions(TaskMarkdown::OPTIONS)->live(onBlur: true)->columnSpanFull(),
                View::make('filament.tasks.description-preview')->viewData(fn (Get $get): array => ['markdown' => $get('description')])->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do erro')->columns(2)->schema([
                TextEntry::make('title')->label('Título'),
                TextEntry::make('project.name')->label('Projeto'),
                TextEntry::make('severity')->label('Gravidade')->formatStateUsing(fn (string $state): string => self::severityOptions()[$state] ?? $state),
                TextEntry::make('status')->label('Status')->formatStateUsing(fn (string $state): string => self::statusOptions()[$state] ?? $state),
                TextEntry::make('reporterUser.name')->label('Relator'),
                TextEntry::make('description')->label('Descrição')->view('filament.tasks.description')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('Título')->searchable(),
            TextColumn::make('project.name')->label('Projeto'),
            TextColumn::make('severity')->label('Gravidade')->formatStateUsing(fn (string $state): string => self::severityOptions()[$state] ?? $state),
            TextColumn::make('status')->label('Status')->formatStateUsing(fn (string $state): string => self::statusOptions()[$state] ?? $state),
        ])->recordActions([ViewAction::make(), EditAction::make()])->defaultSort('updated_at', 'desc');
    }

    public static function severityOptions(): array
    {
        return ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta', 'critical' => 'Crítica'];
    }

    public static function statusOptions(): array
    {
        return ['open' => 'Aberto', 'diagnosed' => 'Diagnosticado', 'resolved' => 'Resolvido', 'closed' => 'Fechado'];
    }

    public static function getPages(): array
    {
        return ['index' => ListProblems::route('/'), 'create' => CreateProblem::route('/create'), 'view' => ViewProblem::route('/{record}'), 'edit' => EditProblem::route('/{record}/edit')];
    }
}
