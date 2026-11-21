<?php

namespace HiEvents\Services\Domain\Payment\Stripe\EventHandlers;

use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\StripePaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\Enterprise\BoxOffice\Services\Domain\Payment\Stripe\Terminal\TerminalAttemptTracker;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Repository\Eloquent\StripePaymentsRepository;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Payment\Stripe\PaymentIntentFailureGuard;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentUpdateFromPaymentIntentService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Illuminate\Database\DatabaseManager;
use Stripe\PaymentIntent;
use Throwable;

readonly class PaymentIntentFailedHandler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private StripePaymentsRepository $stripePaymentsRepository,
        private DatabaseManager $databaseManager,
        private StripePaymentUpdateFromPaymentIntentService $stripePaymentUpdateFromPaymentIntentService,
        private TerminalAttemptTracker $attemptTracker,
        private PaymentIntentFailureGuard $paymentIntentFailureGuard,
        private DomainEventDispatcherService $domainEventDispatcherService,
    ) {}

    /**
     * @throws Throwable
     */
    public function handleEvent(PaymentIntent $paymentIntent, ?int $eventCreatedAt = null): void
    {
        if ($this->attemptTracker->predatesCurrentAttempt($paymentIntent->id, $eventCreatedAt)) {
            return;
        }

        $updatedOrder = null;

        $stripePayment = $this->databaseManager->transaction(function () use ($paymentIntent, &$updatedOrder) {
            /** @var StripePaymentDomainObjectAbstract $stripePayment */
            $stripePayment = $this->stripePaymentsRepository
                ->loadRelation(new Relationship(OrderDomainObject::class, name: 'order'))
                ->findFirstWhere([
                    StripePaymentDomainObjectAbstract::PAYMENT_INTENT_ID => $paymentIntent->id,
                ]);

            if (! $stripePayment) {
                return null;
            }

            $this->stripePaymentUpdateFromPaymentIntentService->updateStripePaymentInfo($paymentIntent, $stripePayment);

            $updatedOrder = $this->updateOrderStatuses($stripePayment);

            if ($updatedOrder !== null) {
                OrderStatusChangedEvent::dispatch($updatedOrder);
            }

            return $stripePayment;
        });

        if ($updatedOrder !== null) {
            $this->domainEventDispatcherService->dispatch(
                new OrderEvent(
                    type: DomainEventType::ORDER_UPDATED,
                    orderId: $updatedOrder->getId(),
                ),
            );
        }

        if ($stripePayment !== null && $paymentIntent->status !== PaymentIntent::STATUS_CANCELED) {
            $this->paymentIntentFailureGuard->recordFailure($stripePayment);
        }
    }

    private function updateOrderStatuses(StripePaymentDomainObjectAbstract $stripePayment): ?OrderDomainObject
    {
        $affected = $this->orderRepository->updateWhere(
            attributes: [
                OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::PAYMENT_FAILED->name,
                OrderDomainObjectAbstract::PAYMENT_PROCESSING_AT => null,
            ],
            where: [
                OrderDomainObjectAbstract::ID => $stripePayment->getOrderId(),
                OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::AWAITING_PAYMENT->name,
            ],
        );

        if ($affected === 0) {
            return null;
        }

        return $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->findById($stripePayment->getOrderId());
    }
}
