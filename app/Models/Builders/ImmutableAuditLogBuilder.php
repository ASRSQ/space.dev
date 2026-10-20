<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

class ImmutableAuditLogBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException('Audit log records cannot be updated.');
    }

    public function delete()
    {
        throw new LogicException('Audit log records cannot be deleted.');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('Audit log records cannot be updated.');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('Audit log records cannot be updated.');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException('Audit log records cannot be updated or replaced.');
    }

    public function truncate()
    {
        throw new LogicException('Audit log records cannot be deleted.');
    }
}
