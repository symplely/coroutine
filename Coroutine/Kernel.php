<?php

namespace Async\Coroutine;

use Async\Coroutine\Channel;
use Async\Coroutine\Coroutine;
use Async\Coroutine\TaskInterface;

/**
 * The Kernel
 * This class is used for Communication between the tasks and the scheduler
 * 
 * The `yield` keyword in your code, act both as an interrupt and as a way to 
 * pass information to (and from) the scheduler.
 */
class Kernel 
{
    protected $callback;

    public function __construct(callable $callback) 
	{
        $this->callback = $callback;
    }

	/**
	 * Tells the scheduler to pass the calling task and itself into the function.
	 * 
	 * @param TaskInterface $task
	 * @param Coroutine $coroutine
	 * @return mixed
	 */
    public function __invoke(TaskInterface $task, Coroutine $coroutine) 
	{
        $callback = $this->callback;
        return $callback($task, $coroutine);
    }

	/**
	 * Return the task ID
	 * 
	 * @return int
	 */
	public static function taskId() 
	{
		return new Kernel(
			function(TaskInterface $task, Coroutine $coroutine) {
				$task->sendValue($task->taskId());
				$coroutine->schedule($task);
			}
		);
	}

	/**
	 * Create an new task
	 * 
	 * @return int task ID
	 */
	public static function createTask(\Generator $coroutines) 
	{
		return new Kernel(
			function(TaskInterface $task, Coroutine $coroutine) use ($coroutines) {
				$task->sendValue($coroutine->createTask($coroutines));
				$coroutine->schedule($task);
			}
		);
	}

	/**
	 * Creates an Channel similar to Google's Go language
	 * 
	 * @return object
	 */
	public static function make() 
	{
		return new Kernel(
			function(TaskInterface $task, Coroutine $coroutine) {
				$task->sendValue(Channel::make($task, $coroutine));
				$coroutine->schedule($task);
			}
		);
	}

	/**
	 * Set Channel by task id, similar to Google Go language
	 * 
     * @param Channel $channel
	 */
	public static function receiver(Channel $channel)
	{
		return new Kernel(
			function(TaskInterface $task, Coroutine $coroutine) use ($channel) {
				$channel->receiver((int) $task->taskId());
				$coroutine->schedule($task);
			}
		);
	}

	/**
	 * Set Channel by task id, similar to Google Go language
	 * 
     * @param mixed $message
	 * @param int $taskId
	 */
	public static function receive(Channel $channel)
	{
		return new Kernel(
			function(TaskInterface $task, Coroutine $coroutine) use ($channel) {
				$channel->receive();
			}
		);
	}

	/**
	 * Send an message to Channel by task id, similar to Google Go language
	 * 
     * @param mixed $message
	 * @param int $taskId
	 */

	public static function sender(Channel $channel, $message = null, int $taskId = 0) 
	{
		return new Kernel(
			function(Coroutine $coroutine) use ($channel, $message, $taskId) {
				$taskList = $coroutine->taskList();				    
		
				if (isset($taskList[$channel->receiverId()]))
					$newTask = $taskList[$channel->receiverId()];
				elseif (isset($taskList[$taskId]))
					$newTask = $taskList[$taskId];
				else
					$newTask = $channel->senderTask();

				$newTask->sendValue($message);
				$coroutine->schedule($newTask);
			}
		);
	}

	public static function async($callable, ...$args) 
	{
	}

	public static function await($callable, ...$args) 
	{
		return new Kernel(
			function(TaskInterface $task, Coroutine $coroutine) use ($callable, $args) {
				$task->sendValue($coroutine->createTask(\awaitAble($callable, ...$args)));				
				$coroutine->schedule($task);
			}
		);
	}
	
	/**
	 * kill/remove an task using task id
	 * 
	 * @param int $tid
	 * @throws \InvalidArgumentException
	 */
	public static function cancelTask($tid) 
	{
		return new Kernel(
			function(TaskInterface $task, Coroutine $coroutine) use ($tid) {
				if ($coroutine->cancelTask($tid)) {					
					$task->sendValue(true);
					$task->setState('cancelled');
					$coroutine->schedule($task);
				} else {
					throw new \InvalidArgumentException('Invalid task ID!');
				}
			}
		);
	}

    /**
     * Wait on read stream socket to be ready read from.
     * 
     * @param resource $socket
     */
	public static function readWait($socket) 
	{
		return new Kernel(
			function(TaskInterface $task, Coroutine $coroutine) use ($socket) {
				$coroutine->addReader($socket, $task);
			}
		);
	}

    /**
     * Wait on write stream socket to be ready to be written to.
     * 
     * @param resource $socket
     */
	public static function writeWait($socket) 
	{
		return new Kernel(
			function(TaskInterface $task, Coroutine $coroutine) use ($socket) {
				$coroutine->addWriter($socket, $task);
			}
		);
	}

