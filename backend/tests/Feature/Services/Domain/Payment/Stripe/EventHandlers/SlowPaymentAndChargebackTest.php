<?php

namespace Tests\Feature\Services\Domain\Payment\Stripe\EventHandlers;

use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\ChargeDisputeHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\PaymentIntentProcessingHandler;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Stripe\Collection;
use Stripe\Dispute;
use Stripe\PaymentIntent;
use Stripe\Service\DisputeService;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\Feature\Support\InsertsRecurringEventRows;
use Tests\TestCase;

class SlowPaymentAndChargebackTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsRecurringEventRows;

    private const PAYMENT_INTENT_ID = 'pi_slow_payment';

    private int $orderId;

    private PaymentIntentService|MockInterface $paymentIntents;

    private DisputeService|MockInterface $disputes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->insertAccountAndOrganizer();
        $this->eventId = $this->insertEvent();
        $this->orderId = $this->insertOrder(OrderStatus::RESERVED->name, now()->subHour());

        DB::table('stripe_payments')->insert([
            'order_id' => $this->orderId,
            'payment_intent_id' => self::PAYMENT_INTENT_ID,
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->paymentIntents = Mockery::mock(PaymentIntentService::class);
        $this->disputes = Mockery::mock(DisputeService::class);
        $stripeClient = Mockery::mock(StripeClient::class);
        $stripeClient->shouldReceive('getService')->with('paymentIntents')->andReturn($this->paymentIntents);
        $stripeClient->shouldReceive('getService')->with('disputes')->andReturn($this->disputes);
        $factory = Mockery::mock(StripeClientFactory::class);
        $factory->shouldReceive('createForPlatform')->andReturn($stripeClient);
        $this->app->instance(StripeClientFactory::class, $factory);
        $this->app->instance(DomainEventDispatcherService::class, Mockery::mock(DomainEventDispatcherService::class)->shouldIgnoreMissing());
    }

    public function test_a_processing_event_delivered_after_the_payment_failed_leaves_the_order_failed(): void
    {
        $this->orderIs(OrderPaymentStatus::PAYMENT_FAILED, processingAt: null);
        $this->stripeHasThePaymentIntent('requires_payment_method');

        $this->processingEventArrives();

        $this->assertOrder(OrderPaymentStatus::PAYMENT_FAILED, processing: false);
    }

    public function test_an_order_left_marked_processing_after_its_payment_failed_is_cleared(): void
    {
        $this->orderIs(OrderPaymentStatus::AWAITING_PAYMENT, processingAt: now()->subDay());
        $this->stripeHasThePaymentIntent('requires_payment_method');

        $this->processingEventArrives();

        $this->assertOrder(OrderPaymentStatus::AWAITING_PAYMENT, processing: false);
    }

    public function test_a_payment_still_processing_marks_the_order(): void
    {
        $this->orderIs(OrderPaymentStatus::PAYMENT_FAILED, processingAt: null);
        $this->stripeHasThePaymentIntent('processing');

        $this->processingEventArrives();

        $this->assertOrder(OrderPaymentStatus::AWAITING_PAYMENT, processing: true);
    }

    public function test_the_order_holds_every_open_or_lost_dispute_and_nothing_stripe_settled_with_a_refund(): void
    {
        $this->disputes->shouldReceive('all')
            ->with(['payment_intent' => self::PAYMENT_INTENT_ID, 'limit' => 100], [])
            ->andReturn(Collection::constructFrom([
                'object' => 'list',
                'data' => [
                    ['object' => 'dispute', 'id' => 'dp_lost', 'status' => 'lost', 'amount' => 5000],
                    ['object' => 'dispute', 'id' => 'dp_open', 'status' => 'needs_response', 'amount' => 1250],
                    ['object' => 'dispute', 'id' => 'dp_prevented', 'status' => 'prevented', 'amount' => 2000],
                ],
            ]));

        $handler = $this->app->make(ChargeDisputeHandler::class);
        $event = Dispute::constructFrom(['id' => 'dp_open', 'status' => 'needs_response', 'payment_intent' => self::PAYMENT_INTENT_ID]);
        $handler->handleEvent($event);
        $handler->handleEvent($event);

        $this->assertEquals(62.5, (float) DB::table('orders')->where('id', $this->orderId)->value('total_disputed'));
        $payment = DB::table('stripe_payments')->where('order_id', $this->orderId)->first();
        $this->assertSame(['dp_open', 'needs_response', 1250], [$payment->dispute_id, $payment->dispute_status, (int) $payment->dispute_amount]);
    }

    private function orderIs(OrderPaymentStatus $paymentStatus, ?\DateTimeInterface $processingAt): void
    {
        DB::table('orders')->where('id', $this->orderId)->update([
            'payment_status' => $paymentStatus->name,
            'payment_processing_at' => $processingAt,
        ]);
    }

    private function stripeHasThePaymentIntent(string $status): void
    {
        $this->paymentIntents->shouldReceive('retrieve')
            ->with(self::PAYMENT_INTENT_ID, [], [])
            ->andReturn(PaymentIntent::constructFrom(['id' => self::PAYMENT_INTENT_ID, 'status' => $status]));
    }

    private function processingEventArrives(): void
    {
        $this->app->make(PaymentIntentProcessingHandler::class)
            ->handleEvent(PaymentIntent::constructFrom(['id' => self::PAYMENT_INTENT_ID, 'status' => 'processing']));
    }

    private function assertOrder(OrderPaymentStatus $paymentStatus, bool $processing): void
    {
        $order = DB::table('orders')->where('id', $this->orderId)->first();
        $this->assertSame($paymentStatus->name, $order->payment_status);
        $this->assertSame($processing, $order->payment_processing_at !== null);
    }
}
