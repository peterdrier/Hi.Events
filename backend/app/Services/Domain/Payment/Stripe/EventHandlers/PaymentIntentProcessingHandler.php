<?php

namespace HiEvents\Services\Domain\Payment\Stripe\EventHandlers;

use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\StripePaymentDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\StripePaymentsRepositoryInterface;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Stripe\PaymentIntent;

readonly class PaymentIntentProcessingHandler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private StripePaymentsRepositoryInterface $stripePaymentsRepository,
        private DomainEventDispatcherService $domainEventDispatcherService,
    ) {}

    public function handleEvent(PaymentIntent $paymentIntent): void
    {
        $stripePayment = $this->stripePaymentsRepository->findFirstWhere([
            StripePaymentDomainObjectAbstract::PAYMENT_INTENT_ID => $paymentIntent->id,
        ]);

        if ($stripePayment === null) {
            return;
        }

        $affected = $this->orderRepository->updateWhere(
            attributes: [
                OrderDomainObjectAbstract::PAYMENT_PROCESSING_AT => now(),
                OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::AWAITING_PAYMENT->name,
            ],
            where: [
                OrderDomainObjectAbstract::ID => $stripePayment->getOrderId(),
                [OrderDomainObjectAbstract::PAYMENT_STATUS, 'in', [
                    OrderPaymentStatus::AWAITING_PAYMENT->name,
                    OrderPaymentStatus::PAYMENT_FAILED->name,
                ]],
            ],
        );

        if ($affected === 0) {
            return;
        }

        $this->domainEventDispatcherService->dispatch(
            new OrderEvent(
                type: DomainEventType::ORDER_UPDATED,
                orderId: $stripePayment->getOrderId(),
            ),
        );
    }
}
