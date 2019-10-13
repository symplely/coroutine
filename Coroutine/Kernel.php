<?php

declare(strict_types = 1);

namespace Async\Coroutine;

use Async\Coroutine\Channel;
use Async\Coroutine\Coroutine;
use Async\Coroutine\TaskInterface;
use Async\Coroutine\Exceptions\TimeoutError;
use Async\Coroutine\Exceptions\CancelledError;

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
    protected static $gatherResumer = null;
    protected static $gatherCount = 0;
    protected static $gatherShouldError = true;

    public function __construct(callable $callback)
	{
        $this->callback = $callback;
    }

	/**
	 * Tells the scheduler to pass the calling task and itself into the function.
	 *
	 * @param TaskInterface $task
	 * @param CoroutineInterface $coroutine
	 * @return mixed
	 */
    public function __invoke(TaskInterface $task, CoroutineInterface $coroutine)
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
			function(TaskInterface $task, CoroutineInterface $coroutine) {
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
			function(TaskInterface $task, CoroutineInterface $coroutine) use ($coroutines) {
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
			function(TaskInterface $task, CoroutineInterface $coroutine) {
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
			function(TaskInterface $task, CoroutineInterface $coroutine) use ($channel) {
				$channel->receiver((int) $task->taskId());
				$coroutine->schedule($task);
			}
		);
	}

	/**
	 * Wait to receive message, similar to Google Go language
	 *
     * @param mixed $message
	 * @param int $taskId
	 */
	public static function receive(Channel $channel)
	{
		return new Kernel(
			function(TaskInterface $task, CoroutineInterface $coroutine) use ($channel) {
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
			function(TaskInterface $task, CoroutineInterface $coroutine) use ($channel, $message, $taskId) {
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

	/**
	 * kill/remove an task using task id,
     * optionally pass custom cancel state and error message for third party code integration.
	 *
	 * @param int $tid
	 * @param mixed $customState
	 * @param string $errorMessage
     *
	 * @throws \InvalidArgumentException
	 */
	public static function cancelTask($tid, $customState = null, string $errorMessage = 'Invalid task ID!')
	{
		return new Kernel(
			function(TaskInterface $task, CoroutineInterface $coroutine) use ($tid, $customState, $errorMessage) {
				if ($coroutine->cancelTask($tid, $customState)) {
					$task->sendValue(true);
					$coroutine->schedule($task);
				} else {
					throw new \InvalidArgumentException($errorMessage);
				}
			}
		);
	}

    /**
     * Performs a clean shutdown.
     * Should be called at application exit.
     */
	public static function shutdown()
	{
		return new Kernel(
			function(TaskInterface $task, CoroutineInterface $coroutine) {
                $tasks = $coroutine->taskList();
                $coroutine->shutdown();
				$coroutine->schedule($tasks[1]);
			}
		);
	}

    /**
     * Wait on read stream/socket to be ready read from,
	 * optionally schedule current task to execute immediately/next for third party code integration.
     *
     * @param resource $streamSocket
     * @param bool $immediately
     */
	public static function readWait($streamSocket, bool $immediately = false)
	{
		return new Kernel(
			function(TaskInterface $task, CoroutineInterface $coroutine) use ($streamSocket, $immediately) {
				$coroutine->addReader($streamSocket, $task);
                if ($immediately) {
					$coroutine->schedule($task);
				}
			}
		);
	}

    /**
     * Wait on write stream/socket to be ready to be written to,
	 * optionally schedule current task to execute immediately/next for third party code integration.
     *
     * @param resource $streamSocket
     * @param bool $immediately
     */
	public static function writeWait($streamSocket, bool $immediately = false)
	{
		return new Kernel(
			function(TaskInterface $task, CoroutineInterface $coroutine) use ($streamSocket, $immediately) {
				$coroutine->addWriter($streamSocket, $task);
                if ($immediately) {
					$coroutine->schedule($task);
				}
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
			function(TaskInterface $task, CoroutineInterface $coroutine) use ($delay, $result) {
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
			function(TaskInterface $task, CoroutineInterface $coroutine) use ($callable, $timeout) {
				$task->parallelTask();
				$task->setState('process');
				$coroutine->createSubProcess($callable, $timeout)
				->then(function($result) use ($task, $coroutine) {
					$task->setState('completed');
					$task->sendValue($result);
					$coroutine->schedule($task);
				})
				->catch(function(\Exception $error) use ($task, $coroutine) {
					$task->setState('erred');
					$task->setException(new \RuntimeException($error->getMessage()));
					$coroutine->schedule($task);
				})
				->timeout(function() use ($task, $coroutine, $timeout) {
					$task->setState('cancelled');
					$task->setException(new TimeoutError($timeout));
					$coroutine->schedule($task);
				});
			}
		);
	}

	/**
	 * Controls how the `gather()` function operates.
	 *
	 * @param int $race - If set, initiate a competitive race between multiple tasks.
	 * - When amount of tasks as completed, the `gather` will return with task results.
	 * - When `0` (default), will wait for all to complete.
	 * @param bool $exception - If `true` (default), the first raised exception is
	 * immediately propagated to the task that awaits on gather(). Other awaitables in
	 * the aws sequence won't be cancelled and will continue to run.
	 * - If `false`, exceptions are treated the same as successful results, and aggregated in the result list.
	 * @throws \LengthException - If the number of tasks less than the desired $race.
	 */
	public static function gatherOptions(int $race = 0, bool $exception = true)
	{
		self::$gatherCount = $race;
		self::$gatherShouldError = $exception;
	}

	/**
	 * Run awaitable objects in the taskId sequence concurrently.
	 * If any awaitable in taskId is a coroutine, it is automatically scheduled as a Task.
	 *
	 * If all awaitables are completed successfully, the result is an aggregate list of returned values.
	 * The order of result values corresponds to the order of awaitables in taskId.
	 *
	 * The first raised exception is immediately propagated to the task that awaits on gather().
	 * Other awaitables in the sequence won't be cancelled and will continue to run.
	 *
	 * @see https://docs.python.org/3.7/library/asyncio-task.html#asyncio.gather
	 *
	 * @param int|array $taskId
	 * @return array
	 */
    public static function gather(...$taskId)
	{
		return new Kernel(
			function(TaskInterface $task, CoroutineInterface $coroutine) use ($taskId) {
				if (!empty(self::$gatherResumer)) {
					[$taskIdList, $count, $results, $taskList] = self::$gatherResumer;
                } else {
					$gatherCount = self::$gatherCount;
					$gatherShouldError = self::$gatherShouldError;
					self::gatherOptions();

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
					$count = \count($taskIdList);
					$gatherSet = ($gatherCount > 0);
					if ($gatherSet) {
						if ($count < $gatherCount) {
							throw new \LengthException(\sprintf('The (%d) tasks, not enough to fulfill the `gatherOptions(%d)` race count!', $count, $gatherCount));
						}
					}

					$taskList = $coroutine->taskList();

					$completeList = $coroutine->completedList();
                    $countComplete = \count($completeList);
                    $gatherCompleteCount = 0;

                	// Check and handle tasks already completed before entering/executing gather().
					if ($countComplete > 0) {
						foreach($completeList as $id => $tasks) {
							if (isset($taskIdList[$id])) {
								$results[$id] = $tasks->result();
                                $count--;
                                $gatherCompleteCount++;
								unset($taskIdList[$id]);

								// Update running task list.
								self::updateList($coroutine, $id, $completeList);

								// end loop, if gather race count reached
                                if ($gatherCompleteCount == $gatherCount)
                                    break;
							}
						}
					}
				}

                // Check and update base off gather race and completed count.
                if ($gatherSet) {
                    $subCount = ($gatherCount - $gatherCompleteCount);
                    if ($gatherCompleteCount != $gatherCount) {
                        $count = $subCount;
                    } elseif ($gatherCompleteCount == $gatherCount) {
                        $count = 0;
                    }
                }

                // Run and wait until race or count is reached.
				while ($count > 0) {
					foreach($taskIdList as $id) {
						if (isset($taskList[$id])) {
							$tasks = $taskList[$id];

                            // Handle if parallel task.
							if ($tasks->isParallel()) {
								$completeList = $coroutine->completedList();
								if (isset($completeList[$id])) {
									$tasks = $completeList[$id];
									$results[$id] = $tasks->result();
									$count--;
									unset($taskIdList[$id]);
									self::updateList($coroutine, $id, $completeList);
									if ($gatherSet) {
										$subCount--;
										if ($subCount == 0)
											break;
									}
								}

                            	// Handle if parallel task process not running, force run.
								if ($tasks->process()) {
									$coroutine->execute();
                                }

                            // Handle if task not running/pending, force run.
							} elseif ($tasks->pending() || $tasks->rescheduled()) {
								if ($tasks->pending() && $tasks->isCustomState(true)) {
									$tasks->customState();
									$coroutine->schedule($tasks);
									$tasks->run();
                                    continue;
								}

								$coroutine->execute();

							// Handle if task finished.
							} elseif ($tasks->completed()) {
								$results[$id] = $tasks->result();
								$count--;
								unset($taskList[$id]);

								// Update running task list.
								self::updateList($coroutine, $id);

								// end loop, if set and race count reached
								if ($gatherSet) {
									$subCount--;
									if ($subCount == 0)
										break;
                                }

                            // Handle if task erred or cancelled.
							} elseif ($tasks->erred() || $tasks->cancelled()) {
                                $exception = $tasks->cancelled() ? new CancelledError() : $tasks->exception();
								$count--;
								unset($taskList[$id]);

                                // Update running task list.
								self::updateList($coroutine, $id);

                                // Check and propagate/schedule the exception.
                                if ($gatherShouldError) {
                                    self::$gatherResumer = [$taskIdList, $count, $results, $taskList];
                                    $task->setException($exception);
                                    $coroutine->schedule($tasks);
                                }
							}
						}
					}
				}

				self::$gatherResumer = null;
				$task->sendValue($results);
				$coroutine->schedule($task);
			}
		);
	}

    /**
     * Update current/running task list.
     */
	protected static function updateList(CoroutineInterface $coroutine, int $taskList, array $completeList = [])
	{
		if (empty($completeList)) {
			$completeList = $coroutine->completedList();
		}

		unset($completeList[$taskList]);
		$coroutine->updateCompleted($completeList);
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
			function(TaskInterface $task, CoroutineInterface $coroutine) use ($callable, $timeout) {
				if ($callable instanceof \Generator) {
					$taskId = $coroutine->createTask($callable);
				} else {
					$taskId = $coroutine->createTask(\awaitAble($callable));
				}

				$coroutine->addTimeout(function () use ($taskId, $timeout, $task, $coroutine) {
					if (!empty($timeout)) {
						$coroutine->cancelTask($taskId);
						$task->setException(new TimeoutError($timeout));
						$coroutine->schedule($task);
					} else {
						$completeList = $coroutine->completedList();
						if (isset($completeList[$taskId])) {
							$tasks = $completeList[$taskId];
							$result = $tasks->result();
							self::updateList($coroutine, $taskId, $completeList);
							$task->sendValue($result);
						}
						$coroutine->schedule($task);
					}

				}, $timeout);
			}
		);
	}

	/**
	 * Makes an resolvable function from label name that's callable with `await`
	 * The passed in `function/callable/task` is wrapped to be `awaitAble`
     *
	 * This will create closure function in global namespace with supplied name as variable
     *
	 * @param string $labelFunction
	 * @param Generator|callable $asyncFunction
	 */
	public static function async(string $labelFunction, callable $asyncFunction)
	{
		$GLOBALS[$labelFunction] = function (...$args) use ($asyncFunction) {
			$return = yield $asyncFunction(...$args);
			return Coroutine::plain($return);
		};
	}

	/**
	 * Add/schedule an `yield`-ing `function/callable/task` for execution.
	 * - This function needs to be prefixed with `yield`
	 *
	 * @see https://docs.python.org/3.7/library/asyncio-task.html#asyncio.create_task
	 *
	 * @param Generator|callable $asyncLabel
	 * @param mixed $args - if `generator`, $args can hold `customState`, and `customData`
     * - if `customData` is object, and has `setId` method, store the $task id.
     * - for third party code integration.
	 *
	 * @return int $task id
	 */
	public static function await($asyncLabel, ...$args)
	{
		$isLabel = false;
		if (!\is_array($asyncLabel) && !\is_callable($asyncLabel) && !$asyncLabel instanceof \Generator) {
			global ${$asyncLabel};
			$isLabel = isset(${$asyncLabel});
		}

		if ($isLabel && (${$asyncLabel}() instanceof \Generator)) {
			return Kernel::createTask(${$asyncLabel}(...$args));
        } else {
			return new Kernel(
				function(TaskInterface $task, CoroutineInterface $coroutine) use ($asyncLabel, $args) {
					if ($asyncLabel instanceof \Generator) {
						$tid = $coroutine->createTask($asyncLabel);
						if (!empty($args)) {
                            $taskList = $coroutine->taskList();
                            if (($args[0] === 'true') || ($args[0] === true))
							    $taskList[$tid]->customState(true);
                            else
                                $taskList[$tid]->customState($args[0]);

                            if (isset($args[1])) {
                                $object = $args[1];
                                if (\is_object($object) && \method_exists($object, 'setId'))
                                    $taskList[$tid]->customData($object->setId($tid));
                                else
                                    $taskList[$tid]->customData($object);
                            }
						}

						$task->sendValue($tid);
					} else {
						$task->sendValue($coroutine->createTask(\awaitAble($asyncLabel, ...$args)));
					}

					$coroutine->schedule($task);
				}
            );
        }
	}
}
