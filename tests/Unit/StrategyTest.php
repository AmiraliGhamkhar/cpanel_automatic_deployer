<?php
namespace Tests\Unit;
use Tests\TestCase;
use App\Services\Deployment\StrategyRegistry;
use App\Services\Deployment\Strategies\{
    ConfigurableStrategy,
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
        // Avoid mass-assignment schema inspection: strategy selection needs no database.
        $p = new Project();
        $p->settings = ["migrations" => false];
        $this->assertNotContains("migrate", $r->for("laravel")->steps($p));
        $p->settings = ["migrations" => true];
        $this->assertContains("migrate", $r->for("laravel")->steps($p));
    }

    public function test_templates_declare_capabilities_and_verification(): void
    {
        $r = new StrategyRegistry();
        $laravel = $r->for("laravel");
        $this->assertContains("composer", $laravel->requirements());
        $this->assertSame(["artisan"], $laravel->verificationCandidates());
        $this->assertSame("passenger", $laravel->restartStrategy());

        // Node builds are opt-in and never assumed to exist on the host.
        $node = $r->for("node");
        $project = new Project();
        $project->settings = ["build" => false];
        $this->assertArrayNotHasKey("Build Node assets", $node->steps($project));
        $project->settings = ["build" => true];
        $this->assertArrayHasKey("Build Node assets", $node->steps($project));
    }

    public function test_unknown_project_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StrategyRegistry())->for("ruby");
    }

    public function test_python_uses_pyproject_when_requirements_are_absent(): void
    {
        $steps = (new StrategyRegistry())
            ->for("python")
            ->steps(new Project(), false);
        $this->assertContains("pyproject", $steps);
        $this->assertNotContains("python", array_values($steps));
    }
}
