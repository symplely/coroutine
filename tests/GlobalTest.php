<?php

namespace Async\Tests;

use Async\Coroutine\Coroutine;
use PHPUnit\Framework\TestCase;

class GlobalTest extends TestCase 
{
    protected $task = null;

	protected function setUp(): void
    {
        global $__coroutine__;
        
        $__coroutine__ = null;
    }

    public function childTask() 
    {
        $tid = yield async_id();
        while (true) {
            $this->task .= "Child task $tid still alive!\n";
            yield;
        }
    }

    public function parentTask() 
    {
        $tid = yield async_id();
        $childTid = yield await([$this, 'childTask']);
        
        for ($i = 1; $i <= 6; ++$i) {
            $this->task .= "Parent task $tid iteration $i.\n";
            yield;
        
            if ($i == 3) $this->assertNull(yield async_remove($childTid));
        }
    }

    /**
     * @covers Async\Coroutine\Coroutine::createTask
     * @covers Async\Coroutine\Coroutine::schedule
     * @covers Async\Coroutine\Coroutine::create
     * @covers Async\Coroutine\Coroutine::removeTask
     * @covers Async\Coroutine\Coroutine::run
     * @covers Async\Coroutine\Task::taskId
     * @covers Async\Coroutine\Task::run
     * @covers \async_id
     * @covers \async
     * @covers \awaitAble
     * @covers \async_remove
     * @covers \coroutineInstance
     * @covers \coroutineCreate
     * @covers \coroutineRun
     */
    public function testGlobalFunctions() 
    {
        $this->task = '';

        \coroutineCreate( \awaitAble([$this, 'parentTask']) );
        \coroutineRun();        

        $expect[] = "Parent task 1 iteration 1.";
        $expect[] = "Child task 3 still alive!";
        $expect[] = "Parent task 1 iteration 2.";
        $expect[] = "Child task 3 still alive!";
        $expect[] = "Parent task 1 iteration 3.";
        $expect[] = "Child task 3 still alive!";
        $expect[] = "Parent task 1 iteration 4.";
        $expect[] = "Parent task 1 iteration 5.";
        $expect[] = "Parent task 1 iteration 6.";

        foreach ($expect as $iteration)
            $this->assertStringContainsString($iteration, $this->task);

        $this->assertNotEquals(4, preg_match_all('/Child task 3/', $this->task, $matches));
        $this->assertEquals(3, preg_match_all('/Child task 3 still alive!/', $this->task, $matches));
        $this->assertEquals(6, preg_match_all('/Parent task 1/', $this->task, $matches));
        $this->assertEquals(9, preg_match_all('/task/', $this->task, $matches));
    }
}
