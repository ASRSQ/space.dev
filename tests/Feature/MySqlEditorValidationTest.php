<?php

namespace Tests\Feature;

use App\Filament\Resources\Problems\Pages\CreateProblem;
use App\Filament\Resources\Problems\Pages\EditProblem;
use App\Filament\Resources\Problems\Pages\ViewProblem;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Models\Problem;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskMarkdown;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MySqlEditorValidationTest extends TestCase
{
    private bool $transactionStarted = false;

    private ?string $views = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('EDITOR_MYSQL_VALIDATION') !== '1') {
            $this->markTestSkipped('Use tools/validate-editors-mysql.php to create an isolated local database.');
        }
        $database = getenv('EDITOR_MYSQL_DATABASE');
        $config = config('database.connections.mysql');
        $this->assertSame('testing', app()->environment());
        $this->assertContains($config['host'], ['127.0.0.1', 'localhost', '::1']);
        $this->assertEmpty($config['url']);
        $this->assertMatchesRegularExpression('/^spacedev_editor_validation_[0-9]{8}_[0-9]{6}_[a-f0-9]{8}$/', $database);
        $this->assertSame($database, $config['database']);
        config(['database.default' => 'mysql']);
        DB::setDefaultConnection('mysql');
        $this->assertSame($database, DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->views = sys_get_temp_dir().DIRECTORY_SEPARATOR.'spacedev-editor-mysql-'.bin2hex(random_bytes(5));
        mkdir($this->views, 0777, true);
        config(['view.compiled' => $this->views]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        Filament::setCurrentPanel('admin');
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            while (DB::connection('mysql')->transactionLevel() > 0) {
                DB::connection('mysql')->rollBack();
            }
        }
        if ($this->views) {
            (new Filesystem)->deleteDirectory($this->views);
        }
        DB::disconnect('editor_competing');
        parent::tearDown();
    }

    public static function editors(): array
    {
        return [
            'tasks' => [Task::class, CreateTask::class, EditTask::class, ViewTask::class],
            'problems' => [Problem::class, CreateProblem::class, EditProblem::class, ViewProblem::class],
        ];
    }

    #[DataProvider('editors')]
    public function test_create_edit_view_and_reload_persist_markdown_and_checkboxes(string $model, string $create, string $edit, string $view): void
    {
        [$admin, $project] = $this->fixtures();
        $markdown = "# Sintomas\r\n\r\n## Diagnóstico\r\n\r\n### Entregas\r\n\r\n**Forte** *ênfase* [link](https://example.test)\r\nquebra\r\n\r\n| A | B |\r\n|---|---|\r\n| 1 | 2 |\r\n\r\n```php\r\necho 'ok';\r\n```\r\n\r\n- [ ] Primeiro\r\n- [x] Segundo";
        $input = ['project_id' => $project->id, 'title' => 'Integração UTF-8', 'description' => $markdown];
        $input += $model === Task::class ? ['task_type' => 'bug', 'priority' => 'high'] : ['severity' => 'high', 'status' => 'open'];
        Livewire::test($create)->fillForm($input)->call('create')->assertHasNoFormErrors();
        $record = $model::query()->sole();
        $this->assertSame($admin->id, $record->reporter_user_id);
        $this->assertSame($markdown, $record->description);
        $page = Livewire::test($view, ['record' => $record->id]);
        foreach (['<h1>Sintomas</h1>', '<h2>', '<h3>', '<strong>', '<em>', '<table>', 'language-php', '<br>', 'type="checkbox"', 'checked=""', 'toggleChecklist'] as $fragment) {
            $page->assertSee($fragment, false);
        }
        $line = count(explode("\n", substr($markdown, 0, strpos($markdown, '- [ ] Primeiro'))));
        $page->call('toggleChecklist', $line, hash('sha256', $markdown), true);
        $updated = str_replace('[ ] Primeiro', '[x] Primeiro', $markdown);
        $this->assertSame($updated, $record->fresh()->description);
        // New component + HTTP GET simulate a new request; browser JS is a separate manual check.
        $this->get($model === Task::class ? '/admin/tasks/'.$record->id : '/admin/problems/'.$record->id)->assertOk();
        Livewire::test($view, ['record' => $record->id])->assertSee('checked=""', false)
            ->call('toggleChecklist', $line, hash('sha256', $updated), false);
        $this->assertSame($markdown, $record->fresh()->description);
        Livewire::test($edit, ['record' => $record->id])->assertFormSet(['description' => $markdown])
            ->fillForm(['title' => 'Editado'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Editado', $record->fresh()->title);
        $this->assertSame($markdown, $record->fresh()->description);
    }

    public function test_sc001_can_be_assigned_to_lauro_and_keeps_selection_on_edit(): void
    {
        [$admin, $project] = $this->fixtures();
        $lauro = $this->user('developer', 'Lauro');
        ProjectMembership::query()->create(['project_id' => $project->id, 'user_id' => $lauro->id, 'role_in_project' => 'contributor', 'status' => 'active', 'granted_by_user_id' => $admin->id]);
        Livewire::test(CreateTask::class)->fillForm(['project_id' => $project->id, 'title' => 'SC-001', 'task_type' => 'feature', 'priority' => 'medium', 'assigned_user_id' => $lauro->id])->call('create')->assertHasNoFormErrors();
        $task = Task::query()->sole();
        Livewire::test(EditTask::class, ['record' => $task->id])
            ->assertFormSet(['assigned_user_id' => $lauro->id])
            ->assertFormFieldExists('assigned_user_id', fn ($field): bool => str_contains($field->getOptions()[$lauro->id], 'Lauro'))
            ->fillForm(['title' => 'SC-001 — Revisada'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($lauro->id, $task->fresh()->assigned_user_id);
        Livewire::test(ViewTask::class, ['record' => $task->id])->assertSee('Lauro');
        ProjectMembership::query()->where('user_id', $lauro->id)->update(['status' => 'revoked']);
        Livewire::test(EditTask::class, ['record' => $task->id])->assertFormSet(['assigned_user_id' => $lauro->id])
            ->assertFormFieldExists('assigned_user_id', fn ($field): bool => str_contains($field->getOptions()[$lauro->id], 'não elegível'))
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($lauro->id, $task->fresh()->assigned_user_id);
    }

    #[DataProvider('editors')]
    public function test_stale_checklist_and_stale_form_cannot_overwrite_description(string $model, string $create, string $edit, string $view): void
    {
        [, $project] = $this->fixtures();
        $record = $this->record($model, $project);
        $revision = hash('sha256', $record->description);
        $first = Livewire::test($view, ['record' => $record->id]);
        $second = Livewire::test($view, ['record' => $record->id]);
        $form = Livewire::test($edit, ['record' => $record->id]);
        $first->call('toggleChecklist', 1, $revision, true);
        $second->call('toggleChecklist', 2, $revision, false)->assertNotified('A descrição foi alterada. Confira a versão atual e tente novamente.');
        $this->assertSame("- [x] Primeiro\n- [x] Segundo", $record->fresh()->description);
        $form->fillForm(['description' => 'Antiga'])->call('save')->assertHasFormErrors(['description']);
        $this->assertSame("- [x] Primeiro\n- [x] Segundo", $record->fresh()->description);
    }

    #[DataProvider('editors')]
    public function test_read_only_users_cannot_toggle_or_save_and_developers_cannot_access(string $model, string $create, string $edit, string $view): void
    {
        [, $project] = $this->fixtures();
        $record = $this->record($model, $project);
        $page = Livewire::test($view, ['record' => $record->id]);
        $form = Livewire::test($edit, ['record' => $record->id])->fillForm(['description' => 'Negado']);
        // Leave view permission intact to test a forged mutation by a read-only user.
        Gate::before(fn ($user, string $ability) => $ability === 'update' ? false : null);
        Livewire::test($view, ['record' => $record->id])->assertDontSee('wire:change', false)->assertSee('disabled=""', false);
        $page->call('toggleChecklist', 1, hash('sha256', $record->description), true)->assertForbidden();
        $form->call('save')->assertForbidden();
        $this->assertSame("- [ ] Primeiro\n- [x] Segundo", $record->fresh()->description);
        $this->actingAs($this->user('developer'), 'web');
        $base = $model === Task::class ? '/admin/tasks' : '/admin/problems';
        foreach ([$base, $base.'/create', $base.'/'.$record->id, $base.'/'.$record->id.'/edit'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    #[DataProvider('editors')]
    public function test_archived_project_blocks_checklist_after_page_was_opened(string $model, string $create, string $edit, string $view): void
    {
        [, $project] = $this->fixtures();
        $record = $this->record($model, $project);
        $page = Livewire::test($view, ['record' => $record->id]);
        $project->update(['status' => 'archived', 'is_archived' => true, 'archived_at' => now()]);
        $page->call('toggleChecklist', 1, hash('sha256', $record->description), true)->assertForbidden();
        $this->assertSame("- [ ] Primeiro\n- [x] Segundo", $record->fresh()->description);
    }

    #[DataProvider('editors')]
    public function test_other_form_fields_are_also_protected_against_lost_updates(string $model, string $create, string $edit, string $view): void
    {
        [, $project] = $this->fixtures();
        $record = $this->record($model, $project);
        $first = Livewire::test($edit, ['record' => $record->id]);
        $second = Livewire::test($edit, ['record' => $record->id]);
        $fields = ['title' => 'Primeira edição'];
        if ($model === Task::class) {
            $fields += ['start_date' => '2026-10-23', 'due_date' => '2026-10-30'];
        }
        $first->fillForm($fields)->call('save')->assertHasNoFormErrors();
        $second->fillForm(['title' => 'Edição desatualizada'])->call('save')->assertHasFormErrors(['title']);
        $this->assertSame('Primeira edição', $record->fresh()->title);
        // Saving twice from the same updated form remains possible.
        $first->fillForm(['title' => 'Segunda edição'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Segunda edição', $record->fresh()->title);
    }

    public function test_mysql_row_locks_exclude_a_competing_writer(): void
    {
        [, $project] = $this->fixtures();
        $record = $this->record(Task::class, $project);
        Task::query()->lockForUpdate()->findOrFail($record->id);
        config(['database.connections.editor_competing' => config('database.connections.mysql')]);
        $competing = DB::connection('editor_competing');
        $competing->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $competing->beginTransaction();
        try {
            $competing->table('tasks')->where('id', $record->id)->lockForUpdate()->first();
            $this->fail('A competing connection bypassed the row lock.');
        } catch (QueryException $exception) {
            $this->assertSame(1205, (int) $exception->errorInfo[1]);
        } finally {
            $competing->rollBack();
        }
    }

    public function test_renderer_rejects_xss_and_preserves_only_trusted_checkbox_events(): void
    {
        $markdown = "# Seguro\n\n<script>alert(1)</script>\n\n<svg onload=alert(1)></svg>\n\n<div wire:click=evil x-init=evil>HTML</div>\n\n[link](javascript:alert(1))\n\n![imagem](data:text/html,evil)\n\n- [ ] Real\n\n```html\n<script>example</script>\n- [ ] Exemplo\n```";
        foreach ([false, true] as $editable) {
            $html = app(TaskMarkdown::class)->render($markdown, $editable);
            foreach (['<script', '<svg', 'onload=', 'wire:click', 'x-init', 'javascript:', 'src="data:'] as $bad) {
                $this->assertStringNotContainsString($bad, $html);
            }
            $this->assertSame($editable ? 1 : 0, substr_count($html, 'wire:change='));
            $this->assertSame(1, substr_count($html, 'type="checkbox"'));
        }
    }

    private function fixtures(): array
    {
        $admin = $this->user('admin');
        $project = Project::query()->create(['name' => 'Projeto isolado', 'slug' => 'editor-'.bin2hex(random_bytes(6)), 'status' => 'active', 'owner_user_id' => $admin->id]);
        $this->actingAs($admin, 'web');

        return [$admin, $project];
    }

    private function user(string $role, string $name = 'Teste'): User
    {
        $role = Role::query()->firstOrCreate(['slug' => $role], ['name' => ucfirst($role)]);

        return User::query()->create(['role_id' => $role->id, 'name' => $name, 'email' => bin2hex(random_bytes(8)).'@example.test', 'password_hash' => 'test-only-password', 'status' => 'active']);
    }

    private function record(string $model, Project $project): Task|Problem
    {
        $record = new $model(['project_id' => $project->id, 'title' => 'Registro isolado', 'description' => "- [ ] Primeiro\n- [x] Segundo"]);
        $record->reporter_user_id = auth()->id();
        $record->saveOrFail();

        return $record;
    }
}
