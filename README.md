# Hibla Cancellation

**Structured external cancellation for the Hibla async ecosystem.**

A `CancellationToken` implementation that provides a shared cancellation
signal for coordinating the cancellation of multiple independent promises
and async operations from a single control point. Complements the built-in
cancellation on `hiblaphp/promise` by adding external, user-driven
cancellation that can span across unrelated promise chains.

[![Latest Release](https://img.shields.io/github/release/hiblaphp/cancellation.svg?style=flat-square)](https://github.com/hiblaphp/cancellation/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Contents

### Fundamentals
- [Installation](#installation)
- [Introduction](#introduction)
- [How it Relates to Promise Cancellation](#how-it-relates-to-promise-cancellation)

### Core Usage
- [Basic Usage](#basic-usage)
- [Accepting a Token in Your Functions](#accepting-a-token-in-your-functions)
- [Cancellation is Synchronous](#cancellation-is-synchronous)

### Features
- [Automatic Timeout](#automatic-timeout)
- [Linking Multiple Tokens](#linking-multiple-tokens)
- [Cleanup Registration with onCancel()](#cleanup-registration-with-oncancel)
- [Monitoring Tracked Promises](#monitoring-tracked-promises)

### Advanced
- [cancel() vs cancelChain()](#cancel-vs-cancelchain)
- [Integration with await()](#integration-with-await)
- [Resource Cleanup on Scope Exit](#resource-cleanup-on-scope-exit)

### Reference
- [API Reference](#api-reference)

### Meta
- [Development](#development)
- [Credits](#credits)
- [License](#license)

---

## Installation
```bash
composer require hiblaphp/cancellation
```

**Requirements:**
- PHP 8.3+
- `hiblaphp/promise`
- `hiblaphp/event-loop`

---

## Introduction

`hiblaphp/promise` gives every promise its own built-in cancellation —
`cancel()`, `cancelChain()`, and `onCancel()` are first-class features of
every promise instance. That system is self-contained: cleanup is registered
at the point where the resource is created, and cancellation propagates
through the chain automatically.

That model works perfectly when you own a specific promise chain and want
to cancel it directly. But async applications often need to cancel work from
the outside — a user clicks an abort button, a request times out, or a
background job is told to stop. In these cases the cancellation decision
happens at a different layer than the work itself, and the work may span
multiple unrelated promise chains that have no shared ancestor to cancel.

`hiblaphp/cancellation` solves this with the `CancellationToken` pattern,
inspired by .NET's `CancellationToken` and `CancellationTokenSource`. The
idea is a producer/consumer split:

- The **`CancellationTokenSource`** owns the ability to cancel. You hold it
  at the control point — the button handler, the timeout, the shutdown hook.
- The **`CancellationToken`** is a read-only view of the cancellation signal.
  You pass it into operations. Operations never receive the source, only the
  token.

When you cancel the source, every operation holding that token is cancelled
together, triggering their individual `onCancel()` handlers and freeing their
resources — regardless of whether those operations share a promise chain.

This library is used throughout the Hibla ecosystem. `hiblaphp/stream`'s
`readAsync()`, `writeAsync()`, and `pipeAsync()` all accept a token. The
`await()` function in `hiblaphp/async` accepts a token as its second
argument. Any Hibla component that performs async I/O is designed to receive
a token and stop cleanly when it fires.

---

## How it Relates to Promise Cancellation

`hiblaphp/promise` has built-in cancellation on every promise — it is
self-contained and does not require this library. `hiblaphp/cancellation`
sits on top of that system and adds external coordination.
```
Built-in promise cancellation:
  $promise->cancel()
    → $promise->onCancel() handlers fire
    → child promises cancelled via forward propagation
    → resources freed

CancellationToken coordination:
  $cts->cancel()
    → token->onCancel() callbacks fire
    → token->track($promise) → $promise->cancel() fires
    → $promise->onCancel() handlers fire
    → resources freed
```

Use promise cancellation to encapsulate cleanup inside the function that
creates a resource. Use `CancellationToken` when you need one external signal
to coordinate cancellation across multiple unrelated operations — user-initiated
abort, timeout coordination, or group cancellation.

The two systems compose naturally — a token cancels promises, and those
promises clean up their own resources via their own `onCancel()` handlers.
You never have to reach inside a promise chain from the outside.

---

## Basic Usage

The token is accessed via the `readonly` public property `$token` on the
source — there is no getter method:
```php
use Hibla\Cancellation\CancellationTokenSource;

$cts   = new CancellationTokenSource();
$token = $cts->token; // readonly public property

// Pass the token into operations
$promise1 = fetchUser(1, $token);
$promise2 = fetchOrders(1, $token);

// Cancel everything from a single control point
$cts->cancel();
```

Calling `cancel()` multiple times on the same source is safe and idempotent
— subsequent calls have no effect:
```php
$cts->cancel(); // cancels
$cts->cancel(); // no-op — already cancelled
$cts->cancel(); // no-op
```

---

## Accepting a Token in Your Functions

Functions that want to support external cancellation accept a
`CancellationToken` and use it to either check for cancellation at safe
checkpoints or to track the promises they create.

### Polling with `isCancelled()`

Use `isCancelled()` for non-throwing checks inside loops or before starting
expensive work:
```php
use Hibla\Cancellation\CancellationToken;

function processItems(array $items, CancellationToken $token): void
{
    foreach ($items as $item) {
        if ($token->isCancelled()) {
            return; // stop gracefully
        }

        processItem($item);
    }
}
```

### Throwing with `throwIfCancelled()`

`throwIfCancelled()` is the preferred pattern for long-running work because
it surfaces cancellation as a `CancelledException` that unwinds naturally
through `try/finally` blocks, ensuring cleanup always runs:
```php
function processItems(array $items, CancellationToken $token): void
{
    $resource = openResource();

    try {
        foreach ($items as $item) {
            $token->throwIfCancelled(); // throws CancelledException if cancelled

            processItem($item);
        }
    } finally {
        $resource->close(); // always runs — even when cancelled
    }
}
```

### Tracking promises with `track()`

Use `track()` to register a promise for automatic cancellation when the
token is cancelled. The promise is automatically untracked when it settles —
fulfilled, rejected, or cancelled — so no manual cleanup is ever needed.
```php
use Hibla\Cancellation\CancellationToken;
use Hibla\Promise\Interfaces\PromiseInterface;

function fetchUser(int $id, CancellationToken $token): PromiseInterface
{
    $promise = Http::get("/users/$id");

    // When $token is cancelled, $promise is automatically cancelled.
    // This triggers $promise's own onCancel() handlers — the HTTP
    // request is aborted via whatever cleanup was registered there.
    $token->track($promise);

    return $promise;
}
```

Tracking an already-settled or already-cancelled promise is a safe no-op —
`track()` checks the promise state and returns it immediately if it has
already completed. Promises are untracked automatically on settlement via
`finally()` internally, which covers all three outcomes — you never need to
call `untrack()` after a promise completes.
```php
$promise = $token->track(fetchUser(1));

$token->getTrackedCount(); // 1

// After the promise fulfills, rejects, or is cancelled:
$token->getTrackedCount(); // 0 — automatically untracked
```

Manual `untrack()` is only needed when you want to detach a still-pending
promise from the token before it settles — for example, promoting an
operation to run independently after a user cancels everything else.

### Default parameter with `CancellationToken::none()`

`CancellationToken::none()` returns a shared singleton token that can never
be cancelled. All token methods work correctly on it without any null checks
or guards — `isCancelled()` always returns false, `throwIfCancelled()` never
throws, `track()` is a safe no-op, and `onCancel()` returns a pre-disposed
registration without storing the callback.
```php
use Hibla\Cancellation\CancellationToken;

function fetchUser(
    int $id,
    CancellationToken $token = null
): PromiseInterface {
    $token ??= CancellationToken::none();

    $promise = Http::get("/users/$id");
    $token->track($promise);     // safe — no-op on none(), nothing stored
    $token->isCancelled();       // safe — always false
    $token->throwIfCancelled();  // safe — never throws

    return $promise;
}

// Works with or without a token
$user = fetchUser(1)->wait();
$user = fetchUser(1, $cts->token)->wait();
```

Calling `onCancel()` on `none()` returns a pre-disposed registration without
storing the callback — the callback will never fire and nothing is retained
against the singleton:
```php
$token = CancellationToken::none();

$registration = $token->onCancel(fn() => cleanup());
// callback is NOT stored — no memory retained
// registration is pre-disposed

$registration->isDisposed(); // true
$registration->dispose();    // no-op, returns false
```

---

## Cancellation is Synchronous

Like `Promise::cancel()`, cancellation through a `CancellationTokenSource`
is **synchronous**. When you call `$cts->cancel()`, all registered
`onCancel()` callbacks and all tracked promise cancellations run immediately
and in order before `cancel()` returns. This eliminates race conditions where
a promise could be resolved in the same tick that cancellation was requested.
```php
$cts = new CancellationTokenSource();

$cts->token->onCancel(function () {
    echo "A\n"; // runs first
});

$cts->token->onCancel(function () {
    echo "B\n"; // runs second
});

$cts->cancel();
echo "C\n"; // runs third — after both handlers have already run
```

Because cancellation is synchronous, callbacks registered via `onCancel()`
on the token must be **fast**. They run directly on the call stack of
`cancel()` — a slow or blocking handler stalls that call stack. Keep
handlers to simple cleanup: cancelling a timer, removing a watcher, or
closing a handle. For async cleanup, fire and return immediately rather
than awaiting:
```php
$cts->token->onCancel(function () use ($requestId) {
    // Correct: fire and return
    Loop::addCurlRequest(
        "https://api.example.com/cancel/$requestId",
        [],
        fn() => null
    );

    // Wrong: do not await inside an onCancel handler
    // Http::delete("https://api.example.com/cancel/$requestId")->wait();
});
```

### Exceptions during cancellation

If any `onCancel()` callback or promise cancellation throws during `cancel()`,
the library does not abort mid-loop. All remaining callbacks and promises are
still processed. Exceptions are collected and at the end either a single
exception is rethrown or — if multiple callbacks threw — an
`AggregateErrorException` is thrown containing all of them.

This means your cleanup callbacks can throw without preventing other cleanup
from running:
```php
$cts->token->onCancel(fn() => throw new \RuntimeException('A failed'));
$cts->token->onCancel(fn() => releaseOtherResource()); // still runs

try {
    $cts->cancel();
} catch (\Hibla\Promise\Exceptions\AggregateErrorException $e) {
    foreach ($e->getErrors() as $error) {
        logger()->error($error->getMessage());
    }
} catch (\Throwable $e) {
    // single exception if only one callback threw
}
```

---

## Automatic Timeout

Pass a timeout in seconds to the constructor and the source will
automatically cancel after that duration. The timeout timer uses
`WeakReference` internally — if the source goes out of scope before the
timer fires, the timer cancels cleanly without error and does not keep
the source alive.
```php
// Cancels after 5 seconds
$cts = new CancellationTokenSource(5.0);

$promise = longRunningOperation($cts->token);

$promise->wait(); // throws CancelledException if 5 seconds elapse
```

You can set or reset the timeout dynamically after construction by calling
`cancelAfter()`. Each call cancels the previous timer and starts a new one
— the constructor timeout is not fixed:
```php
$cts = new CancellationTokenSource(5.0); // starts with 5 second timeout
$cts->cancelAfter(10.0);                 // reset — now cancels in 10 seconds
$cts->cancelAfter(2.0);                  // reset again — now cancels in 2 seconds
```

---

## Linking Multiple Tokens

`createLinkedTokenSource()` creates a new source that cancels automatically
when any of the provided tokens cancel. This is the standard way to combine
multiple cancellation signals — user abort, timeout, system shutdown — into
a single token you pass into an operation.
```php
use Hibla\Cancellation\CancellationTokenSource;

$userCts    = new CancellationTokenSource();          // user clicks cancel
$timeoutCts = new CancellationTokenSource(10.0);      // 10 second timeout

// Operation cancels if user cancels OR timeout expires
$linkedCts = CancellationTokenSource::createLinkedTokenSource(
    $userCts->token,
    $timeoutCts->token
);

$result = fetchData($linkedCts->token)->wait();
```

If any of the input tokens are already cancelled at the time
`createLinkedTokenSource()` is called, the linked source is cancelled
immediately before being returned.

The linked source uses `WeakReference` internally — parent token callbacks
do not keep the linked source alive after it goes out of scope. If the linked
source is garbage collected before any parent token fires, the link is severed
cleanly. Once one parent token fires and cancels the linked source, subsequent
parent token firings call `cancel()` on the already-cancelled source — which
is a safe no-op.

### Full example — user cancellation, timeout, and `await()`
```php
use Hibla\Cancellation\CancellationTokenSource;
use function Hibla\async;
use function Hibla\await;

$userCts    = new CancellationTokenSource();     // cancelled when user clicks abort
$timeoutCts = new CancellationTokenSource(30.0); // hard 30 second ceiling

$linkedCts = CancellationTokenSource::createLinkedTokenSource(
    $userCts->token,
    $timeoutCts->token
);

$workflow = async(function () use ($linkedCts) {
    try {
        $user   = await(fetchUser(1), $linkedCts->token);
        $orders = await(fetchOrders($user->id), $linkedCts->token);
        $report = await(generateReport($user, $orders), $linkedCts->token);

        return $report;
    } catch (\Hibla\Promise\Exceptions\CancelledException $e) {
        echo "Workflow cancelled — either user aborted or 30s timeout hit\n";
        return null;
    }
});

// Wire user abort to the source
$abortButton->onClick(fn() => $userCts->cancel());

$result = await($workflow);
```

---

## Cleanup Registration with `onCancel()`

`onCancel()` on the token itself is for registering cleanup that is not tied
to a specific promise — for example logging, releasing a lock, or updating
state when any operation in a group is cancelled.

It returns a `CancellationTokenRegistration` that lets you unregister the
callback if the operation completes before cancellation occurs. `dispose()`
returns `true` if the callback was successfully removed, `false` if it was
already disposed or had already fired:
```php
$cts   = new CancellationTokenSource();
$token = $cts->token;

$registration = $token->onCancel(function () use ($tempFile) {
    $tempFile->delete();
});

try {
    $result = doWork($token)->wait();

    // Success — temp file is kept, unregister the cleanup
    $disposed = $registration->dispose(); // true if removed, false if already fired

    return $result;
} catch (\Throwable $e) {
    // Failed — let the cleanup registration remain in case of later cancellation
    throw $e;
}
```

> **Important:** If you let a `CancellationTokenRegistration` go out of scope
> without calling `dispose()`, the callback remains registered and will still
> fire when the token is cancelled. The registration's `__destruct()` only
> nullifies the internal state reference for garbage collection — it does NOT
> unregister the callback. Always call `dispose()` explicitly if you want to
> prevent the callback from firing.

If the token is already cancelled when `onCancel()` is called, the callback
fires immediately and synchronously, and a pre-disposed registration is
returned.

---

## Monitoring Tracked Promises

The token provides methods for inspecting and managing its tracked promises.
These are primarily useful for monitoring, diagnostics, and advanced lifecycle
management:
```php
// How many promises are currently being tracked
$count = $token->getTrackedCount();

// Stop tracking a specific promise without cancelling it
// Useful when an operation completes and you want to detach it
// from the token without affecting its result
$token->untrack($promise);

// Remove all tracked promises without cancelling any of them
// Useful when you want to stop managing a batch of operations
// but let them complete naturally
$token->clearTracked();
```

Note that `clearTracked()` and `untrack()` do not cancel the promises they
remove — the promises continue running and will resolve or reject normally.
If you need to cancel them, call `cancel()` or `cancelChain()` on the source
before clearing.

---

## `cancel()` vs `cancelChain()`

The source provides two cancellation methods that mirror the same distinction
on `Promise` itself.

**`cancel()`** calls `Promise::cancel()` on all tracked promises — forward
propagation only. Cancels the tracked promise and all its children. Does not
walk up to the root. Use this when you track root promises directly:
```php
$root  = Http::get('/api/users');  // onCancel() -> abort HTTP
$child = $root->then(fn($r) => json_decode($r->getBody()));

// Track the root — cancel() is sufficient
$cts->token->track($root);
$cts->cancel();
// $root->onCancel() fires -> HTTP aborted
// $child cancelled via forward propagation
```

**`cancelChain()`** calls `Promise::cancelChain()` on all tracked promises —
walks up to the root before cancelling downward. Use this when you only hold
child promise references but need root-level `onCancel()` handlers to fire
for proper resource cleanup:
```php
$root  = Http::get('/api/users');  // onCancel() -> abort HTTP
$child = $root->then(fn($r) => json_decode($r->getBody()));

// Only hold child reference — cancelChain() walks up to find root
$cts->token->track($child);
$cts->cancelChain();
// walks up to $root
// $root->onCancel() fires -> HTTP aborted
// $child cancelled via forward propagation
```

> **Important:** Only use `cancelChain()` when you own the full promise chain
> and no external code holds references to ancestor promises. It walks up to
> the root and cancels everything from there. If the root promise is shared
> with other callers, `cancelChain()` will cancel their operations too — this
> is almost certainly a bug. When in doubt, track root promises directly and
> use `cancel()`.

---

## Integration with `await()`

`await()` from `hiblaphp/async` accepts an optional `CancellationToken` as
its second argument. When provided, it automatically calls
`token->track($promise)` — you do not need to call `track()` manually at
the `await()` call site:
```php
use function Hibla\await;

async(function () use ($token) {
    // Token automatically tracks the promise — no manual track() needed
    $user   = await(fetchUser(1), $token);
    $orders = await(fetchOrders($user->id), $token);

    return compact('user', 'orders');
});
```

If you are not using `hiblaphp/async`, call `track()` on the token directly
before awaiting the promise:
```php
// Without hiblaphp/async — use track() manually
$promise = fetchUser(1);
$token->track($promise);
$user = $promise->wait();
```

---

## Resource Cleanup on Scope Exit

`CancellationTokenSource` implements `__destruct()` which cancels the
timeout timer and clears all callbacks and tracked promises when the source
goes out of scope.

> **Important:** `__destruct()` does NOT call `cancel()` — it only clears
> the internal state. Both tracked promises and registered `onCancel()`
> callbacks are silently dropped without firing. Promises continue running
> and will resolve or reject normally. If you need promises to be cancelled
> and `onCancel()` handlers to fire when the source goes out of scope, call
> `cancel()` explicitly in a `finally` block:
```php
function doWork(): mixed
{
    $cts = new CancellationTokenSource(30.0);

    $cts->token->onCancel(function () {
        echo "cancelled\n"; // will NOT fire unless cancel() is called explicitly
    });

    try {
        return longOperation($cts->token)->wait();
    } finally {
        // Explicitly cancel to ensure tracked promises are cancelled
        // and onCancel() handlers fire before $cts is destroyed.
        // Without this, both are silently dropped on scope exit.
        if (! $cts->token->isCancelled()) {
            $cts->cancel();
        }
    }
}
```

The timeout timer is the exception — it is always cancelled automatically
on scope exit regardless of whether `cancel()` is called explicitly.
```
On scope exit ($cts goes out of scope):

  Timeout timer        → always cancelled cleanly ✓
  onCancel() callbacks → silently dropped, NOT fired ✗ unless cancel() called
  tracked promises     → silently untracked, NOT cancelled ✗ unless cancel() called
```

---

## API Reference

### `CancellationTokenSource`

| Method | Description |
|---|---|
| `__construct(?float $timeoutSeconds)` | Create a source. Pass a timeout in seconds for automatic cancellation. |
| `cancel()` | Cancel synchronously. Calls `Promise::cancel()` (forward-only) on all tracked promises. Idempotent. Collects and throws `AggregateErrorException` if multiple callbacks throw. |
| `cancelChain()` | Cancel synchronously. Calls `Promise::cancelChain()` (walks to root) on all tracked promises. Only use when you own the full chain. |
| `cancelAfter(float $seconds)` | Set or reset the automatic cancellation timer. Resets on each call. |
| `createLinkedTokenSource(CancellationToken ...$tokens)` | Static. Returns a new source that cancels when any input token cancels. |
| `$token` | Readonly public property. The `CancellationToken` associated with this source. |

### `CancellationToken`

| Method | Description |
|---|---|
| `isCancelled(): bool` | Non-throwing check. True if the source has been cancelled. |
| `throwIfCancelled(): void` | Throws `CancelledException` if cancelled. Preferred for long-running work — unwinds through finally blocks. |
| `onCancel(callable $callback): CancellationTokenRegistration` | Register a synchronous cleanup callback. Must be fast — no blocking or awaiting. Returns a registration for unregistering. If already cancelled, fires immediately and returns a pre-disposed registration. No-op on `none()` — returns a pre-disposed registration without storing the callback. |
| `track(PromiseInterface $promise): PromiseInterface` | Register a promise for automatic cancellation. Auto-untracked when promise settles (fulfilled, rejected, or cancelled). Safe no-op on already-settled, already-cancelled promises, and on `none()`. Returns the same promise. |
| `untrack(PromiseInterface $promise): void` | Stop tracking a promise without cancelling it. |
| `getTrackedCount(): int` | Returns the number of currently tracked promises. |
| `clearTracked(): void` | Remove all tracked promises without cancelling them. |
| `CancellationToken::none()` | Static singleton. A token that can never be cancelled. All methods are safe to call — `onCancel()` and `track()` are no-ops that store nothing against the singleton. |

### `CancellationTokenRegistration`

| Method | Description |
|---|---|
| `dispose(): bool` | Unregister the callback. Returns `true` if removed, `false` if already disposed or already fired. Safe to call multiple times. Does NOT fire automatically on garbage collection — call explicitly to prevent the callback from firing. |
| `isDisposed(): bool` | True if `dispose()` was called. |

---

## Development

### Running Tests
```bash
git clone https://github.com/hiblaphp/cancellation.git
cd cancellation
composer install
```
```bash
./vendor/bin/pest
```
```bash
./vendor/bin/phpstan analyse
```

---

## Credits

- **API Design:** Inspired by .NET's `CancellationToken` and
  `CancellationTokenSource` pattern.
- **Promise Integration:** Built on
  [hiblaphp/promise](https://github.com/hiblaphp/promise).
- **Event Loop:** Powered by
  [hiblaphp/event-loop](https://github.com/hiblaphp/event-loop).

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.