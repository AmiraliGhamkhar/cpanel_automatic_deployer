<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Services\Deployment\StrategyRegistry;
use App\Services\Deployment\Strategies\{
    LaravelDeploymentStrategy,
    PythonDeploymentStrategy,
    NodeDeploymentStrategy,
    StaticDeploymentStrategy,
};
use App\Models\Project;
class StrategyTest extends TestCase
{
    public function test_templates_are_separate_and_migrations_are_opt_in(): void
    {
        $r = new StrategyRegistry();
        foreach (
            [
                "laravel" => LaravelDeploymentStrategy::class,
                "python" => PythonDeploymentStrategy::class,
                "node" => NodeDeploymentStrategy::class,
                "static" => StaticDeploymentStrategy::class,
            ]
            as $key => $class
        ) {
            $this->assertInstanceOf($class, $r->for($key));
        }
        $p = new Project(["settings" => ["migrations" => false]]);
        $this->assertNotContains("migrate", $r->for("laravel")->steps($p));
        $p->settings = ["migrations" => true];
        $this->assertContains("migrate", $r->for("laravel")->steps($p));
    }
}
