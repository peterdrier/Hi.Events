<?php

namespace Tests\Unit\Services\Domain\Payment\Stripe\EventHandlers;

use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\StripePaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\StripePaymentsRepositoryInterface;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\ChargeDisputeHandler;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use HiEvents\Services\Infrastructure\Lock\TransactionLockService;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Stripe\Collection as StripeCollection;
use Stripe\Dispute;
use Stripe\Service\DisputeService;
use Stripe\StripeClient;
use Tests\TestCase;

class ChargeDisputeHandlerTest extends TestCase
{
    private StripePaymentsRepositoryInterface|MockInterface $stripePaymentsRepository;

    private OrderRepositoryInterface|MockInterface $orderRepository;

    private DisputeService|MockInterface $disputes;

    private TransactionLockService|MockInterface $lock;

    private DomainEventDispatcherService|MockInterface $dispatcher;

    private ChargeDisputeHandler $handler;

    private StripePaymentDomainObject $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripePaymentsRepository = Mockery::mock(StripePaymentsRepositoryInterface::class);
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->disputes = Mockery::mock(DisputeService::class);
        $this->lock = Mockery::mock(TransactionLockService::class)->shouldIgnoreMissing();
        $this->dispatcher = Mockery::mock(DomainEventDispatcherService::class);

        $client = Mockery::mock(StripeClient::class);
        $client->disputes = $this->disputes;
        $factory = Mockery::mock(StripeClientFactory::class);
        $factory->shouldReceive('createForPlatform')->andReturn($client);

        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

        $this->handler = new ChargeDisputeHandler(
            $this->stripePaymentsRepository,
            $this->orderRepository,
            $factory,
            $databaseManager,
            $this->lock,
            $this->dispatcher,
            new NullLogger,
        );

