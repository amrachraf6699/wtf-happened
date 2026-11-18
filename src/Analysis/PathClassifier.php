<?php

namespace AmrAchraf\WtfHappened\Analysis;

use InvalidArgumentException;

/** Committed-path heuristics: no application code execution or working-tree scan. */
class PathClassifier
{
    public const ROOTS = ['Modules/*', 'app/Modules/*', 'src/Modules/*', 'packages/*/*', 'packages/*', 'app/Containers/*/*', 'app/Domain/*', 'app/Domains/*', 'src/Domain/*', 'src/Domains/*', 'domain/*', 'domains/*'];

    public function __construct(private array $roots = self::ROOTS, private array $rules = [])
    {
        foreach ($roots as $root) {
            if (! is_string($root) || $root === '' || str_contains($root, '..') || str_contains($root, '\\') || str_starts_with($root, '/') || preg_match('/^[a-z]:|[\x00-\x1f]/i', $root)) {
                throw new InvalidArgumentException('analysis.module_roots must contain relative path patterns.');
            }
        }
        foreach ($rules as $rule) {
            if (! is_array($rule) || ! is_string($rule['pattern'] ?? null) || $rule['pattern'] === '' || ! is_string($rule['category'] ?? null) || trim($rule['category']) === '' || (isset($rule['review']) && ! is_bool($rule['review']))) {
                throw new InvalidArgumentException('Each analysis.category_rules entry needs a pattern, category and optional boolean review.');
            }
        }
    }

    /** @return array{category:string,module:?string,review:bool,source:bool} */
    public function classify(string $path): array
    {
        // Git paths always use /; a literal backslash may be part of a Unix filename.
        $relative = $path;
        $module = null;
        foreach ($this->roots as $root) {
            if (preg_match('~^('.$this->glob(trim($root, '/')).')/(.+)$~iD', $path, $matches)) {
                // packages/Blog/src is a single package, not a vendor/package pair.
                if (strtolower($root) === 'packages/*/*' && preg_match('~/(?:app|src|routes|database|tests|resources|config|lang)$~i', $matches[1])) {
                    continue;
                }
                $module = $matches[1];
                $relative = $matches[2];
                break;
            }
        }
        $source = $module !== null || preg_match('~^(app|src|routes|database|tests)/~i', $path) === 1;
        $relative = preg_replace('~^(?:app/|src/)+~i', '', $relative);
        $category = 'Other';
        foreach ($this->rules as $rule) {
            if (preg_match('~^'.$this->glob($rule['pattern']).'$~iD', $path)) {
                return ['category' => $rule['category'], 'module' => $module, 'review' => $rule['review'] ?? false, 'source' => $source];
            }
        }
        if ($source || preg_match('~^(config|resources|lang)/~i', $path)) {
            foreach ([
                'Tests?' => 'Tests', 'Database/Migrations|Migrations' => 'Migrations',
                'Database/Seeders|Database/Seeds|Seeders' => 'Seeders', 'Database/Factories|Factories' => 'Factories',
                'Routes' => 'Routes', 'Config' => 'Configuration', 'Resources/Views|Views' => 'Views',
                'Resources/Lang|Lang|Translations' => 'Translations',
                'Http/Controllers|Controllers' => 'Controllers', 'Http/Middleware|Middleware' => 'Middleware',
                'Http/Requests|Requests' => 'Requests', 'Http/Resources' => 'API Resources',
                'Models|Entities' => 'Models', 'Policies' => 'Policies', 'Jobs' => 'Jobs',
                'Services' => 'Services', 'Actions|Tasks' => 'Actions', 'Events' => 'Events',
                'Listeners' => 'Listeners', 'Providers' => 'Service Providers',
                'Console/Commands|Commands' => 'Console Commands', 'Notifications' => 'Notifications',
                'Mail|Mailables' => 'Mail', 'Exceptions' => 'Exceptions', 'Contracts|Interfaces' => 'Contracts',
                'Repositories' => 'Repositories', 'DTOs|DataTransferObjects|Data' => 'Data Objects',
                'Enums' => 'Enums', 'Rules' => 'Validation Rules', 'Livewire' => 'Livewire',
                'Filament' => 'Filament', 'Resources/Js|Resources/Css|Resources/Sass|Resources/Assets' => 'Frontend',
            ] as $directory => $label) {
                if (preg_match('~(?:^|/)(?:'.$directory.')/~i', $relative)) {
                    $category = $label;
                    break;
                }
            }
            if ($category === 'Other' && str_ends_with(strtolower($path), '.php')) {
                foreach (['Controller' => 'Controllers', 'Middleware' => 'Middleware', 'Policy' => 'Policies', 'Request' => 'Requests', 'Job' => 'Jobs', 'ServiceProvider' => 'Service Providers', 'Notification' => 'Notifications', 'Test' => 'Tests', 'Action' => 'Actions'] as $suffix => $label) {
                    if (preg_match('~'.$suffix.'\.php$~i', $relative)) {
                        $category = $label;
                        break;
                    }
                }
            }
        }
        $basename = strtolower(basename($path));
        if (in_array($basename, ['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'], true)) {
            $category = 'Dependencies';
        } elseif ($basename === 'module.json') {
            $category = 'Module Definition';
        } elseif (preg_match('~^\.github/workflows/|(?:^|/)(?:Dockerfile|docker-compose[^/]*\.ya?ml)$~i', $path)) {
            $category = 'Infrastructure';
        } elseif ($category === 'Other' && preg_match('~(?:^docs/|\.(?:md|rst)$)~i', $path)) {
            $category = 'Documentation';
        }
        $review = in_array($category, ['Middleware', 'Policies', 'Routes', 'Migrations', 'Configuration', 'Dependencies', 'Module Definition', 'Service Providers', 'Infrastructure'], true);

        return compact('category', 'module', 'review', 'source');
    }

    private function glob(string $pattern): string
    {
        return str_replace(['\\*\\*', '\\*', '\\?'], ['.*', '[^/]+', '[^/]'], preg_quote($pattern, '~'));
    }
}
