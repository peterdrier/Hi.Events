<?php

namespace Tests\Unit\Services\Domain\Payment\Stripe\EventHandlers;

use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\StripePaymentsRepositoryInterface;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\PaymentIntentProcessingHandler;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\TestCase;

class PaymentIntentProcessingHandlerTest extends TestCase
{
    private OrderRepositoryInterface|MockInterface $orderRepository;

    private StripePaymentsRepositoryInterface|MockInterface $stripePaymentsRepository;

    private DomainEventDispatcherService|MockInterface $dispatcher;

    private PaymentIntentService|MockInterface $paymentIntents;

    private PaymentIntentProcessingHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->stripePaymentsRepository = Mockery::mock(StripePaymentsRepositoryInterface::class);
        $this->dispatcher = Mockery::mock(DomainEventDispatcherService::class);
        $this->paymentIntents = Mockery::mock(PaymentIntentService::class);

        $client = Mockery::mock(StripeClient::class);
        $client->paymentIntents = $this->paymentIntents;
        $factory = Mockery::mock(StripeClientFactory::class);
        $factory->shouldReceive('createForPlatform')->andReturn($client);

        $this->handler = new PaymentIntentProcessingHandler(
            $this->orderRepository,
            $this->stripePaymentsRepository,
            $this->dispatcher,
            $factory,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        Mockery::close();
        parent::tearDown();
    }

    public function test_marks_an_unpaid_order_as_processing_and_tells_webhooks(): void
    {
        Carbon::setTestNow('2027-01-02 10:00:00');
        $this->stripePaymentsRepository->shouldReceive('findFirstWhere')
            ->andReturn((new StripePaymentDomainObject)->setOrderId(5)->setPaymentIntentId('pi_sepa')->setConnectedAccountId('acct_1'));
        $this->livePaymentIntentIs('processing', ['stripe_account' => 'acct_1']);

        $this->orderRepository->shouldReceive('updateWhere')
            ->once()
            ->withArgs(function (array $attributes, array $where) {
                return $attributes[OrderDomainObjectAbstract::PAYMENT_PROCESSING_AT]->equalTo(Carbon::parse('2027-01-02 10:00:00'))
                    && $attributes[OrderDomainObjectAbstract::PAYMENT_STATUS] === OrderPaymentStatus::AWAITING_PAYMENT->name
                    && $where === [
                        OrderDomainObjectAbstract::ID => 5,
                        [OrderDomainObjectAbstract::PAYMENT_STATUS, 'in', [
                            OrderPaymentStatus::AWAITING_PAYMENT->name,
                            OrderPaymentStatus::PAYMENT_FAILED->name,
                        ]],
                    ];
            })
            ->andReturn(1);

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(fn (OrderEvent $event) => $event->type === DomainEventType::ORDER_UPDATED && $event->orderId === 5));

        $this->handler->handleEvent(PaymentIntent::constructFrom(['id' => 'pi_sepa', 'status' => 'processing']));
    }

    public function test_a_paid_order_is_left_alone(): void
    {
        $this->stripePaymentsRepository->shouldReceive('findFirstWhere')
            ->andReturn((new StripePaymentDomainObject)->setOrderId(5)->setPaymentIntentId('pi_sepa'));
        $this->livePaymentIntentIs('processing');
        $this->orderRepository->shouldReceive('updateWhere')->once()->andReturn(0);
        $this->dispatcher->shouldNotReceive('dispatch');

        $this->handler->handleEvent(PaymentIntent::constructFrom(['id' => 'pi_sepa', 'status' => 'processing']));
    }

    public function test_an_unknown_payment_intent_is_ignored(): void
    {
        $this->stripePaymentsRepository->shouldReceive('findFirstWhere')->andReturnNull();
        $this->paymentIntents->shouldNotReceive('retrieve');
        $this->orderRepository->shouldNotReceive('updateWhere');
        $this->dispatcher->shouldNotReceive('dispatch');

        $this->handler->handleEvent(PaymentIntent::constructFrom(['id' => 'pi_other', 'status' => 'processing']));
    }

    #[DataProvider('settledStatuses')]
    public function test_a_processing_event_that_arrives_after_stripe_moved_on_is_ignored(string $liveStatus): void
    {
        $this->stripePaymentsRepository->shouldReceive('findFirstWhere')
            ->andReturn((new StripePaymentDomainObject)->setOrderId(5)->setPaymentIntentId('pi_sepa'));
        $this->livePaymentIntentIs($liveStatus);
        $this->orderRepository->shouldNotReceive('updateWhere');
        $this->dispatcher->shouldNotReceive('dispatch');

        $this->handler->handleEvent(PaymentIntent::constructFrom(['id' => 'pi_sepa', 'status' => 'processing']));
    }

    public static function settledStatuses(): array
    {
        return [
            'failed after processing' => ['requires_payment_method'],
            'cancelled' => ['canceled'],
            'paid' => ['succeeded'],
        ];
    }

    private function livePaymentIntentIs(string $status, array $opts = []): void
    {
        $this->paymentIntents->shouldReceive('retrieve')->once()
            ->with('pi_sepa', [], $opts)
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_sepa', 'status' => $status]));
    }
}