        $this->payment = (new StripePaymentDomainObject)->setId(3)->setOrderId(5)->setPaymentIntentId('pi_1')
            ->setConnectedAccountId('acct_1');
    }

    protected function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        Mockery::close();
        parent::tearDown();
    }

    #[DataProvider('statuses')]
    public function test_the_order_holds_what_the_dispute_as_stripe_has_it_takes(string $stripeStatus, float $expected): void
    {
        $this->paymentFoundByIntent();
        $this->disputes->shouldReceive('all')
            ->with(['payment_intent' => 'pi_1', 'limit' => 100], ['stripe_account' => 'acct_1'])
            ->andReturn($this->stripeList(['dp_1', $stripeStatus, 22500]));
        $this->stripePaymentsRepository->shouldReceive('updateWhere')->once()->with(
            [
                StripePaymentDomainObjectAbstract::DISPUTE_ID => 'dp_1',
                StripePaymentDomainObjectAbstract::DISPUTE_STATUS => $stripeStatus,
                StripePaymentDomainObjectAbstract::DISPUTE_AMOUNT => 22500,
            ],
            [StripePaymentDomainObjectAbstract::ID => 3],
        );
        $this->orderHolding(0);

        if ($expected > 0) {
            $this->expectTotal($expected);
        } else {
            $this->orderRepository->shouldNotReceive('updateFromArray');
            $this->dispatcher->shouldNotReceive('dispatch');
        }

        $this->handler->handleEvent($this->webhookDispute('needs_response'));
    }

    public static function statuses(): array
    {
        return [
            'open' => ['needs_response', 225.0],
            'under review' => ['under_review', 225.0],
            'lost' => ['lost', 225.0],
            'won' => ['won', 0.0],
            'inquiry' => ['warning_needs_response', 0.0],
            'inquiry closed' => ['warning_closed', 0.0],
            'prevented, settled by a refund' => ['prevented', 0.0],
        ];
    }

    public function test_a_won_dispute_releases_what_the_order_held(): void
    {
        $this->paymentFoundByIntent();
        $this->disputes->shouldReceive('all')->andReturn($this->stripeList(['dp_1', 'won', 22500]));
        $this->stripePaymentsRepository->shouldReceive('updateWhere')->once();
        $this->orderHolding(225);
        $this->expectTotal(0.0);

        $this->handler->handleEvent($this->webhookDispute('under_review'));
    }

    public function test_every_dispute_on_a_payment_counts(): void
    {
        $this->paymentFoundByIntent();
        $this->disputes->shouldReceive('all')->andReturn($this->stripeList(
            ['dp_0', 'lost', 5000],
            ['dp_1', 'needs_response', 3000],
            ['dp_2', 'won', 9000],
        ));
        $this->stripePaymentsRepository->shouldReceive('updateWhere')->once()->with(
            Mockery::on(fn (array $attributes) => $attributes[StripePaymentDomainObjectAbstract::DISPUTE_ID] === 'dp_1'),
            [StripePaymentDomainObjectAbstract::ID => 3],
        );
        $this->orderHolding(50);
        $this->expectTotal(80.0);

        $this->handler->handleEvent($this->webhookDispute('needs_response'));
    }

    public function test_stripe_is_read_only_once_the_order_is_locked(): void
    {
        $this->paymentFoundByIntent();
        $this->lock->shouldReceive('lockOrder')->once()->with('o_5')->globally()->ordered();
        $this->disputes->shouldReceive('all')->once()->globally()->ordered()
            ->andReturn($this->stripeList(['dp_1', 'lost', 22500]));
        $this->stripePaymentsRepository->shouldReceive('updateWhere')->once();
        $this->orderHolding(225);
        $this->orderRepository->shouldNotReceive('updateFromArray');
        $this->dispatcher->shouldNotReceive('dispatch');

        $this->handler->handleEvent($this->webhookDispute('needs_response'));
    }

    public function test_a_dispute_stripe_does_not_list_yet_is_read_by_id_and_counted(): void
    {
        $this->paymentFoundByIntent();
        $this->disputes->shouldReceive('all')->andReturn($this->stripeList(['dp_0', 'lost', 1000]));
        $this->disputes->shouldReceive('retrieve')->once()->with('dp_1', [], ['stripe_account' => 'acct_1'])
            ->andReturn(Dispute::constructFrom(['id' => 'dp_1', 'status' => 'needs_response', 'amount' => 22500]));
        $this->stripePaymentsRepository->shouldReceive('updateWhere')->once();
        $this->orderHolding(0);
        $this->expectTotal(235.0);

        $this->handler->handleEvent($this->webhookDispute('needs_response'));
    }

    public function test_a_dispute_on_a_payment_found_by_its_charge_is_recorded(): void
    {
        $this->stripePaymentsRepository->shouldReceive('findFirstWhere')
            ->with([StripePaymentDomainObjectAbstract::PAYMENT_INTENT_ID => 'pi_1'])->andReturnNull();
        $this->stripePaymentsRepository->shouldReceive('findFirstWhere')
            ->with([StripePaymentDomainObjectAbstract::CHARGE_ID => 'ch_1'])->andReturn($this->payment);
        $this->disputes->shouldReceive('all')->andReturn($this->stripeList(['dp_1', 'needs_response', 5000]));
        $this->stripePaymentsRepository->shouldReceive('updateWhere')->once();
        $this->orderHolding(0);
        $this->expectTotal(50.0);

        $this->handler->handleEvent($this->webhookDispute('needs_response'));
    }

    public function test_a_dispute_on_a_payment_this_install_does_not_hold_is_ignored(): void
    {
        $this->stripePaymentsRepository->shouldReceive('findFirstWhere')->andReturnNull();
        $this->disputes->shouldNotReceive('all');
        $this->disputes->shouldNotReceive('retrieve');
        $this->dispatcher->shouldNotReceive('dispatch');

        $this->handler->handleEvent($this->webhookDispute('needs_response'));
    }

    private function paymentFoundByIntent(): void
    {
        $this->stripePaymentsRepository->shouldReceive('findFirstWhere')
            ->with([StripePaymentDomainObjectAbstract::PAYMENT_INTENT_ID => 'pi_1'])
            ->andReturn($this->payment);
    }

    private function orderHolding(float $totalDisputed): void
    {
        $this->stripePaymentsRepository->shouldReceive('findWhere')
            ->with([StripePaymentDomainObjectAbstract::ORDER_ID => 5])
            ->andReturn(new Collection([$this->payment]));
        $this->orderRepository->shouldReceive('findById')->with(5)
            ->andReturn((new OrderDomainObject)->setId(5)->setShortId('o_5')->setCurrency('EUR')->setTotalDisputed($totalDisputed));
    }

    private function expectTotal(float $expected): void
    {
        $this->orderRepository->shouldReceive('updateFromArray')->once()
            ->with(5, [OrderDomainObjectAbstract::TOTAL_DISPUTED => $expected]);
        $this->dispatcher->shouldReceive('dispatch')->once()
            ->with(Mockery::on(fn (OrderEvent $event) => $event->type === DomainEventType::ORDER_UPDATED && $event->orderId === 5));
    }

    private function stripeList(array ...$disputes): StripeCollection
    {
        return StripeCollection::constructFrom([
            'object' => 'list',
            'has_more' => false,
            'data' => array_map(fn (array $dispute) => [
                'object' => 'dispute',
                'id' => $dispute[0],
                'status' => $dispute[1],
                'amount' => $dispute[2],
            ], $disputes),
        ]);
    }

    private function webhookDispute(string $status): Dispute
    {
        return Dispute::constructFrom([
            'id' => 'dp_1',
            'status' => $status,
            'amount' => 22500,
            'payment_intent' => 'pi_1',
            'charge' => 'ch_1',
        ]);
    }
}
