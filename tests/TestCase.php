<?php

namespace Tests;

use App\Services\AuditService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test gets a fake `public` disk, so none can touch the real one.
     *
     * The database is a throwaway in memory; the disk is not. Once, a
     * settings test pressed "Put back to the defaults" against the real
     * `storage/app/public` and deleted the tab icons cut from a logo a person
     * had just uploaded. Faked here, a test that forgets to fake it is safe.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * Build test fixtures without writing to the audit trail.
     *
     * A model made by a factory is scenery for the test, not an action
     * somebody took, so it must not show up in the trail a test is counting.
     *
     *     $user = $this->unaudited(fn () => User::factory()->create());
     */
    protected function unaudited(callable $callback): mixed
    {
        return app(AuditService::class)->withoutAuditing($callback);
    }

    /**
     * Forget who is signed in, the way a new HTTP process would.
     *
     * One test method shares one application, so a guard that has already
     * resolved a user keeps answering with it. Production has a fresh
     * process per request, so a test that checks a token stopped working
     * has to clear the guard first.
     */
    protected function forgetSignedInUser(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
