<?php

declare(strict_types = 1);

namespace Async\Coroutine;

use Async\Coroutine\Coroutine;
use Async\Coroutine\TaskInterface;
use Async\Coroutine\ResultValueCoroutine;
use Async\Coroutine\Exceptions\CancelledError;
use Async\Coroutine\Exceptions\InvalidStateError;

/**
 * Task is used to schedule coroutines concurrently.
 * When a coroutine is wrapped into a Task with functions like Coroutine::createTask()
 * the coroutine is automatically scheduled to run soon.
 *
 * This Task class can also be seen to operate like an Fiber according to the RFC spec https://wiki.php.net/rfc/fiber
 *
 * @see https://curio.readthedocs.io/en/latest/reference.html#tasks
 */
class Task implements TaskInterface
{
    /**
     * The task’s id.
     *
     * @var int
     */
    protected $taskId;

    /**
     * A flag that indicates whether or not a task is daemonic.
     *
     * @var bool
     */
    protected $daemon;

    /**
     * A flag that indicates whether or not a task is an is parallel process.
     *
     * @var bool
     */
    protected $subprocess = false;

    /**
     * The number of scheduling cycles the task has completed.
     * This might be useful if you’re trying to figure out if a task is running or not.
     * Or if you’re trying to monitor a task’s progress.
     *
     * @var int
     */
    protected $cycles = 0;

    /**
     * The underlying coroutine associated with the task.
     *
     * @var mixed
     */
    protected $coroutine;

    /**
     * The name of the task’s current state. Printing it can be potentially useful for debugging.
     *
     * @var string
     */
    protected $state = null;

    /**
     * The result of a task.
     *
     * @var mixed
     */
    protected $result;
    protected $sendValue = null;

    protected $beforeFirstYield = true;

    /**
     * Exception raised by a task, if any.
     *
     * @var object
     */
    protected $error;
    protected $exception = null;

    public function __construct($taskId, \Generator $coroutine)
	{
        $this->taskId = $taskId;
        $this->state = 'pending';
        $this->coroutine = Coroutine::create($coroutine);
    }

    public function taskId(): int
	{
        return $this->taskId;
    }

    public function cyclesAdd()
	{
        $this->cycles++;
    }

    public function sendValue($sendValue)
	{
        $this->sendValue = $sendValue;
    }

    public function setException($exception)
	{
        $this->exception = $exception;
    }

    public function run()
	{
        if ($this->beforeFirstYield) {
            $this->beforeFirstYield = false;
            return $this->coroutine->current();
        } elseif ($this->exception) {
            $value = $this->coroutine->throw($this->exception);
            $this->error = $this->exception;
            $this->exception = null;
            return $value;
        } else {
            $value = $this->coroutine->send($this->sendValue);
            if (!empty($value))
                $this->result = $value;

            $this->sendValue = null;
            return $value;
        }
    }

    public function isFinished(): bool
	{
        return !$this->coroutine->valid();
    }

    public function setState(string $status)
	{
        $this->state = $status;
    }

    public function getState(): string
	{
        return $this->state;
    }

    public function exception(): \Exception
    {
        return $this->error;
    }

    public function parallelTask()
    {
        $this->subprocess = true;
    }

    public function isParallel(): bool
    {
        return $this->subprocess;
    }

    public function erred(): bool
    {
        return ($this->state == 'erred');
    }

    public function pending(): bool
    {
        return ($this->state == 'pending');
    }

    public function cancelled(): bool
    {
        return ($this->state == 'cancelled');
    }

    public function completed(): bool
    {
        return ($this->state == 'completed');
    }

    public function rescheduled(): bool
    {
        return ($this->state == 'rescheduled');
    }

    public function clearResult()
    {
        $this->result = null;
    }

    public function result()
    {
        if ($this->completed())
            return $this->result;
        elseif ($this->cancelled() || $this->erred())
            throw $this->error;
        else
            throw new InvalidStateError();
    }
}
