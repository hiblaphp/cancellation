<?php

declare(strict_types=1);

namespace Hibla\Cancellation\Internals;

/**
 * @internal This class is not part of the public API and should not be used directly.
 */
final class CancellationTokenRegistration
{
    private bool $disposed = false;

    /**
     * @internal Only CancellationToken should create registrations
     * 
     * @param CancellationTokenState|null $state Shared state with the token
     * @param int $callbackId Unique identifier for this callback
     */
    public function __construct(
        private ?CancellationTokenState $state,
        private int $callbackId
    ) {
    }

    /**
     * Unregister the callback from the cancellation token.
     * 
     * After calling dispose(), the registered callback will not be invoked
     * when the token is cancelled. This is useful when:
     * 
     * - An operation completes successfully and cleanup is no longer needed
     * - You want to conditionally prevent cleanup
     * - You're using a long-lived token with many short-lived operations
     * 
     * Calling dispose() multiple times is safe - subsequent calls have no effect.
     * 
     * If the callback has already been invoked (because cancellation occurred),
     * dispose() has no effect but returns false to indicate this.
     * 
     * @return bool True if successfully unregistered, false if already disposed or callback already executed
     * 
     * @example
     * ```php
     * $registration = $token->onCancel(fn() => $file->delete());
     * 
     * try {
     *     $result = await($operation);
     *     
     *     // Success! Don't delete the file
     *     $registration->dispose();
     *     
     *     return $result;
     * } catch (Exception $e) {
     *     // On error, let cancellation cleanup happen
     *     throw $e;
     * }
     * ```
     */
    public function dispose(): bool
    {
        if ($this->disposed || $this->state === null) {
            return false;
        }

        $this->disposed = true;

        if (isset($this->state->callbacks[$this->callbackId])) {
            unset($this->state->callbacks[$this->callbackId]);
            $this->state = null;

            return true;
        }

        $this->state = null;
        return false;
    }

    /**
     * Check if this registration has been disposed.
     * 
     * @return bool True if dispose() was called, false otherwise
     */
    public function isDisposed(): bool
    {
        return $this->disposed;
    }

    /**
     * Automatically dispose when registration is destroyed.
     * 
     * This provides automatic cleanup if you forget to call dispose(),
     * though explicit disposal is still recommended.
     */
    public function __destruct()
    {
        if (!$this->disposed) {
            $this->dispose();
        }
    }
}