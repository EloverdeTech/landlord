<?php


use Eloverde\Landlord\BelongsToTenants;
use Eloverde\Landlord\Facades\Landlord;
use Eloverde\Landlord\LandlordServiceProvider;
use Eloverde\Landlord\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase;

class LandlordTest extends TestCase
{
    protected function setUp()
    {
        parent::setUp();

        $schemaBuilder = $this->app['db']->connection()->getSchemaBuilder();

        $schemaBuilder->create('model_stubs', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();
            $table->string('name');
            $table->integer('tenant_a_id');
            $table->integer('tenant_b_id');
        });
    }

    protected function getPackageProviders($app)
    {
        return [
            LandlordServiceProvider::class
        ];
    }

    public function testTenantsWithStrings()
    {
        Landlord::addTenant('tenant_a_id', 1);
        Landlord::addTenant('tenant_b_id', 2);

        $model = new ModelStub();
        $sql = $this->getSqlFromBuilder($model->query());

        $this->assertContains("\"model_stubs\".\"tenant_a_id\" = '1'", $sql);
        $this->assertContains("\"model_stubs\".\"tenant_b_id\" = '2'", $sql);
    }

    public function testTenantsWithModels()
    {
        $tenantA = new TenantA();
        $tenantB = new TenantB();

        $tenantA->id = 1;
        $tenantB->id = 2;

        Landlord::addTenant($tenantA);
        Landlord::addTenant($tenantB);

        $model = new ModelStub();
        $sql = $this->getSqlFromBuilder($model->query());

        $this->assertContains("\"model_stubs\".\"tenant_a_id\" = '1'", $sql);
        $this->assertContains("\"model_stubs\".\"tenant_b_id\" = '2'", $sql);
    }

    public function testTenantsWithTenantScope()
    {
        $tenantA = (new TenantScope())
            ->onQuery(function (Builder $builder, Model $model) {
                $builder->where('tenant_a_id', 1);
            });

        $tenantB = (new TenantScope())
            ->onQuery(function (Builder $builder, Model $model) {
                $builder->where('tenant_b_id', 2);
            });

        Landlord::addTenant('tenant_a_id', $tenantA);
        Landlord::addTenant('tenant_b_id', $tenantB);

        $model = new ModelStub();
        $sql = $this->getSqlFromBuilder($model->query());

        $this->assertContains("\"tenant_a_id\" = '1'", $sql);
        $this->assertContains("\"tenant_b_id\" = '2'", $sql);
    }

    public function testApplyTenantScopes()
    {
        Landlord::addTenant('tenant_a_id', 1);
        Landlord::addTenant('tenant_c_id', 1);

        $model = new ModelStub();

        Landlord::applyTenantScopes($model);

        $this->assertArrayHasKey('tenant_a_id', $model->getGlobalScopes());
        $this->assertArrayNotHasKey('tenant_b_id', $model->getGlobalScopes());
        $this->assertArrayNotHasKey('tenant_c_id', $model->getGlobalScopes());
    }

    public function testApplyTenantScopesToDeferredModels()
    {
        $model = new ModelStub();

        Landlord::newModel($model);
        Landlord::addTenant('tenant_a_id', 1);

        $sql = $this->getSqlFromBuilder($model->query());
        $this->assertNotContains("\"model_stubs\".\"tenant_a_id\" = '1'", $sql);

        Landlord::applyTenantScopesToDeferredModels();

        $sql = $this->getSqlFromBuilder($model->query());
        //$this->assertContains("\"model_stubs\".\"tenant_a_id\" = '1'", $sql);
    }

    public function testNewModel()
    {
        Landlord::addTenant('tenant_a_id', 1);
        Landlord::addTenant('tenant_b_id', 2);
        Landlord::addTenant('tenant_c_id', 3);

        $model = ModelStub::create([
            'name' => 'foo'
        ]);

        $this->assertEquals(1, $model->tenant_a_id);
        $this->assertEquals(2, $model->tenant_b_id);
        $this->assertNull($model->tenant_c_id);
    }

    protected function getSqlFromBuilder(\Illuminate\Database\Eloquent\Builder $builder)
    {
        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        $wrappedSql = str_replace('?', "'?'", $sql);
        return str_replace_array('?', $bindings, $wrappedSql);
    }
}

class ModelStub extends Model
{
    use BelongsToTenants;

    public $fillable = ['name'];
    public $tenantColumns = ['tenant_a_id', 'tenant_b_id'];
}

class TenantA extends Model
{
    //
}

class TenantB extends Model
{
    //
}
