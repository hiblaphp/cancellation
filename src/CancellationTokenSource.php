<?php

declare(strict_types=1);

namespace Hibla\Cancellation;

use Hibla\Cancellation\Internals\CancellationTokenState;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\AggregateErrorException;

final class CancellationTokenSource
{
    /**
     * The token associated with this source.
     *
     * Multiple calls return the same token instance. The token allows
     * operations to monitor for cancellation requests.
     */
    public readonly CancellationToken $token;

    private readonly CancellationTokenState $state;

    private ?string $timerId = null;

    /**
     * Create a new CancellationTokenSource.
     *
     * @param float|null $timeoutSeconds Optional timeout in seconds. If provided,
     *                                    the source will automatically cancel after
     *                                    this duration.
     *
     * @example
     * ```php
     * // No timeout
     * $cts = new CancellationTokenSource();
     *
     * // With 5 second timeout
     * $cts = new CancellationTokenSource(5.0);
     * ```
     */
    public function __construct(?float $timeoutSeconds = null)
    {
        $this->state = new CancellationTokenState();
        $this->token = new CancellationToken($this->state);

        if ($timeoutSeconds !== null) {
            $this->cancelAfter($timeoutSeconds);
        }
    }

    /**
     * Request cancellation of all operations using this source's token.
     *
     * Cancels all tracked promises using forward-only cancellation.
     * Only the tracked promise and its children are cancelled — parent
     * promises in the chain are not affected.
     *
     * Use this when you track root promises directly, or when you don't
     * need upstream resource cleanup beyond what the tracked promise handles.
     *
     * This method:
     * 1. Marks the token as cancelled
     * 2. Cancels any pending timeout timer
     * 3. Invokes all registered onCancel() callbacks
     * 4. Calls cancel() on all tracked promises (forward only)
     *
     * Calling cancel() multiple times is safe — subsequent calls have no effect.
     *
     * @throws \Throwable If any callback or promise cancellation throws an exception
     * @throws AggregateErrorException If multiple exceptions occur during cancellation
     *
     * @example
     * ```php
     * $root  = fetch('/api/users');                    // onCancel() → abort HTTP
     * $child = $root->then(fn($r) => json_decode($r));
     *
     * // Track the root directly — cancel() is sufficient
     * $cts->token->track($root);
     * $cts->cancel();
     * // $root->onCancel() fires → HTTP aborted ✅
     * // $child cancelled via forward propagation ✅
     * ```
     */
    public function cancel(): void
    {
        if ($this->state->cancelled) {
            return;
        }

        $this->state->cancelled = true;
        $this->clearTimer();

        $exceptions = [];
        $this->invokeCallbacks($exceptions);

        $promises = $this->state->trackedPromises;
        $this->state->trackedPromises = [];
        $this->state->promiseKeyMap = [];

        foreach ($promises as $promise) {
            if (! $promise->isSettled() && ! $promise->isCancelled()) {
                try {
                    $promise->cancel();
                } catch (\Throwable $e) {
                    $exceptions[] = $e;
                }
            }
        }

        $this->throwAggregateIfNeeded($exceptions);
    }

    /**
     * Request cancellation and propagate up to root producers.
     *
     * Like cancel(), but uses cancelChain() on each tracked promise —
     * walking UP to the root producer before cancelling DOWN the entire
     * chain. This ensures root producers fire their onCancel() handlers
     * for proper resource cleanup (timers, HTTP requests, DB connections).
     *
     * Use this when you track child promises but need upstream resource
     * cleanup to fire on the root producer.
     *
     * This method:
     * 1. Marks the token as cancelled
     * 2. Cancels any pending timeout timer
     * 3. Invokes all registered onCancel() callbacks
     * 4. Calls cancelChain() on all tracked promises (walks to root)
     *
     * Calling cancelChain() multiple times is safe — subsequent calls have no effect.
     *
     * IMPORTANT: cancelChain() will cancel root producers even if they are
     * shared references used elsewhere. Only use this when you own the full
     * promise chain and no external code holds references to ancestor promises.
     *
     * @throws \Throwable If any callback or promise cancellation throws an exception
     * @throws AggregateErrorException If multiple exceptions occur during cancellation
     *
     * @example
     * ```php
     * $root  = fetch('/api/users');                    // onCancel() → abort HTTP
     * $child = $root->then(fn($r) => json_decode($r));
     *
     * // Only hold child reference but need root cleanup
     * $cts->token->track($child);
     * $cts->cancelChain();
     * // walks up to $root ✅
     * // $root->onCancel() fires → HTTP aborted ✅
     * // $child cancelled via forward propagation ✅
     * // nothing outside this chain is touched ✅
     *
     * // Compare with cancel() on a tracked child:
     * $cts->token->track($child);
     * $cts->cancel();
     * // $child cancelled ✅
     * // $root keeps running ← by design, use cancel() when you own the root
     * ```
     */
    public function cancelChain(): void
    {
        if ($this->state->cancelled) {
            return;
        }

        $this->state->cancelled = true;
        $this->clearTimer();

        $exceptions = [];
        $this->invokeCallbacks($exceptions);

        $promises = $this->state->trackedPromises;
        $this->state->trackedPromises = [];
        $this->state->promiseKeyMap = [];

        foreach ($promises as $promise) {
            if (! $promise->isSettled() && ! $promise->isCancelled()) {
                try {
                    $promise->cancelChain();
                } catch (\Throwable $e) {
                    $exceptions[] = $e;
                }
            }
        }

        $this->throwAggregateIfNeeded($exceptions);
    }

