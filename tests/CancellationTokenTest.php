<?php

declare(strict_types=1);

use Hibla\Cancellation\CancellationToken;
use Hibla\Cancellation\CancellationTokenSource;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\PromiseCancelledException;
use Hibla\Promise\Promise;

describe('CancellationToken', function () {
    describe('Basic State Management', function () {
        it('starts in uncancelled state', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            expect($token->isCancelled())->toBeFalse();
        });

        it('transitions to cancelled state when source is cancelled', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $cts->cancel();

            expect($token->isCancelled())->toBeTrue();
        });

        it('remains cancelled after multiple cancel calls', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $cts->cancel();
            $cts->cancel();
            $cts->cancel();

            expect($token->isCancelled())->toBeTrue();
        });
    });

    describe('CancellationToken::none()', function () {
        it('returns a token that is never cancelled', function () {
            $token = CancellationToken::none();

            expect($token->isCancelled())->toBeFalse();
        });

        it('returns the same instance on multiple calls', function () {
            $token1 = CancellationToken::none();
            $token2 = CancellationToken::none();

            expect($token1)->toBe($token2);
        });

        it('can be used as default parameter', function () {
            $token = null;
            $token ??= CancellationToken::none();

            expect($token)->toBeInstanceOf(CancellationToken::class);
            expect($token->isCancelled())->toBeFalse();
        });
    });

    describe('Promise Tracking', function () {
        it('tracks a pending promise', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $promise = new Promise(function () {
                // Never settles
            });

            $token->track($promise);

            expect($token->getTrackedCount())->toBe(1);
        });

        it('tracks multiple promises', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $promise1 = new Promise(function () {});
            $promise2 = new Promise(function () {});
            $promise3 = new Promise(function () {});

            $token->track($promise1);
            $token->track($promise2);
            $token->track($promise3);

            expect($token->getTrackedCount())->toBe(3);
        });

        it('does not track already settled promise', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $promise = Promise::resolved('value');

            $token->track($promise);

            expect($token->getTrackedCount())->toBe(0);
        });

        it('cancels tracked promise when token is cancelled', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $promise = new Promise(function () {});

            $token->track($promise);

            expect($promise->isCancelled())->toBeFalse();

            $cts->cancel();

            expect($promise->isCancelled())->toBeTrue();
        });

        it('cancels all tracked promises', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $promise1 = new Promise(function () {});
            $promise2 = new Promise(function () {});
            $promise3 = new Promise(function () {});

            $token->track($promise1);
            $token->track($promise2);
            $token->track($promise3);

            $cts->cancel();

            expect($promise1->isCancelled())->toBeTrue()
                ->and($promise2->isCancelled())->toBeTrue()
                ->and($promise3->isCancelled())->toBeTrue()
            ;
        });

        it('immediately cancels promise if token already cancelled', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $cts->cancel();

            $promise = new Promise(function () {});
            $token->track($promise);

            expect($promise->isCancelled())->toBeTrue();
        });

        it('automatically untracks promise when it resolves', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $promise = new Promise(function ($resolve) {
                Loop::defer(function () use ($resolve) {
                    $resolve('value');
                });
            });

            $token->track($promise);

            expect($token->getTrackedCount())->toBe(1);

            $promise->wait();

            expect($token->getTrackedCount())->toBe(0);
        });

        it('automatically untracks promise when it rejects', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $promise = new Promise(function ($resolve, $reject) {
                Loop::nextTick(function () use ($reject) {
                    $reject(new RuntimeException('error'));
                });
            });

            $promise = $promise->catch(function () {});

            $token->track($promise);

            expect($token->getTrackedCount())->toBe(1);

            try {
                $promise->wait();
            } catch (RuntimeException $e) {
                // Expected
            }

            expect($token->getTrackedCount())->toBe(0);
        });

        it('manually untracks a specific promise', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $promise1 = new Promise(function () {});
            $promise2 = new Promise(function () {});

            $token->track($promise1);
            $token->track($promise2);

            expect($token->getTrackedCount())->toBe(2);

            $token->untrack($promise1);

            expect($token->getTrackedCount())->toBe(1);

            $cts->cancel();

            expect($promise1->isCancelled())->toBeFalse();
            expect($promise2->isCancelled())->toBeTrue();
        });

        it('clears all tracked promises without cancelling them', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $promise1 = new Promise(function () {});
            $promise2 = new Promise(function () {});
            $promise3 = new Promise(function () {});

            $token->track($promise1);
            $token->track($promise2);
            $token->track($promise3);

            expect($token->getTrackedCount())->toBe(3);

            $token->clearTracked();

            expect($token->getTrackedCount())->toBe(0);

            $cts->cancel();

            expect($promise1->isCancelled())->toBeFalse()
                ->and($promise2->isCancelled())->toBeFalse()
                ->and($promise3->isCancelled())->toBeFalse()
            ;
        });

        it('tracks the same promise multiple times', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $promise = new Promise(function () {});

            $token->track($promise);
            $token->track($promise);
            $token->track($promise);

            expect($token->getTrackedCount())->toBe(3);

            $cts->cancel();

            expect($promise->isCancelled())->toBeTrue();
        });

        it('does not track settled promises', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $resolved = Promise::resolved('value');
            $rejected = Promise::rejected(new RuntimeException('error'));

            $token->track($resolved);
            $token->track($rejected);

            expect($rejected->getReason())->toBeInstanceOf(RuntimeException::class);
            expect($token->getTrackedCount())->toBe(0);
        });
    });

    describe('Cancellation Callbacks', function () {
        it('executes callback when cancelled', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $called = false;

            $token->onCancel(function () use (&$called) {
                $called = true;
            });

            expect($called)->toBeFalse();

            $cts->cancel();

            expect($called)->toBeTrue();
        });

        it('executes multiple callbacks in registration order', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $order = [];

            $token->onCancel(function () use (&$order) {
                $order[] = 1;
            });

            $token->onCancel(function () use (&$order) {
                $order[] = 2;
            });

            $token->onCancel(function () use (&$order) {
                $order[] = 3;
            });

            $cts->cancel();

            expect($order)->toBe([1, 2, 3]);
        });

        it('immediately executes callback if already cancelled', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $cts->cancel();

            $called = false;

            $token->onCancel(function () use (&$called) {
                $called = true;
            });

            expect($called)->toBeTrue();
        });

        it('does not execute callback multiple times on repeated cancels', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $count = 0;

            $token->onCancel(function () use (&$count) {
                $count++;
            });

            $cts->cancel();
            $cts->cancel();
            $cts->cancel();

            expect($count)->toBe(1);
        });

        it('allows callbacks to access token state', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $tokenWasCancelled = null;

            $token->onCancel(function () use ($token, &$tokenWasCancelled) {
                $tokenWasCancelled = $token->isCancelled();
            });

            $cts->cancel();

            expect($tokenWasCancelled)->toBeTrue();
        });

        it('returns a registration that can be disposed', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $called = false;

            $registration = $token->onCancel(function () use (&$called) {
                $called = true;
            });

            $result = $registration->dispose();

            expect($result)->toBeTrue();
            expect($registration->isDisposed())->toBeTrue();

            $cts->cancel();

            expect($called)->toBeFalse();
        });

        it('disposes registration automatically after callback execution', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $registration = $token->onCancel(function () {});

            $cts->cancel();

            $result = $registration->dispose();

            expect($result)->toBeFalse();
        });
    });

    describe('throwIfCancelled()', function () {
        it('throws exception when cancelled', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $cts->cancel();

            expect(fn () => $token->throwIfCancelled())
                ->toThrow(PromiseCancelledException::class, 'Operation was cancelled')
            ;
        });

        it('does not throw when not cancelled', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $token->throwIfCancelled();

            expect(true)->toBeTrue(); // No exception thrown
        });

        it('can be called multiple times', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $token->throwIfCancelled();
            $token->throwIfCancelled();
            $token->throwIfCancelled();

            $cts->cancel();

            expect(fn () => $token->throwIfCancelled())
                ->toThrow(PromiseCancelledException::class)
            ;
        });
    });

    describe('Promise Chain Cancellation', function () {
        it('cancels child promises in chain', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $promise = new Promise(function () {});
            $child = $promise->then(function ($value) {
                return $value * 2;
            });

            $token->track($promise);

            $cts->cancel();

            expect($promise->isCancelled())->toBeTrue();
            expect($child->isCancelled())->toBeTrue();
        });

        it('cancels multiple levels of promise chains', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $promise = new Promise(function () {});
            $child1 = $promise->then(fn ($v) => $v);
            $child2 = $child1->then(fn ($v) => $v);
            $child3 = $child2->then(fn ($v) => $v);

            $token->track($promise);

            $cts->cancel();

            expect($promise->isCancelled())->toBeTrue()
                ->and($child1->isCancelled())->toBeTrue()
                ->and($child2->isCancelled())->toBeTrue()
                ->and($child3->isCancelled())->toBeTrue()
            ;
        });

        it('does not affect already settled chain members', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $promise = Promise::resolved(10);
            $child = $promise->then(function ($value) {
                return $value * 2;
            });

            $result = $child->wait();

            $token->track($promise);
            $cts->cancel();

            expect($promise->isFulfilled())->toBeTrue()
                ->and($child->isFulfilled())->toBeTrue()
                ->and($result)->toBe(20)
            ;
        });
    });

    describe('Edge Cases and Error Handling', function () {
        it('handles tracking null promise gracefully', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            expect(fn () => $token->untrack(new Promise(fn () => null)))
                ->not->toThrow(Throwable::class)
            ;
        });

        it('handles untracking non-tracked promise', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $promise = new Promise(function () {});

            expect(fn () => $token->untrack($promise))
                ->not->toThrow(Throwable::class)
            ;
        });

        it('handles clearing empty tracked list', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            expect(fn () => $token->clearTracked())
                ->not->toThrow(Throwable::class)
            ;
        });

        it('maintains tracked count accuracy with mixed operations', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $p1 = new Promise(function () {});
            $p2 = new Promise(function () {});
            $p3 = new Promise(function () {});

            $token->track($p1);
            expect($token->getTrackedCount())->toBe(1);

            $token->track($p2);
            expect($token->getTrackedCount())->toBe(2);

            $token->untrack($p1);
            expect($token->getTrackedCount())->toBe(1);

            $token->track($p3);
            expect($token->getTrackedCount())->toBe(2);

            $token->clearTracked();
            expect($token->getTrackedCount())->toBe(0);
        });
    });

    describe('Integration with Promise Static Methods', function () {
        it('works with Promise::race()', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $slow = new Promise(function ($resolve) use ($token) {
                $token->onCancel(function () use ($resolve) {
                    // Don't resolve
                });
            });

            $fast = Promise::resolved('fast');

            $token->track($slow);

            $race = Promise::race([$slow, $fast]);
            $result = $race->wait();

            expect($result)->toBe('fast');
        });

        it('works with Promise::all()', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $p1 = Promise::resolved(1);
            $p2 = Promise::resolved(2);
            $p3 = new Promise(function () {
                // Never settles
            });

            $token->track($p3);

            $all = Promise::all([$p1, $p2, $p3]);

            $token->track($all);

            $cts->cancel();

            expect($p3->isCancelled())->toBeTrue()
                ->and($all->isCancelled())->toBeTrue()
            ;
        });

        it('works with Promise::allSettled()', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $p1 = Promise::resolved(1);
            $p2 = Promise::rejected(new RuntimeException('error'));
            $p3 = new Promise(function () {});

            $token->track($p3);

            Promise::allSettled([$p1, $p2, $p3]);

            $cts->cancel();

            expect($p3->isCancelled())->toBeTrue();
        });
    });
});
