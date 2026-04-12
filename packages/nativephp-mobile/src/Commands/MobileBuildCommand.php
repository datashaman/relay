<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'native:mobile:build', description: 'Build the NativePHP mobile application')]
class MobileBuildCommand extends Command
{
    protected $signature = 'native:mobile:build
        {platform? : The target platform (ios, android, all)}
        {--release : Build a release package}';

    protected $description = 'Build the NativePHP mobile application for iOS and/or Android';

    public function handle(): int
    {
        $platform = $this->argument('platform') ?? $this->choice(
            'Which platform would you like to build for?',
            ['ios', 'android', 'all'],
            'all'
        );

        $platforms = $platform === 'all' ? ['ios', 'android'] : [$platform];

        foreach ($platforms as $target) {
            $this->buildPlatform($target);
        }

        return self::SUCCESS;
    }

    protected function buildPlatform(string $platform): void
    {
        $this->info("Building for {$platform}...");

        $this->runPreBuildHooks();

        $outputPath = config('nativephp-mobile.build.output_path', 'dist/mobile');
        $platformPath = str_starts_with($outputPath, '/') ? "{$outputPath}/{$platform}" : base_path("{$outputPath}/{$platform}");

        if (! is_dir($platformPath)) {
            mkdir($platformPath, 0755, true);
        }

        match ($platform) {
            'ios' => $this->buildIos($platformPath),
            'android' => $this->buildAndroid($platformPath),
        };

        $this->runPostBuildHooks();

        $this->info("Build complete for {$platform}: {$platformPath}");
    }

    protected function buildIos(string $outputPath): void
    {
        $config = config('nativephp-mobile.ios');

        $this->info("Bundle ID: {$config['bundle_id']}");
        $this->info("Min iOS: {$config['min_ios_version']}");

        $manifestPath = "{$outputPath}/build-manifest.json";
        file_put_contents($manifestPath, json_encode([
            'platform' => 'ios',
            'bundle_id' => $config['bundle_id'],
            'team_id' => $config['team_id'],
            'min_version' => $config['min_ios_version'],
            'app_name' => config('app.name'),
            'app_version' => config('nativephp.version', '1.0.0'),
            'release' => $this->option('release'),
            'built_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("iOS build manifest written to {$manifestPath}");
    }

    protected function buildAndroid(string $outputPath): void
    {
        $config = config('nativephp-mobile.android');

        $this->info("Package: {$config['package_name']}");
        $this->info("Min SDK: {$config['min_sdk']}, Target SDK: {$config['target_sdk']}");

        $manifestPath = "{$outputPath}/build-manifest.json";
        file_put_contents($manifestPath, json_encode([
            'platform' => 'android',
            'package_name' => $config['package_name'],
            'min_sdk' => $config['min_sdk'],
            'target_sdk' => $config['target_sdk'],
            'app_name' => config('app.name'),
            'app_version' => config('nativephp.version', '1.0.0'),
            'release' => $this->option('release'),
            'built_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("Android build manifest written to {$manifestPath}");
    }

    protected function runPreBuildHooks(): void
    {
        foreach (config('nativephp.prebuild', []) as $command) {
            $this->info("Running pre-build: {$command}");
            exec($command);
        }
    }

    protected function runPostBuildHooks(): void
    {
        foreach (config('nativephp.postbuild', []) as $command) {
            $this->info("Running post-build: {$command}");
            exec($command);
        }
    }
}
