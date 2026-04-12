<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'native:mobile:serve', description: 'Start the NativePHP mobile development server')]
class MobileServeCommand extends Command
{
    protected $signature = 'native:mobile:serve
        {platform? : The target platform (ios, android)}';

    protected $description = 'Start the mobile development server for local testing';

    public function handle(): int
    {
        $platform = $this->argument('platform') ?? $this->choice(
            'Which platform?',
            ['ios', 'android'],
            'ios'
        );

        $this->info("Starting mobile dev server for {$platform}...");
        $this->info('The application will be available via the platform simulator.');

        $this->call('serve', [
            '--host' => '0.0.0.0',
            '--port' => 8100,
        ]);

        return self::SUCCESS;
    }
}
