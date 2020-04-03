<?php

namespace Async\Tests;

use Async\Spawn\Channel;
use Async\Coroutine\Exceptions\InvalidStateError;
use PHPUnit\Framework\TestCase;

class KernelSignalProgressTest extends TestCase
{
    protected function setUp(): void
    {
        \coroutine_clear();
    }

    public function taskSpawnProgress()
    {
        $channel = new Channel;
        $realTimeTask = yield \progress_task(function ($type, $data) use ($channel) {
            $this->assertNotNull($type);
            $this->assertNotNull($data);
        });

        $realTime = yield \spawn_progress(function () {
            echo 'hello ';
            usleep(500);
            return 'world';
        }, $channel, $realTimeTask);

        $notUsing = yield \gather($realTime);
        yield \shutdown();
    }

    public function testSpawnProgress()
    {
        $this->markTestSkipped('Progress subprocess tests skipped for now, still not setup correctly.');
        \coroutine_run($this->taskSpawnProgress());
    }

    public function taskSpawnSignalDelay()
    {
        $sigTask = yield \signal_task(\SIGKILL, function ($signal) {
            $this->assertEquals(\SIGKILL, $signal);
        });

        $sigId = yield \spawn_signal(function () {
            \usleep(5000);
            return 'subprocess';
        }, \SIGKILL, $sigTask);

        $kill = yield \away(function () use ($sigId) {
            yield;
            $bool = yield \spawn_kill($sigId);
            return $bool;
        }, true);

        $output = yield \gather($sigId);
        yield \shutdown();
    }

    public function testSpawnSignalDelay()
    {
        \coroutine_run($this->taskSpawnSignalDelay());
    }

    public function taskSpawnSignalResult()
    {
        $sigTask = yield \signal_task(\SIGKILL, function ($signal) {
            $this->assertEquals(\SIGKILL, $signal);
        });

        $sigId = yield \spawn_signal(function () {
            \usleep(5000);
            return 'subprocess';
        }, \SIGKILL, $sigTask);

        $kill = yield \away(function () use ($sigId) {
            yield;
            $bool = yield \spawn_kill($sigId);
            return $bool;
        }, true);

        $output = yield \gather_wait([$sigId, $kill], 0, false);
        $this->assertEquals([null, true], [$output[$sigId], $output[$kill]]);
        yield \shutdown();
    }

    public function testSpawnSignalResult()
    {
        \coroutine_run($this->taskSpawnSignalResult());
    }

    public function taskSpawnSignal()
    {
        $sigTask = yield \signal_task(\SIGKILL, function ($signal) {
            $this->assertEquals(\SIGKILL, $signal);
        });

        $sigId = yield \spawn_signal(function () {
            sleep(2);
            return 'subprocess';
        }, \SIGKILL, $sigTask, 1);

        yield \away(function () use ($sigId) {
            return yield \spawn_kill($sigId);
        });

        $this->expectException(InvalidStateError::class);
        yield \gather($sigId);
        yield \shutdown();
    }

    public function testSpawnSignal()
    {
        \coroutine_run($this->taskSpawnSignal());
    }
}
