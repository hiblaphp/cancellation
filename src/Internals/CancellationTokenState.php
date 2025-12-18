<?php

declare(strict_types=1);

namespace Hibla\Cancellation\Internals;

use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * @internal This class is not part of the public API and should not be used directly.
 */
final class CancellationTokenState
{
    /**
     * @var array<int, PromiseInterface<mixed>>
     */
    public array $trackedPromises = [];

    /**
     * @var array<int, callable(): void>
     */
    public array $callbacks = [];

    /**
     * @var array<int, int>
     */
    public array $promiseKeyMap = [];

    public int $nextPromiseKey = 0;

    public int $nextCallbackId = 0;

    public bool $cancelled = false;
}
