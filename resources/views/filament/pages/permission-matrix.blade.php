<x-filament-panels::page>
    <p>A matriz consulta as Policies atuais para o usuário e projeto selecionados. Ela não concede permissões. Exclusão física de usuários e registros com histórico permanece bloqueada. Aprovações não são concedidas a líderes de projeto. As Policies de prompts não acrescentam uma interface nem um fluxo de aprovação novo.</p>
    <label>Usuário
        <select wire:model.live="userId" class="fi-input">
            @foreach ($this->users() as $id => $name)<option value="{{ $id }}">{{ $name }} (#{{ $id }})</option>@endforeach
        </select>
    </label>
    <label>Projeto
        <select wire:model.live="projectId" class="fi-input">
            <option value="">Selecione o projeto</option>
            @foreach ($this->projects() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </select>
    </label>
    <div class="fi-prose">
        <table>
            <thead><tr><th>Recurso</th><th>Ler</th><th>Criar</th><th>Editar</th><th>Excluir/arquivar</th><th>Aprovar</th></tr></thead>
            <tbody>
                @foreach ($this->matrix() as $resource => $permissions)
                    <tr><th>{{ $resource }}</th>@foreach ($permissions as $permission)<td>{{ is_bool($permission) ? ($permission ? 'Permitido' : 'Negado') : $permission }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
        <h2>Papéis globais existentes</h2>
        <ul>@foreach ($this->roles() as $role)<li>{{ $role->name }} ({{ $role->slug }})</li>@endforeach</ul>
        <p>Atribua o papel global em Usuários e o papel contextual em Membros dos projetos. Programador e editor podem coexistir por meio do papel contextual combinado. Associações de equipe não substituem o vínculo explícito com o projeto.</p>
    </div>
</x-filament-panels::page>
