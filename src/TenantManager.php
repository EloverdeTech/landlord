<?php

namespace Eloverde\Landlord;

use Eloverde\Landlord\Exceptions\TenantColumnUnknownException;
use Eloverde\Landlord\Exceptions\TenantNullIdException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Macroable;

class TenantManager
{
    use Macroable;

    /**
     * @var bool
     */
    protected $enabled = true;

    /**
     * @var Collection
     */
    protected $tenants;

    /**
     * @var Collection
     */
    protected $deferredModels;

    /**
     * Landlord constructor.
     */
    public function __construct()
    {
        $this->tenants = collect();
        $this->deferredModels = collect();
    }

    /**
     * Enable scoping by tenantColumns.
     *
     * @return void
     */
    public function enable()
    {
        $this->enabled = true;
    }

    /**
     * Disable scoping by tenantColumns.
     *
     * @return void
     */
    public function disable()
    {
        $this->enabled = false;
    }

    /**
     * Add a tenant to scope by.
     *
     * @param $tenantKey
     * @param string|Model $tenant
     * @throws TenantColumnUnknownException
     * @throws TenantNullIdException
     */
    public function addTenant($tenant, $tenantValue = null)
    {
        if (func_num_args() == 1 && $tenant instanceof Model) {
            $tenantValue = $tenant->getKey();
        }

        if (is_null($tenantValue)) {
            throw new TenantNullIdException('$tenantValue must not be null');
        }

        $tenantKey = $this->getTenantKey($tenant);

        if ($tenantValue instanceof TenantScope) {
            $tenantScope = $tenantValue;
        } else {
            $tenantScope = (new TenantScope())
                ->onQuery(function (Builder $builder, Model $model) use ($tenantKey, $tenantValue) {
                    $builder->where($model->getQualifiedTenant($tenantKey), '=', $tenantValue);
                })
                ->onSave(function (Model $model) use ($tenantKey, $tenantValue) {
                    if (!isset($model->{$tenantKey})) {
                        $model->setAttribute($tenantKey, $tenantValue);
                    }
                });
        }

        $this->tenants->put($tenantKey, $tenantScope);
    }

    /**
     * Remove a tenant so that queries are no longer scoped by it.
     *
     * @param string|Model $tenant
     * @throws TenantColumnUnknownException
     */
    public function removeTenant($tenant)
    {
        $this->tenants->pull($this->getTenantKey($tenant));
    }

    /**
     * Whether a tenant is currently being scoped.
     *
     * @param string|Model $tenant
     *
     * @return bool
     */
    public function hasTenant($tenant)
    {
        return $this->tenants->has($this->getTenantKey($tenant));
    }

    /**
     * @return Collection
     */
    public function getTenants()
    {
        return $this->tenants;
    }

    /**
     * @param $tenant
     *
     * @return mixed
     * @throws TenantColumnUnknownException
     *
     */
    public function getTenantId($tenant)
    {
        if (!$this->hasTenant($tenant)) {
            throw new TenantColumnUnknownException(
                '$tenant must be a string key or an instance of \Illuminate\Database\Eloquent\Model'
            );
        }

        return $this->tenants->get($this->getTenantKey($tenant));
    }

    /**
     * Applies applicable tenant scopes to a model.
     *
     * @param Model|BelongsToTenants $model
     */
    public function applyTenantScopes(Model $model)
    {
        if (!$this->enabled) {
            return;
        }

        if ($this->tenants->isEmpty()) {
            // No tenants yet, defer scoping to a later stage
            $this->deferredModels->push($model);

            return;
        }

        $this->modelTenants($model)->each(function ($id, $tenant) use ($model) {
            $this->addScopeToQuery($model, $tenant, $id);
        });
    }

    /**
     * Applies applicable tenant scopes to deferred model booted before tenants setup.
     */
    public function applyTenantScopesToDeferredModels()
    {
        $this->deferredModels->each(function ($model) {
            /* @var Model|BelongsToTenants $model */
            $this->modelTenants($model)->each(function ($id, $tenant) use ($model) {
//                if (!isset($model->{$tenant})) {
//                    $model->setAttribute($tenant, $id);
//                }

                $this->addScopeToQuery($model, $tenant, $id);
            });
        });

        $this->deferredModels = collect();
    }

    private function addScopeToQuery(Model $model, string $tenantKey, TenantScope $tenantScope)
    {
        $model->addGlobalScope($tenantKey, function (Builder $builder) use ($tenantKey, $tenantScope, $model) {
            $callable = $tenantScope->getOnQuery();
            $callable($builder, $model);
        });
    }

    /**
     * Add tenant columns as needed to a new model instance before it is created.
     *
     * @param Model $model
     */
    public function newModel(Model $model)
    {
        if (!$this->enabled) {
            return;
        }

        if ($this->tenants->isEmpty()) {
            // No tenants yet, defer scoping to a later stage
            $this->deferredModels->push($model);

            return;
        }

        $this->modelTenants($model)->each(function (TenantScope $tenantScope, string $tenantColumn) use ($model) {
            $callable = $tenantScope->getOnCreate();
            $callable($model);
        });
    }

    /**
     * Get a new Eloquent Builder instance without any of the tenant scopes applied.
     *
     * @param Model $model
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newQueryWithoutTenants(Model $model)
    {
        return $model->newQuery()->withoutGlobalScopes($this->tenants->keys()->toArray());
    }

    /**
     * Get the key for a tenant, either from a Model instance or a string.
     *
     * @param string|Model $tenantKey
     *
     * @return string
     * @throws TenantColumnUnknownException
     *
     */
    protected function getTenantKey($tenantKey)
    {
        if ($tenantKey instanceof Model) {
            $tenantKey = $tenantKey->getForeignKey();
        }

        if (!is_string($tenantKey)) {
            throw new TenantColumnUnknownException(
                '$tenantKey must be a string key or an instance of \Illuminate\Database\Eloquent\Model'
            );
        }

        return $tenantKey;
    }

    /**
     * Get the tenantColumns that are actually applicable to the given
     * model, in case they've been manually specified.
     *
     * @param Model|BelongsToTenants $model
     *
     * @return Collection
     */
    protected function modelTenants(Model $model)
    {
        return $this->tenants->only($model->getTenantColumns());
    }
}
