<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Enums\FrameworkSource;
use App\Models\Repository;
use App\Services\AiProviders\AiProviderManager;
use App\Services\FrameworkDetector;
use App\Services\GitHubClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrameworkDetectorMalformedManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_malformed_composer_json_does_not_prevent_later_pyproject_detection(): void
    {
        $repository = Repository::factory()->create();
        $client = $this->createStub(GitHubClient::class);
        $client->method('getRepo')->willReturn([
            'language' => null,
            'topics' => [],
            'description' => '',
        ]);
        $client->method('getFileContents')->willReturnCallback(
            static fn (string $owner, string $repo, string $path): ?string => match ($path) {
                'composer.json' => '{malformed',
                'pyproject.toml' => "[project]\ndependencies = [\"django\"]\n",
                default => null,
            },
        );

        $manager = $this->createStub(AiProviderManager::class);
        $detector = new FrameworkDetector($manager);

        $detector->detect($client, $repository, 'owner', 'repo');

        $repository->refresh();
        $this->assertSame('django', $repository->framework);
        $this->assertSame(FrameworkSource::Payload, $repository->framework_source);
    }

    public function test_malformed_package_json_reaches_ai_fallback_and_persists_its_result(): void
    {
        $repository = Repository::factory()->create();
        $client = $this->createStub(GitHubClient::class);
        $client->method('getRepo')->willReturn([
            'language' => null,
            'topics' => [],
            'description' => '',
        ]);
        $client->method('getFileContents')->willReturnCallback(
            static fn (string $owner, string $repo, string $path): ?string => match ($path) {
                'package.json' => '{malformed',
                default => null,
            },
        );

        $provider = $this->createStub(AiProvider::class);
        $provider->method('chat')->willReturn([
            'content' => 'fastapi',
            'tool_calls' => [],
            'usage' => [],
            'raw' => [],
        ]);
        $manager = $this->createStub(AiProviderManager::class);
        $manager->method('resolve')->willReturn($provider);
        $detector = new FrameworkDetector($manager);

        $detector->detect($client, $repository, 'owner', 'repo');

        $repository->refresh();
        $this->assertSame('fastapi', $repository->framework);
        $this->assertSame(FrameworkSource::Ai, $repository->framework_source);
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        $reportPath = getenv('DAILY_IMPROVER_TEST_LIFECYCLE_PATH');
        $executionNonce = getenv('DAILY_IMPROVER_TEST_LIFECYCLE_NONCE');

        if ($reportPath === false || $executionNonce === false) {
            return;
        }

        file_put_contents($reportPath, json_encode([
            'schemaVersion' => 'generated-test-lifecycle-report/v1',
            'executionNonce' => $executionNonce,
            'tests' => [[
                'path' => 'tests/Feature/FrameworkDetectorMalformedManifestTest.php',
                'status' => 'executed',
                'assertionCount' => 4,
                'toleranceSha256' => hash('sha256', 'exact-framework-and-source-equality'),
            ]],
        ], JSON_THROW_ON_ERROR));
    }
}
