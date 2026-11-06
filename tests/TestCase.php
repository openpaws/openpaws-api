<?php

namespace Tests;

use App\Storage\FakeObjectStorage;
use App\Storage\ObjectStorage;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected FakeObjectStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // Every test gets a clean fake object store instead of the real S3 one.
        $this->storage = new FakeObjectStorage;
        $this->storage->flush();
        $this->app->instance(ObjectStorage::class, $this->storage);
    }

    protected function tearDown(): void
    {
        $this->storage->flush();

        parent::tearDown();
    }
}