    /**
     * Block/sleep for delay seconds.
     * Suspends the calling task, allowing other tasks to run.
	 * 
	 * @see https://docs.python.org/3.7/library/asyncio-task.html#sleeping
	 * 
     * @param float $delay
	 * @param mixed $result - If provided, it is returned to the caller when the coroutine complete
     */
	public static function sleepFor(float $delay = 0.0, $result = null) 
	{
		return new Kernel(
			function(TaskInterface $task, Coroutine $coroutine) use ($delay, $result) {
				$coroutine->addTimeout(function () use ($task, $coroutine, $result) {
					if (!empty($result)) 
						$task->sendValue($result);
					$coroutine->schedule($task);
				}, $delay);
			}
		);
	}

	public static function awaitProcess($callable, $timeout = 300) 
	{
		return new Kernel(
			function(TaskInterface $task, Coroutine $coroutine) use ($callable, $timeout) {
				$subProcess = $coroutine->createSubProcess($callable, $timeout);

				$subProcess->then( function ($result) use ($task, $coroutine) {
					$task->sendValue($result);
					$coroutine->schedule($task);
				})
				->catch(function(\Exception $error) use ($task, $coroutine) {
					$task->setException(new \RuntimeException($error->getMessage()));
					$coroutine->schedule($task);
				})
				->timeout(function() use ($task, $coroutine) {
					$task->setException(new \OutOfBoundsException('Timed Out!'));
					$coroutine->schedule($task);
				});
			}
		);
	}

	/**
	 * Run awaitable objects in the taskId sequence concurrently.
	 * If any awaitable in taskId is a coroutine, it is automatically scheduled as a Task.
	 * 
	 * If all awaitables are completed successfully, the result is an aggregate list of returned values. 
	 * The order of result values corresponds to the order of awaitables in taskId.
	 * 
	 * The first raised exception is immediately propagated to the task that awaits on gather(). 
	 * Other awaitables in the sequence won’t be cancelled and will continue to run.
	 * 
	 * @see https://docs.python.org/3.7/library/asyncio-task.html#asyncio.gather
	 * 
	 * @param int|array $taskId
	 * @return array
	 */
    public static function gather(...$taskId)
	{
		return new Kernel(
			function(TaskInterface $task, Coroutine $coroutine) use ($taskId) {
				$taskIdList = [];
				$newIdList =(\is_array($taskId[0])) ? $taskId[0] : $taskId;

				foreach($newIdList as $id => $value) {
					if($value instanceof \Generator) {
						$id = $coroutine->createTask($value);
						$taskIdList[$id] = $id;
					} else 
						$taskIdList[$value] = $value;
				}

				$results = [];
				$count = \count($taskId);				
				$taskList = $coroutine->taskList();

				$completeList = $coroutine->completedList();		
				$countComplete = \count($completeList);
				if ($countComplete > 0) {
					foreach($completeList as $id => $tasks) {
						$results[$id] = $tasks->result();
						$count--;
						if (isset($taskIdList[$id])) {								
							unset($taskIdList[$id]);
						}
					}
				}

				while ($count != 0) {
					foreach($taskIdList as $id) {
						if (isset($taskList[$id])) {
							$tasks = $taskList[$id];
							if ($tasks->pending()) {
								$coroutine->runCoroutines();
							} elseif ($tasks->completed()) {
								$results[$id] = $tasks->result();
								$count--;
								unset($taskList[$id]);
							} elseif ($tasks->erred()) {
								$count--;
								unset($taskList[$id]);								
								$coroutine->cancelTask($id);
								$task->setException($tasks->getError());
								$coroutine->schedule($tasks);
							} elseif ($tasks->rescheduled()) {								
								$count = 0;
								unset($taskList[$id]);
								break;
							}
						}
					}
				}

				$task->sendValue($results);
				$coroutine->schedule($task);
			}
		);
	}

    /**
     * Wait for the callable to complete with a timeout.
     * 
	 * @see https://docs.python.org/3.7/library/asyncio-task.html#timeouts
	 * 
	 * @param callable $callable
     * @param float $timeout
     */
	public static function waitFor($callable, float $timeout = null) 
	{
		return new Kernel(
			function(TaskInterface $task, Coroutine $coroutine) use ($callable, $timeout) {
				if ($callable instanceof \Generator) {
					$taskId = $coroutine->createTask($callable);
				} else {
					$taskId = $coroutine->createTask(\awaitAble($callable));					
				}
				
				$coroutine->addTimeout(function () use ($taskId, $timeout, $task, $coroutine) {
					if (!empty($timeout)) {
						$coroutine->cancelTask($taskId);
						$task->setException(new \RuntimeException('The operation has exceeded the given deadline'));
						$coroutine->schedule($task);
					} else
						$coroutine->schedule($task);
						
				}, $timeout);
			}
		);
	}
}
