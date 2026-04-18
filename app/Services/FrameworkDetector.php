<?php

namespace App\Services;

use App\Enums\FrameworkSource;
use App\Models\Repository;
use App\Services\AiProviders\AiProviderManager;
use Illuminate\Support\Facades\Log;
use Throwable;

class FrameworkDetector
{
    /**
     * Allowed framework slugs. AI output is coerced to one of these; unknowns → 'other'.
     *
     * @var list<string>
     */
    public const ALLOWED = [
        'laravel',
        'rails',
        'django',
        'nextjs',
        'nestjs',
        'flask',
        'fastapi',
        'go-echo',
        'go-gin',
        'springboot',
        'other',
    ];

    private const MANIFESTS = [
        'composer.json',
        'package.json',
        'pyproject.toml',
        'Gemfile',
        'go.mod',
        'Cargo.toml',
    ];

    public function __construct(
        private AiProviderManager $providerManager,
    ) {}

    /**
     * Detect and persist the framework for a repository.
     *
     * Detection order (cheapest first):
     *   1. GitHub `language` + topics.
     *   2. Manifest file probe via contents API.
     *   3. AI fallback via the configured provider.
     *
     * Skipped entirely when `framework_source === Manual` — the user's choice is
     * authoritative and must never be clobbered by sync-driven detection.
     */
    public function detect(GitHubClient $client, Repository $repository, string $owner, string $repo): void
    {
        if ($repository->framework_source === FrameworkSource::Manual) {
            return;
        }

        try {
            $repoMeta = $client->getRepo($owner, $repo);
        } catch (Throwable $e) {
            Log::warning('Framework detection failed to fetch repo metadata', [
                'repository_id' => $repository->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $payloadHit = $this->detectFromPayload($repoMeta);

        if ($payloadHit !== null) {
            $this->persist($repository, $payloadHit, FrameworkSource::Payload);

            return;
        }

        $manifestHit = $this->detectFromManifests($client, $owner, $repo);

        if ($manifestHit !== null) {
            $this->persist($repository, $manifestHit, FrameworkSource::Payload);

            return;
        }

        $aiHit = $this->detectWithAi($repoMeta);

        if ($aiHit !== null) {
            $this->persist($repository, $aiHit, FrameworkSource::Ai);
        }
    }

    /**
     * @param  array<string, mixed>  $repoMeta
     */
    private function detectFromPayload(array $repoMeta): ?string
    {
        $language = strtolower((string) ($repoMeta['language'] ?? ''));
        $topics = array_map(
            fn ($t) => strtolower((string) $t),
            is_array($repoMeta['topics'] ?? null) ? $repoMeta['topics'] : [],
        );

        foreach (self::ALLOWED as $slug) {
            if ($slug !== 'other' && in_array($slug, $topics, true)) {
                return $slug;
            }
        }

        $topicMap = [
            'rails' => ['ruby-on-rails'],
            'nextjs' => ['next', 'next-js', 'next.js'],
            'nestjs' => ['nest', 'nest-js', 'nest.js'],
            'springboot' => ['spring-boot', 'spring'],
            'fastapi' => ['fast-api'],
        ];

        foreach ($topicMap as $slug => $aliases) {
            foreach ($aliases as $alias) {
                if (in_array($alias, $topics, true)) {
                    return $slug;
                }
            }
        }

        if ($language === 'php' && in_array('laravel', $topics, true)) {
            return 'laravel';
        }

        if ($language === 'ruby' && in_array('rails', $topics, true)) {
            return 'rails';
        }

        return null;
    }

    private function detectFromManifests(GitHubClient $client, string $owner, string $repo): ?string
    {
        $manifests = [];

        foreach (self::MANIFESTS as $path) {
            try {
                $contents = $client->getFileContents($owner, $repo, $path);
            } catch (Throwable $e) {
                continue;
            }

            if ($contents !== null) {
                $manifests[$path] = $contents;
            }
        }

        if (isset($manifests['composer.json'])) {
            $hit = $this->classifyComposer($manifests['composer.json']);
            if ($hit !== null) {
                return $hit;
            }
        }

        if (isset($manifests['package.json'])) {
            $hit = $this->classifyPackageJson($manifests['package.json']);
            if ($hit !== null) {
                return $hit;
            }
        }

        if (isset($manifests['pyproject.toml'])) {
            $hit = $this->classifyPyproject($manifests['pyproject.toml']);
            if ($hit !== null) {
                return $hit;
            }
        }

        if (isset($manifests['Gemfile'])) {
            $hit = $this->classifyGemfile($manifests['Gemfile']);
            if ($hit !== null) {
                return $hit;
            }
        }

        if (isset($manifests['go.mod'])) {
            $hit = $this->classifyGoMod($manifests['go.mod']);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    private function classifyComposer(string $contents): ?string
    {
        try {
            $json = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($json)) {
            return null;
        }

        $require = array_merge(
            is_array($json['require'] ?? null) ? $json['require'] : [],
            is_array($json['require-dev'] ?? null) ? $json['require-dev'] : [],
        );

        if (isset($require['laravel/framework'])) {
            return 'laravel';
        }

        return null;
    }

    private function classifyPackageJson(string $contents): ?string
    {
        try {
            $json = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($json)) {
            return null;
        }

        $deps = array_merge(
            is_array($json['dependencies'] ?? null) ? $json['dependencies'] : [],
            is_array($json['devDependencies'] ?? null) ? $json['devDependencies'] : [],
        );

        if (isset($deps['next'])) {
            return 'nextjs';
        }

        if (isset($deps['@nestjs/core']) || isset($deps['@nestjs/common'])) {
            return 'nestjs';
        }

        return null;
    }

    private function classifyPyproject(string $contents): ?string
    {
        $lower = strtolower($contents);

        if (str_contains($lower, 'django')) {
            return 'django';
        }

        if (str_contains($lower, 'fastapi')) {
            return 'fastapi';
        }

        if (str_contains($lower, 'flask')) {
            return 'flask';
        }

        return null;
    }

    private function classifyGemfile(string $contents): ?string
    {
        if (preg_match('/^\s*gem\s+["\']rails["\']/mi', $contents) === 1) {
            return 'rails';
        }

        return null;
    }

    private function classifyGoMod(string $contents): ?string
    {
        if (str_contains($contents, 'github.com/labstack/echo')) {
            return 'go-echo';
        }

        if (str_contains($contents, 'github.com/gin-gonic/gin')) {
            return 'go-gin';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $repoMeta
     */
    private function detectWithAi(array $repoMeta): ?string
    {
        try {
            $provider = $this->providerManager->resolve();
        } catch (Throwable $e) {
            Log::warning('Framework detection AI fallback skipped — no provider available', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $allowed = implode(', ', self::ALLOWED);
        $language = (string) ($repoMeta['language'] ?? 'unknown');
        $topics = is_array($repoMeta['topics'] ?? null) ? implode(', ', $repoMeta['topics']) : '';
        $description = (string) ($repoMeta['description'] ?? '');

        $system = "You classify source-code repositories by primary framework. Respond with exactly one slug from this allowlist (no punctuation, no explanation): {$allowed}.";
        $user = "Repository metadata:\nLanguage: {$language}\nTopics: {$topics}\nDescription: {$description}\n\nReturn the single best-matching slug.";

        try {
            $result = $provider->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ], [], ['max_tokens' => 32]);
        } catch (Throwable $e) {
            Log::warning('Framework detection AI call failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $raw = is_string($result['content'] ?? null) ? $result['content'] : '';

        return $this->coerceAllowed($raw);
    }

    private function coerceAllowed(string $raw): string
    {
        $slug = trim(strtolower($raw));
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug) ?? '';

        if ($slug === '') {
            return 'other';
        }

        foreach (self::ALLOWED as $allowed) {
            if ($slug === $allowed) {
                return $allowed;
            }
        }

        return 'other';
    }

    private function persist(Repository $repository, string $framework, FrameworkSource $source): void
    {
        $repository->forceFill([
            'framework' => $framework,
            'framework_source' => $source,
        ])->save();
    }
}
