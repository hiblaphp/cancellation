<?php

declare(strict_types=1);

namespace Hibla\Cancellation;

use Hibla\Cancellation\Internals\CancellationTokenRegistration;
use Hibla\Cancellation\Internals\CancellationTokenState;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Promise\Interfaces\PromiseInterface;

final readonly class CancellationToken
{
    /**
     * @internal Only CancellationTokenSource should create tokens
     *
     * @param CancellationTokenState $state The shared state with the source
     */
    public function __construct(
        private CancellationTokenState $state
    ) {
    }

    /**
     * Get a token that can never be cancelled.
     *
     * This is useful as a default parameter value for functions that accept
     * an optional cancellation token. It's more efficient than creating a
     * new source that never cancels.
     *
     * @return self A token that will never be cancelled
     *
     * @example
     * ```php
     * function doWork(CancellationToken $token = null): void
     * {
     *     $token ??= CancellationToken::none();
     *     // ... use token safely
     * }
     * ```
     */
    public static function none(): self
    {
        /** @var self|null $noneToken */
        static $noneToken = null;
        if ($noneToken === null) {
            $noneToken = new self(new CancellationTokenState());
        }

        return $noneToken;
    }

    /**
     * Check if cancellation has been requested.
     *
     * This is the non-throwing way to check for cancellation. Use this when
     * you want to handle cancellation gracefully without exceptions.
     *
     * @return bool True if cancellation was requested, false otherwise
     *
     * @example
     * ```php
     * while (!$token->isCancelled()) {
     *     // Continue working
     *     processNextItem();
     * }
     * ```
     */
    public function isCancelled(): bool
    {
        return $this->state->cancelled;
    }

    /**
     * Throw an exception if cancellation has been requested.
     *
     * This is the primary way to check for cancellation in async code.
     * Place strategic calls to this method at points where it's safe to
     * stop the operation.
     *
     * @throws CancelledException If cancellation was requested
     *
     * @example
     * ```php
     * async function processItems(array $items, CancellationToken $token): void
     * {
     *     foreach ($items as $item) {
     *         $token->throwIfCancelled();
     *         await processItem($item);
     *     }
     * }
     * ```
     */
    public function throwIfCancelled(): void
    {
        if ($this->state->cancelled) {
            throw new CancelledException('Operation was cancelled');
        }
    }

    /**
     * Register a callback to execute when cancellation occurs.
     *
     * Returns a registration object that can be used to unregister the callback
     * before cancellation occurs. This is useful when:
     *
     * - An operation completes and cleanup is no longer needed
     * - You're using a long-lived token with many short-lived operations
     * - You want conditional cleanup based on operation outcome
     *
     * Use this for cleanup operations like closing connections, releasing
     * resources, or logging cancellation events. Callbacks are executed
     * synchronously in registration order during cancel().
     *
     * If the token is already cancelled, the callback executes immediately
     * and a pre-disposed registration is returned.
     *
     * IMPORTANT: Callbacks should be fast and non-blocking. They execute
     * synchronously during cancel(). Avoid throwing exceptions in callbacks
     * as this can prevent subsequent callbacks from running.
     *
     * @param callable(): void $callback The function to call on cancellation
     * @return CancellationTokenRegistration Registration object for unregistering the callback
     *
     * @example
     * ```php
     * // Basic usage
     * $registration = $token->onCancel(function () use ($connection) {
     *     $connection->close();
     *     echo "Connection closed due to cancellation\n";
     * });
     *
     * // Conditional cleanup
     * $registration = $token->onCancel(fn() => $tempFile->delete());
     *
     * try {
     *     $result = await($operation);
     *     $registration->dispose();  // Success - keep the file
     *     return $result;
     * } catch (Exception $e) {
     *     // On error, let cancellation delete temp file
     *     throw $e;
     * }
     * ```
     */
    public function onCancel(callable $callback): CancellationTokenRegistration
    {
        if ($this->state->cancelled) {
            $callback();

            $dummyState = new CancellationTokenState();
            $dummyState->cancelled = true;

            return new CancellationTokenRegistration($dummyState, -1);
        }

        $callbackId = $this->state->nextCallbackId++;
        $this->state->callbacks[$callbackId] = $callback;

        return new CancellationTokenRegistration($this->state, $callbackId);
    }

    /**
     * Track a promise for automatic cancellation.
     *
     * When you track a promise, it will be automatically cancelled if the token
     * is cancelled. The promise is automatically untracked when it settles.
     *
     * This is useful for managing multiple concurrent operations that should all
     * be cancelled together.
     *
     * @template TValue
     * @param PromiseInterface<TValue> $promise The promise to track
     * @return PromiseInterface<TValue> The same promise (for chaining)
     *
     * @example
     * ```php
     * $cts = new CancellationTokenSource();
     * $token = $cts->token;
     *
     * $promise1 = $token->track(asyncOperation1());
     * $promise2 = $token->track(asyncOperation2());
     *
     * // Cancelling will cancel both promises
     * $cts->cancel();
     * ```
     */
    public function track(PromiseInterface $promise): PromiseInterface
    {
        if ($promise->isSettled()) {
            return $promise;
        }

        if ($this->state->cancelled) {
            if (! $promise->isCancelled()) {
                $promise->cancel();
            }

            return $promise;
        }

        $key = $this->state->nextPromiseKey++;
        $this->state->trackedPromises[$key] = $promise;

        $promiseId = spl_object_id($promise);
        $this->state->promiseKeyMap[$promiseId] = $key;

        $weakThis = \WeakReference::create($this);

        $promise->finally(static function () use ($weakThis, $promiseId): void {
            $token = $weakThis->get();
            if ($token !== null) {
                $token->untrackById($promiseId);
            }
        });

        return $promise;
    }

    /**
     * Stop tracking a promise.
     *
     * After untracking, the promise will no longer be automatically cancelled
     * when the token is cancelled. This is rarely needed as promises are
     * automatically untracked when they settle.
     *
     * @param PromiseInterface<mixed> $promise The promise to stop tracking
     *
     * @example
     * ```php
     * $promise = $token->track(someOperation());
     *
     * // Later, if you want to stop tracking
     * $token->untrack($promise);
     * ```
     */
    public function untrack(PromiseInterface $promise): void
    {
        $this->untrackById(spl_object_id($promise));
    }

    /**
     * Get the number of promises currently being tracked.
     *
     * Useful for monitoring and debugging to see how many operations are
     * still pending cancellation.
     *
     * @return int Number of tracked promises
     */
    public function getTrackedCount(): int
    {
        return \count($this->state->trackedPromises);
    }

    /**
     * Clear all tracked promises without cancelling them.
     *
     * This removes all promises from tracking but doesn't cancel them.
     * Useful when you want to stop managing a batch of operations but
     * let them complete naturally.
     */
    public function clearTracked(): void
    {
        $this->state->trackedPromises = [];
        $this->state->promiseKeyMap = [];
    }

    /**
     * Remove a promise from tracking by its object ID.
     *
     * @param int $promiseId The spl_object_id of the promise
     */
    private function untrackById(int $promiseId): void
    {
        if (isset($this->state->promiseKeyMap[$promiseId])) {
            $key = $this->state->promiseKeyMap[$promiseId];
            unset(
                $this->state->trackedPromises[$key],
                $this->state->promiseKeyMap[$promiseId]
            );
        }
    }
}
