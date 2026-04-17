<?php

namespace Tests;

use App\Services\WorktreeService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            'localhost:4000/*' => Http::response(['ok' => true], 200),
        ]);

        // Default WorktreeService stub — tests that care about git behavior mock it themselves.
        $this->mock(WorktreeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createWorktree')
                ->byDefault()
                ->andReturnUsing(function ($run) {
                    $run->update(['worktree_path' => '/tmp/relay-test-'.$run->id]);

                    return $run->worktree_path;
                });
            $mock->shouldReceive('removeWorktree')->byDefault();
            $mock->shouldReceive('ensureCloned')->byDefault();
            $mock->shouldReceive('runRunScript')->byDefault()->andReturn(null);
            $mock->shouldReceive('recoverStaleWorktrees')->byDefault()->andReturn([]);
        });
    }
}