    /**
     * Schedule automatic cancellation after a specified duration.
     *
     * This method allows you to set or reset the cancellation timeout dynamically.
     * If called multiple times, subsequent calls will reset the timer (cancelling
     * the previous timer if the source hasn't been cancelled yet).
     *
     * This is a convenient way to implement timeouts. The source will
     * automatically call cancel() after the specified time elapses.
     *
     * @param float $seconds Number of seconds until automatic cancellation
     *
     * @example
     * ```php
     * $cts = new CancellationTokenSource();
     * $cts->cancelAfter(5.0); // Cancel after 5 seconds
     *
     * // Later, reset the timeout
     * $cts->cancelAfter(10.0); // Now cancels after 10 seconds instead
     *
     * $promise = longRunningOperation($cts->token);
     * ```
     */
    public function cancelAfter(float $seconds): void
    {
        if ($this->state->cancelled) {
            return;
        }

        $this->clearTimer();

        $weakThis = \WeakReference::create($this);

        $this->timerId = Loop::addTimer($seconds, function () use ($weakThis) {
            $source = $weakThis->get();
            if ($source !== null) {
                $source->timerId = null;
                $source->cancel();
            }
        });
    }

    /**
     * Create a linked cancellation token source that cancels when ANY source token cancels.
     *
     * This is useful for combining multiple cancellation sources (user cancellation,
     * timeout, system shutdown, etc.) into a single token. The returned source will
     * automatically cancel if any of the input tokens are cancelled.
     *
     * Use Cases:
     * - Combine user cancellation with timeout
     * - Coordinate cancellation across multiple operations
     * - Create fallback cancellation strategies
     *
     * @param CancellationToken ...$tokens One or more source tokens to link
     * @return self A new source that cancels when any input token cancels
     *
     * @example
     * ```php
     * $userCts    = new CancellationTokenSource();
     * $timeoutCts = new CancellationTokenSource(10.0);
     *
     * $linkedCts = CancellationTokenSource::createLinkedTokenSource(
     *     $userCts->token,
     *     $timeoutCts->token
     * );
     *
     * // Operation cancels if user cancels OR timeout expires
     * doWork($linkedCts->token);
     * ```
     */
    public static function createLinkedTokenSource(CancellationToken ...$tokens): self
    {
        $linkedSource = new self();

        if (\count($tokens) === 0) {
            return $linkedSource;
        }

        foreach ($tokens as $token) {
            if ($token->isCancelled()) {
                $linkedSource->cancel();

                return $linkedSource;
            }
        }

        $weakLinked = \WeakReference::create($linkedSource);

        foreach ($tokens as $token) {
            $token->onCancel(function () use ($weakLinked): void {
                $source = $weakLinked->get();
                if ($source !== null) {
                    $source->cancel();
                }
            });
        }

        return $linkedSource;
    }

    /**
     * Cancel the pending timeout timer if one exists.
     */
    private function clearTimer(): void
    {
        if ($this->timerId !== null) {
            Loop::cancelTimer($this->timerId);
            $this->timerId = null;
        }
    }

    /**
     * Invoke all registered cancellation callbacks, collecting any exceptions.
     *
     * @param array<\Throwable> $exceptions
     */
    private function invokeCallbacks(array &$exceptions): void
    {
        $callbacks = $this->state->callbacks;
        $this->state->callbacks = [];

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $exceptions[] = $e;
            }
        }
    }

    /**
     * Clean up resources — called automatically on destruction.
     */
    private function cleanup(): void
    {
        $this->clearTimer();
        $this->state->callbacks = [];
        $this->state->trackedPromises = [];
        $this->state->promiseKeyMap = [];
    }

    /**
     * @param array<\Throwable> $exceptions
     * @throws \Throwable
     * @throws AggregateErrorException
     */
    private function throwAggregateIfNeeded(array $exceptions): void
    {
        if (\count($exceptions) === 1) {
            throw $exceptions[0];
        }

        if (\count($exceptions) > 1) {
            $errorMessages = [];
            foreach ($exceptions as $index => $exception) {
                $errorMessages[] = \sprintf(
                    '#%d: [%s] %s in %s:%d',
                    $index + 1,
                    \get_class($exception),
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine()
                );
            }

            throw new AggregateErrorException(
                $exceptions,
                \sprintf(
                    "Cancellation encountered %d error(s):\n%s",
                    \count($exceptions),
                    implode("\n", $errorMessages)
                )
            );
        }
    }

    /**
     * Automatic cleanup when the source is destroyed.
     */
    public function __destruct()
    {
        $this->cleanup();
    }
}
