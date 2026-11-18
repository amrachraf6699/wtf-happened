<?php

namespace AmrAchraf\WtfHappened\Tests\Unit;

use AmrAchraf\WtfHappened\Analysis\ChangeAnalyzer;
use AmrAchraf\WtfHappened\Analysis\PathClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PathClassifierTest extends TestCase
{
    public static function layouts(): array
    {
        return [
            ['app/Http/Controllers/HomeController.php', 'Controllers', null],
            ['Modules/Blog/Http/Controllers/PostController.php', 'Controllers', 'Modules/Blog'],
            ['Modules/Blog/app/Http/Middleware/Check.php', 'Middleware', 'Modules/Blog'],
            ['modules/Blog/Entities/Post.php', 'Models', 'modules/Blog'],
            ['Modules/Billing/Database/Migrations/create.php', 'Migrations', 'Modules/Billing'],
            ['Modules/Blog/Tests/Feature/ControllerTest.php', 'Tests', 'Modules/Blog'],
            ['Modules/Blog/Resources/views/index.blade.php', 'Views', 'Modules/Blog'],
            ['Modules/Blog/module.json', 'Module Definition', 'Modules/Blog'],
            ['app/Modules/Shop/Routes/api.php', 'Routes', 'app/Modules/Shop'],
            ['packages/acme/shop/src/Policies/OrderPolicy.php', 'Policies', 'packages/acme/shop'],
            ['packages/Shop/src/Models/Order.php', 'Models', 'packages/Shop'],
            ['app/Domain/Orders/Actions/PlaceOrder.php', 'Actions', 'app/Domain/Orders'],
            ['src/Domains/Orders/Repositories/OrderRepository.php', 'Repositories', 'src/Domains/Orders'],
            ['app/Containers/Shop/Order/Tasks/PlaceOrderTask.php', 'Actions', 'app/Containers/Shop/Order'],
            ['src/Features/PublishController.php', 'Controllers', null],
            ['app/Enums/State.php', 'Enums', null],
            ['app/Livewire/Cart.php', 'Livewire', null],
            ['app/Filament/Resources/OrderResource.php', 'Filament', null],
            ['resources/js/app.js', 'Frontend', null],
            ['config/services.php', 'Configuration', null],
            ['vendor/foo/Models/User.php', 'Other', null],
            ['docs/Controllers/example.md', 'Documentation', null],
            ['Modules\\Blog\\app\\UserController.php', 'Other', null],
        ];
    }

    #[DataProvider('layouts')]
    public function test_classifies_standard_modular_and_domain_layouts(string $path, string $category, ?string $module): void
    {
        $result = (new PathClassifier)->classify($path);
        $this->assertSame($category, $result['category']);
        $this->assertSame($module, $result['module']);
    }

    public function test_custom_roots_and_ordered_full_path_rules(): void
    {
        $classifier = new PathClassifier(['components/*'], [
            ['pattern' => 'components/*/Gateways/**', 'category' => 'Payment Gateways', 'review' => true],
            ['pattern' => '**/*.php', 'category' => 'Custom PHP'],
        ]);
        $this->assertSame(['category' => 'Payment Gateways', 'module' => 'components/Checkout', 'review' => true, 'source' => true], $classifier->classify('components/Checkout/Gateways/Stripe/Charge.php'));
        $this->assertNull($classifier->classify('Modules/Blog/Models/Post.php')['module']);
        $this->expectException(\InvalidArgumentException::class);
        new PathClassifier(['../outside/*']);
    }

    public function test_module_counts_deduplicate_and_sensitive_module_changes_are_reviewed(): void
    {
        $changes = array_map(fn ($path) => ['path' => $path, 'old_path' => null, 'status' => 'M'], ['Modules/Shop/Routes/api.php', 'Modules/Shop/app/Models/Order.php']);
        $commit = ['author' => 'local-only', 'subject' => 'Update shop', 'changes' => $changes];
        $report = (new ChangeAnalyzer)->analyze(['commits' => [$commit, $commit]]);
        $this->assertSame(['Modules/Shop' => 2], $report['changes']['modules']);
        $this->assertSame(['Models' => 1, 'Routes' => 1], $report['changes']['categories']);
        $this->assertCount(1, $report['review_items']);
        $this->assertSame('Routes changed; worth reviewing.', $report['review_items'][0]['reason']);
    }
}
