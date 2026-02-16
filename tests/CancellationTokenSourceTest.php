<?php

declare(strict_types=1);

use Hibla\Cancellation\CancellationToken;
use Hibla\Cancellation\CancellationTokenSource;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\AggregateErrorException;
use Hibla\Promise\Promise;

describe('CancellationTokenSource', function () {
    describe('Constructor', function () {
        it('creates a source without timeout', function () {
            $cts = new CancellationTokenSource();

            expect($cts->token)->toBeInstanceOf(CancellationToken::class);
            expect($cts->token->isCancelled())->toBeFalse();
        });

        it('creates a source with timeout', function () {
            $cts = new CancellationTokenSource(0.05);

            expect($cts->token->isCancelled())->toBeFalse();

            Loop::run();

            expect($cts->token->isCancelled())->toBeTrue();
        });
    });

    describe('token()', function () {
        it('returns the same token instance', function () {
            $cts = new CancellationTokenSource();

            $token1 = $cts->token;
            $token2 = $cts->token;

            expect($token1)->toBe($token2);
        });
    });

    describe('cancel()', function () {
        it('cancels the token', function () {
            $cts = new CancellationTokenSource();

            expect($cts->token->isCancelled())->toBeFalse();

            $cts->cancel();

            expect($cts->token->isCancelled())->toBeTrue();
        });

        it('is idempotent', function () {
            $cts = new CancellationTokenSource();

            $cts->cancel();
            $cts->cancel();
            $cts->cancel();

            expect($cts->token->isCancelled())->toBeTrue();
        });

        it('executes all registered callbacks', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $state = ['order' => []];

            $token->onCancel(function () use (&$state) {
                $state['order'][] = 1;
            });

            $token->onCancel(function () use (&$state) {
                $state['order'][] = 2;
            });

            $token->onCancel(function () use (&$state) {
                $state['order'][] = 3;
            });

            $cts->cancel();

            expect($state['order'])->toBe([1, 2, 3]);
        });

        it('cancels all tracked promises', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $promise1 = new Promise(function () {});
            $promise2 = new Promise(function () {});

            $token->track($promise1);
            $token->track($promise2);

            $cts->cancel();

            expect($promise1->isCancelled())->toBeTrue();
            expect($promise2->isCancelled())->toBeTrue();
        });

        it('only cancels tracked promise forward — does not walk up to root', function () {
            $cts = new CancellationTokenSource();

            $root  = new Promise(function () {});
            $child = $root->then(fn ($v) => $v);

            $cts->token->track($child);
            $cts->cancel();

            expect($child->isCancelled())->toBeTrue();
            expect($root->isCancelled())->toBeFalse(); // root untouched ← key assertion
        });

        it('throws single exception from callback', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $token->onCancel(function () {
                throw new RuntimeException('Callback error');
            });

            expect(fn () => $cts->cancel())
                ->toThrow(RuntimeException::class, 'Callback error')
            ;
        });

        it('throws AggregateErrorException for multiple errors', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $token->onCancel(function () {
                throw new RuntimeException('Error 1');
            });

            $token->onCancel(function () {
                throw new RuntimeException('Error 2');
            });

            expect(fn () => $cts->cancel())
                ->toThrow(AggregateErrorException::class)
            ;
        });

        it('continues executing callbacks even if some throw', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $state = ['executed' => []];

            $token->onCancel(function () use (&$state) {
                $state['executed'][] = 1;

                throw new RuntimeException('Error 1');
            });

            $token->onCancel(function () use (&$state) {
                $state['executed'][] = 2;
            });

            $token->onCancel(function () use (&$state) {
                $state['executed'][] = 3;

                throw new RuntimeException('Error 2');
            });

            try {
                $cts->cancel();
            } catch (AggregateErrorException $e) {
                // Expected
            }

            expect($state['executed'])->toBe([1, 2, 3]);
        });
    });

    describe('cancelChain()', function () {
        it('cancels the token', function () {
            $cts = new CancellationTokenSource();

            expect($cts->token->isCancelled())->toBeFalse();

            $cts->cancelChain();

            expect($cts->token->isCancelled())->toBeTrue();
        });

        it('is idempotent', function () {
            $cts = new CancellationTokenSource();

            $cts->cancelChain();
            $cts->cancelChain();
            $cts->cancelChain();

            expect($cts->token->isCancelled())->toBeTrue();
        });

        it('executes all registered callbacks', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;
            $state = ['order' => []];

            $token->onCancel(function () use (&$state) {
                $state['order'][] = 1;
            });

            $token->onCancel(function () use (&$state) {
                $state['order'][] = 2;
            });

            $token->onCancel(function () use (&$state) {
                $state['order'][] = 3;
            });

            $cts->cancelChain();

            expect($state['order'])->toBe([1, 2, 3]);
        });

        it('walks up to root and cancels the entire chain', function () {
            $cts = new CancellationTokenSource();

            $root  = new Promise(function () {});
            $child = $root->then(fn ($v) => $v);

            $cts->token->track($child);
            $cts->cancelChain();
 
            expect($child->isCancelled())->toBeTrue();
            expect($root->isCancelled())->toBeTrue(); 
        });

        it('walks up through deep chain to root', function () {
            $cts = new CancellationTokenSource();

            $root        = new Promise(function () {});
            $child       = $root->then(fn ($v) => $v);
            $grandchild  = $child->then(fn ($v) => $v);

            $cts->token->track($grandchild);
            $cts->cancelChain();

            expect($grandchild->isCancelled())->toBeTrue();
            expect($child->isCancelled())->toBeTrue();
            expect($root->isCancelled())->toBeTrue();
        });

        it('fires root onCancel() handler via chain propagation', function () {
            $cts = new CancellationTokenSource();
            $state = ['rootCleanedUp' => false];

            $root = new Promise(function () {});
            $root->onCancel(function () use (&$state) {
                $state['rootCleanedUp'] = true; 
            });

            $child = $root->then(fn ($v) => $v);

            $cts->token->track($child);
            $cts->cancelChain();

            expect($state['rootCleanedUp'])->toBeTrue(); 
        });

        it('cancels multiple tracked promises via chain', function () {
            $cts = new CancellationTokenSource();

            $root1  = new Promise(function () {});
            $child1 = $root1->then(fn ($v) => $v);

            $root2  = new Promise(function () {});
            $child2 = $root2->then(fn ($v) => $v);

            $cts->token->track($child1);
            $cts->token->track($child2);

            $cts->cancelChain();

            expect($child1->isCancelled())->toBeTrue();
            expect($root1->isCancelled())->toBeTrue();
            expect($child2->isCancelled())->toBeTrue();
            expect($root2->isCancelled())->toBeTrue();
        });

        it('does not affect untracked promises', function () {
            $cts = new CancellationTokenSource();

            $tracked   = new Promise(function () {});
            $untracked = new Promise(function () {});

            $cts->token->track($tracked);
            $cts->cancelChain();

            expect($tracked->isCancelled())->toBeTrue();
            expect($untracked->isCancelled())->toBeFalse(); 
        });

        it('throws single exception from callback', function () {
            $cts = new CancellationTokenSource();

            $cts->token->onCancel(function () {
                throw new RuntimeException('Callback error');
            });

            expect(fn () => $cts->cancelChain())
                ->toThrow(RuntimeException::class, 'Callback error')
            ;
        });

        it('throws AggregateErrorException for multiple callback errors', function () {
            $cts = new CancellationTokenSource();

            $cts->token->onCancel(function () {
                throw new RuntimeException('Error 1');
            });

            $cts->token->onCancel(function () {
                throw new RuntimeException('Error 2');
            });

            expect(fn () => $cts->cancelChain())
                ->toThrow(AggregateErrorException::class)
            ;
        });

        it('continues executing callbacks even if some throw', function () {
            $cts = new CancellationTokenSource();
            $state = ['executed' => []];

            $cts->token->onCancel(function () use (&$state) {
                $state['executed'][] = 1;

                throw new RuntimeException('Error 1');
            });

            $cts->token->onCancel(function () use (&$state) {
                $state['executed'][] = 2;
            });

            $cts->token->onCancel(function () use (&$state) {
                $state['executed'][] = 3;

                throw new RuntimeException('Error 2');
            });

            try {
                $cts->cancelChain();
            } catch (AggregateErrorException $e) {
                // Expected
            }

            expect($state['executed'])->toBe([1, 2, 3]);
        });

        it('behaves identically to cancel() when tracking root promises directly', function () {
            $cts1 = new CancellationTokenSource();
            $cts2 = new CancellationTokenSource();

            $root1 = new Promise(function () {});
            $root2 = new Promise(function () {});

            $cts1->token->track($root1);
            $cts2->token->track($root2);

            $cts1->cancel();
            $cts2->cancelChain();

            expect($root1->isCancelled())->toBeTrue();
            expect($root2->isCancelled())->toBeTrue();
        });
    });

    describe('cancelAfter()', function () {
        it('cancels after specified delay', function () {
            $cts = new CancellationTokenSource();

            $cts->cancelAfter(0.05);

            expect($cts->token->isCancelled())->toBeFalse();

            Loop::run();

            expect($cts->token->isCancelled())->toBeTrue();
        });

        it('allows multiple cancelAfter calls', function () {
            $cts = new CancellationTokenSource();

            $cts->cancelAfter(0.01);
            $cts->cancelAfter(0.1);

            Loop::run();

            expect($cts->token->isCancelled())->toBeTrue();
        });

        it('has no effect if already cancelled', function () {
            $cts = new CancellationTokenSource();
            $cts->cancel();

            $state = ['callbackCount' => 0];

            $cts->token->onCancel(function () use (&$state) {
                $state['callbackCount']++;
            });

            expect($state['callbackCount'])->toBe(1);

            $cts->cancelAfter(0.01);

            Loop::run();

            expect($state['callbackCount'])->toBe(1);
        });

        it('cancels tracked promises after delay', function () {
            $cts = new CancellationTokenSource();
            $token = $cts->token;

            $promise1 = new Promise(function () {});
            $promise2 = new Promise(function () {});

            $token->track($promise1);
            $token->track($promise2);

            $cts->cancelAfter(0.05);

            expect($promise1->isCancelled())->toBeFalse();
            expect($promise2->isCancelled())->toBeFalse();

            Loop::run();

            expect($promise1->isCancelled())->toBeTrue();
            expect($promise2->isCancelled())->toBeTrue();
        });
    });

    describe('createLinkedTokenSource()', function () {
        it('creates linked source from multiple tokens', function () {
            $cts1 = new CancellationTokenSource();
            $cts2 = new CancellationTokenSource();
            $cts3 = new CancellationTokenSource();

            $linked = CancellationTokenSource::createLinkedTokenSource(
                $cts1->token,
                $cts2->token,
                $cts3->token
            );

            expect($linked)->toBeInstanceOf(CancellationTokenSource::class);
            expect($linked->token->isCancelled())->toBeFalse();
        });

        it('cancels linked source when any parent is cancelled', function () {
            $cts1 = new CancellationTokenSource();
            $cts2 = new CancellationTokenSource();
            $cts3 = new CancellationTokenSource();

            $linked = CancellationTokenSource::createLinkedTokenSource(
                $cts1->token,
                $cts2->token,
                $cts3->token
            );

            $cts2->cancel();

            expect($linked->token->isCancelled())->toBeTrue();
            expect($cts1->token->isCancelled())->toBeFalse();
            expect($cts3->token->isCancelled())->toBeFalse();
        });

        it('returns uncancelled source when no tokens provided', function () {
            $linked = CancellationTokenSource::createLinkedTokenSource();

            expect($linked->token->isCancelled())->toBeFalse();
        });

        it('returns immediately cancelled source if any token already cancelled', function () {
            $cts1 = new CancellationTokenSource();
            $cts1->cancel();

            $cts2 = new CancellationTokenSource();

            $linked = CancellationTokenSource::createLinkedTokenSource(
                $cts1->token,
                $cts2->token
            );

            expect($linked->token->isCancelled())->toBeTrue();
            expect($cts2->token->isCancelled())->toBeFalse();
        });

        it('does not cancel parent tokens when linked is cancelled directly', function () {
            $cts1 = new CancellationTokenSource();
            $cts2 = new CancellationTokenSource();

            $linked = CancellationTokenSource::createLinkedTokenSource(
                $cts1->token,
                $cts2->token
            );

            $linked->cancel();

            expect($linked->token->isCancelled())->toBeTrue();
            expect($cts1->token->isCancelled())->toBeFalse();
            expect($cts2->token->isCancelled())->toBeFalse();
        });

        it('supports nested linked sources', function () {
            $cts1 = new CancellationTokenSource();
            $cts2 = new CancellationTokenSource();
            $linked1 = CancellationTokenSource::createLinkedTokenSource(
                $cts1->token,
                $cts2->token
            );

            $cts3 = new CancellationTokenSource();
            $linked2 = CancellationTokenSource::createLinkedTokenSource(
                $linked1->token,
                $cts3->token
            );

            $cts1->cancel();

            expect($linked1->token->isCancelled())->toBeTrue();
            expect($linked2->token->isCancelled())->toBeTrue();
            expect($cts2->token->isCancelled())->toBeFalse();
            expect($cts3->token->isCancelled())->toBeFalse();
        });

        it('tracks promises on linked token', function () {
            $cts1 = new CancellationTokenSource();
            $cts2 = new CancellationTokenSource();
            $linked = CancellationTokenSource::createLinkedTokenSource(
                $cts1->token,
                $cts2->token
            );

            $promise = new Promise(function () {});
            $linked->token->track($promise);

            expect($linked->token->getTrackedCount())->toBe(1);

            $cts1->cancel();

            expect($promise->isCancelled())->toBeTrue();
            expect($linked->token->getTrackedCount())->toBe(0);
        });
    });
});