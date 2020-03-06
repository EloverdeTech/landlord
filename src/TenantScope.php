<?php

namespace Eloverde\Landlord;

class TenantScope
{

    private \Closure $queryScope;
    private \Closure $createScope;

    public function onQuery(\Closure $closure): TenantScope
    {
        $this->queryScope = $closure;
        return $this;
    }

    public function onSave(\Closure $closure): TenantScope
    {
        $this->createScope = $closure;
        return $this;
    }

    public function getOnQuery(): \Closure
    {
        return $this->queryScope;
    }

    public function getOnCreate(): \Closure
    {
        return $this->createScope;
    }
}