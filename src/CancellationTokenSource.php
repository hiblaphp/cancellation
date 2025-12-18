<?php

declare(strict_types=1);

namespace Hibla\Cancellation;

use Hibla\Cancellation\Internals\CancellationTokenState;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\AggregateErrorException;

final class CancellationTokenSource
{
    private CancellationToken $token;
    private CancellationTokenState $state;
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
     * Get the token associated with this source.
     *
     * Multiple calls return the same token instance. The token allows
     * operations to monitor for cancellation requests.
     *
     * @return CancellationToken The cancellation token
     */
    public function token(): CancellationToken
    {
        return $this->token;
    }

    /**
     * Request cancellation of all operations using this source's token.
     *
     * This method:
     * 1. Marks the token as cancelled
     * 2. Invokes all registered callbacks (for cleanup operations)
     * 3. Cancels all tracked promises
     * 4. Cancels any pending timeout timer
     *
     * Calling `cancel()` multiple times is safe - subsequent calls have no effect.
     *
     * @throws \Throwable If any callback or promise cancellation throws an exception
     * @throws AggregateErrorException If multiple exceptions occur during cancellation
     */
    public function cancel(): void
    {
        if ($this->state->cancelled) {
            return;
        }

        $this->state->cancelled = true;

        if ($this->timerId !== null) {
            Loop::cancelTimer($this->timerId);
            $this->timerId = null;
        }

        $exceptions = [];

        $callbacks = $this->state->callbacks;
        $this->state->callbacks = [];

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $exceptions[] = $e;
            }
        }

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
     * Schedule automatic cancellation after a specified duration.
     *
     * This method allows you to set or reset the cancellation timeout dynamically.
     * If called multiple times, subsequent calls will reset the timer (cancelling
     * the previous timer if the source hasn't been cancelled yet).
     *
     * This is a convenient way to implement timeouts. The source will
     * automatically call `cancel()` after the specified time elapses.
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
     * $promise = longRunningOperation($cts->token());
     * ```
     */
    public function cancelAfter(float $seconds): void
    {
        if ($this->state->cancelled) {
            return;
        }

        // Cancel the previous timer if it exists
        if ($this->timerId !== null) {
            Loop::cancelTimer($this->timerId);
            $this->timerId = null;
        }

        $weakThis = \WeakReference::create($this);

        $this->timerId = Loop::addTimer($seconds, function () use ($weakThis) {
            $source = $weakThis->get();
            if ($source !== null) {
                $source->timerId = null; // Clear the ID since timer fired
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
     * **Use Cases:**
     * - Combine user cancellation with timeout
     * - Coordinate cancellation across multiple operations
     * - Create fallback cancellation strategies
     *
     * @param CancellationToken ...$tokens One or more source tokens to link
     * @return self A new source that cancels when any input token cancels
     *
     * @example
     * ```php
     * $userCts = new CancellationTokenSource();
     * $timeoutCts = new CancellationTokenSource(10.0);
     *
     * $linkedCts = CancellationTokenSource::createLinkedTokenSource(
     *     $userCts->token(),
     *     $timeoutCts->token()
     * );
     *
     * // Operation cancels if user cancels OR timeout expires
     * doWork($linkedCts->token());
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
     * Clean up resources (called automatically on destruction).
     */
    private function cleanup(): void
    {
        if ($this->timerId !== null) {
            Loop::cancelTimer($this->timerId);
            $this->timerId = null;
        }

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
        } elseif (\count($exceptions) > 1) {
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

            $detailedMessage = \sprintf(
                "Cancellation encountered %d error(s):\n%s",
                \count($exceptions),
                implode("\n", $errorMessages)
            );

            throw new AggregateErrorException(
                $exceptions,
                $detailedMessage
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
