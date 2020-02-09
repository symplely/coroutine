<?php

namespace Async\Tests;

use Async\Coroutine\UV;
use Async\Coroutine\Coroutine;
use PHPUnit\Framework\TestCase;

class CoroutineSignalerTest extends TestCase
{
    protected $loop;

    protected function setUp(): void
    {
        if (!\function_exists('posix_kill') || !\function_exists('posix_getpid')) {
            if (!\function_exists('uv_loop_new'))
                $this->markTestSkipped(
                    'Signal test skipped because functions "posix_kill" and "posix_getpid", or "uv_loop_new" are missing.'
                );
        }

        \coroutine_clear();
    }

    public function testSignalMultipleUsagesForTheSameListener()
    {
        $loop = new Coroutine();
        $funcCallCount = 0;
        $func = function () use (&$funcCallCount) {
            $funcCallCount++;
        };
        $loop->addTimeout(function () {
        }, 1);
        $loop->addSignal(UV::SIGUSR1, $func);
        $loop->addSignal(UV::SIGUSR1, $func);
        $loop->addTimeout(function ()  use ($loop) {
            if (function_exists('posix_kill') || function_exists('posix_getpid'))
                posix_kill(posix_getpid(), UV::SIGUSR1);
            else
                $loop->getSignaler()->execute(UV::SIGUSR1);
        }, 0.4);
        $loop->addTimeout(function () use (&$func, $loop) {
            $loop->removeSignal(UV::SIGUSR1, $func);
        }, 0.9);

        $loop->run();
        $this->assertSame(1, $funcCallCount);
    }

    public function testSignalsKeepTheLoopRunningAndRemovingItStopsTheLoop()
    {
        $loop = $this->loop = new Coroutine();
        $function = function () {
        };
        $loop->addSignal(UV::SIGUSR1, $function);
        $loop->addTimeout(function () use ($function, $loop) {
            $loop->removeSignal(UV::SIGUSR1, $function);
        }, 1.5);
        $this->assertRunFasterThan(1.6);
    }

    private function assertRunFasterThan($maxInterval)
    {
        $start = microtime(true);
        $this->loop->run();
        $end = microtime(true);
        $interval = $end - $start;
        $this->assertLessThan($maxInterval, $interval);
    }
}
