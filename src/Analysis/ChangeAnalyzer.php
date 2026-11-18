<?php

namespace AmrAchraf\WtfHappened\Analysis;

class ChangeAnalyzer
{
    public function analyze(array $evidence): array
    {
        $files = $authors = $categories = $directories = $reviews = $subjects = [];
        foreach (array_reverse($evidence['commits']) as $commit) {
            if ($commit['author'] !== '') {
                $authors[$commit['author']] = true;
            }
            $subjects[] = $commit['subject'];
            foreach ($commit['changes'] as $change) {
                $path = $change['path'];
                if ($change['old_path'] !== null) {
                    unset($files[$change['old_path']]);
                }
                $files[$path] = ['path' => $path, 'old_path' => $change['old_path'] ?? ($files[$path]['old_path'] ?? null), 'status' => $change['status']];
            }
        }
        ksort($files);
        foreach ($files as $path => $file) {
            $category = $this->category($path);
            $categories[$category] = ($categories[$category] ?? 0) + 1;
            $directory = str_contains($path, '/') ? explode('/', $path)[0].'/' : './';
            $directories[$directory] = ($directories[$directory] ?? 0) + 1;
            foreach (array_filter([$path, $file['old_path']]) as $candidate) {
                if ($this->reviewReason($candidate) !== null) {
                    $reviews[$path] = ['path' => $path, 'reason' => $this->reviewReason($candidate)];
                }
            }
        }
        arsort($directories);
        ksort($categories);

        return [
            'statistics' => ['commits' => count($evidence['commits']), 'files' => count($files), 'contributors' => count($authors)],
            'changes' => ['files' => array_values($files), 'categories' => $categories, 'directories' => $directories, 'subjects' => array_slice(array_values(array_unique(array_reverse($subjects))), 0, 10)],
            'review_items' => array_values($reviews),
        ];
    }

    public function category(string $path): string
    {
        foreach ([
            'app/Http/Controllers/' => 'Controllers', 'app/Http/Middleware/' => 'Middleware',
            'app/Models/' => 'Models', 'app/Policies/' => 'Policies', 'app/Jobs/' => 'Jobs',
            'app/Services/' => 'Services', 'routes/' => 'Routes', 'database/migrations/' => 'Migrations',
            'database/seeders/' => 'Seeders', 'config/' => 'Configuration', 'tests/' => 'Tests',
        ] as $prefix => $category) {
            if (str_starts_with($path, $prefix)) {
                return $category;
            }
        }

        return 'Other';
    }

    public function reviewReason(string $path): ?string
    {
        $category = $this->category($path);
        if (in_array($category, ['Middleware', 'Policies', 'Routes', 'Migrations', 'Configuration'], true)) {
            return $category.' changed; worth reviewing.';
        }
        if (preg_match('~(?:^|/)(?:Auth(?:entication|orization)?|Login|Payment|Webhook|Checkout|Billing|Stripe|Paypal)[^/]*\.php$~i', $path)) {
            return 'Authentication or payment-related filename changed; worth reviewing.';
        }

        return null;
    }
}
