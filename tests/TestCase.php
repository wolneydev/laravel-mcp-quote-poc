<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Bind local/public disks to a workspace-writable root.
     *
     * Laravel's Storage::fake() uses storage/framework/testing/disks, which may not be writable here.
     */
    protected function fakePrivateStorageDisks(): void
    {
        $filesystem = new Filesystem;
        $base = base_path('.phpunit-disks');

        foreach (['local', 'public'] as $disk) {
            $root = $base.'/'.$disk;
            $filesystem->ensureDirectoryExists($root);
            $filesystem->cleanDirectory($root);

            Storage::set($disk, Storage::createLocalDriver([
                'root' => $root,
                'throw' => false,
            ]));
        }
    }
}
