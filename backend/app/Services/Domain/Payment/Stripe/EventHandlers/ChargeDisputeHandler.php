<?php

namespace HiEvents\Services\Domain\Payment\Stripe\EventHandlers;

use Brick\Money\Money;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\StripePaymentDomainObjectAbstract;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\StripePaymentsRepositoryInterface;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use HiEvents\Services\Infrastructure\Lock\TransactionLockService;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Stripe\Dispute;
use Stripe\StripeClient;
use Throwable;

readonly class ChargeDisputeHandler
{
    private const STATUS_PREVENTED = 'prevented';

    private const STATUSES_HOLDING_NO_MONEY = [
        Dispute::STATUS_WON,
        Dispute::STATUS_WARNING_NEEDS_RESPONSE,
        Dispute::STATUS_WARNING_UNDER_REVIEW,
        Dispute::STATUS_WARNING_CLOSED,
        self::STATUS_PREVENTED,
    ];

    public function __construct(
        private StripePaymentsRepositoryInterface $stripePaymentsRepository,
        private OrderRepositoryInterface $orderRepository,
        private StripeClientFactory $stripeClientFactory,
        private DatabaseManager $databaseManager,
        private TransactionLockService $transactionLockService,
        private DomainEventDispatcherService $domainEventDispatcherService,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws Throwable
     */
    public function handleEvent(Dispute $dispute): void
    {
        $stripePayment = $this->findStripePayment($dispute);

        if ($stripePayment === null) {
            $this->logger->info('Stripe dispute for a payment this install does not hold', [
                'dispute_id' => $dispute->id,
            ]);

            return;
        }

        $changed = $this->databaseManager->transaction(function () use ($dispute, $stripePayment) {
            $order = $this->orderRepository->findById($stripePayment->getOrderId());
            $this->transactionLockService->lockOrder($order->getShortId());

            $disputedMinor = 0;

            foreach ($this->stripePaymentsRepository->findWhere([StripePaymentDomainObjectAbstract::ORDER_ID => $order->getId()]) as $payment) {
                $disputes = [];
                foreach ($this->listDisputes($payment) as $listed) {
                    $disputes[$listed->id] = $listed;
                }

                if ($payment->getId() === $stripePayment->getId()) {
                    $current = $disputes[$dispute->id] ?? $this->retrieve($dispute->id, $payment);
                    $disputes[$current->id] = $current;
                    $this->recordOnPayment($payment, $current);
                }

                $disputedMinor += array_sum(array_map(
                    static fn (Dispute $each) => in_array($each->status, self::STATUSES_HOLDING_NO_MONEY, true) ? 0 : (int) $each->amount,
                    $disputes,
                ));
            }

            $totalDisputed = Money::ofMinor($disputedMinor, $order->getCurrency())->getAmount()->toFloat();

            if (abs($totalDisputed - (float) $order->getTotalDisputed()) < 0.001) {
                return false;
            }

            $this->orderRepository->updateFromArray($order->getId(), [
                OrderDomainObjectAbstract::TOTAL_DISPUTED => $totalDisputed,
            ]);

            $this->logger->warning('Stripe dispute changed the amount held back on an order', [
                'order_id' => $order->getId(),
                'dispute_id' => $dispute->id,
                'total_disputed' => $totalDisputed,
            ]);

            return true;
        });

        if (! $changed) {
            return;
        }

        $this->domainEventDispatcherService->dispatch(
            new OrderEvent(
                type: DomainEventType::ORDER_UPDATED,
                orderId: $stripePayment->getOrderId(),
            ),
        );
    }

    private function retrieve(string $disputeId, StripePaymentDomainObject $payment): Dispute
    {
        return $this->client($payment)->disputes->retrieve($disputeId, [], $this->options($payment));
    }

    private function recordOnPayment(StripePaymentDomainObject $payment, Dispute $current): void
    {
        $this->stripePaymentsRepository->updateWhere(
            attributes: [
                StripePaymentDomainObjectAbstract::DISPUTE_ID => $current->id,
                StripePaymentDomainObjectAbstract::DISPUTE_STATUS => $current->status,
                StripePaymentDomainObjectAbstract::DISPUTE_AMOUNT => $current->amount,
            ],
            where: [StripePaymentDomainObjectAbstract::ID => $payment->getId()],
        );
    }

    private function findStripePayment(Dispute $dispute): ?StripePaymentDomainObject
    {
        $paymentIntentId = is_string($dispute->payment_intent) ? $dispute->payment_intent : $dispute->payment_intent?->id;

        if ($paymentIntentId !== null) {
            $payment = $this->stripePaymentsRepository->findFirstWhere([
                StripePaymentDomainObjectAbstract::PAYMENT_INTENT_ID => $paymentIntentId,
            ]);

            if ($payment !== null) {
                return $payment;
            }
        }

        $chargeId = is_string($dispute->charge) ? $dispute->charge : $dispute->charge?->id;

        return $chargeId === null ? null : $this->stripePaymentsRepository->findFirstWhere([
            StripePaymentDomainObjectAbstract::CHARGE_ID => $chargeId,
        ]);
    }

    /**
     * @return Dispute[]
     */
    private function listDisputes(StripePaymentDomainObject $payment): array
    {
        return $this->client($payment)->disputes->all(
            ['payment_intent' => $payment->getPaymentIntentId(), 'limit' => 100],
            $this->options($payment),
        )->data;
    }

    private function client(StripePaymentDomainObject $payment): StripeClient
    {
        return $this->stripeClientFactory->createForPlatform($payment->getStripePlatformEnum());
    }

    private function options(StripePaymentDomainObject $payment): array
    {
        return $payment->getConnectedAccountId() !== null
            ? ['stripe_account' => $payment->getConnectedAccountId()]
            : [];
    }
}
