<?php

namespace HiEvents\Services\Domain\Payment\Stripe\EventHandlers;

use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\StripePaymentDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\StripePaymentsRepositoryInterface;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;

readonly class PaymentIntentProcessingHandler
{
    private const UNPAID_STATUSES = [
        OrderPaymentStatus::AWAITING_PAYMENT,
        OrderPaymentStatus::PAYMENT_FAILED,
    ];

    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private StripePaymentsRepositoryInterface $stripePaymentsRepository,
        private DomainEventDispatcherService $domainEventDispatcherService,
        private StripeClientFactory $stripeClientFactory,
    ) {}

    /**
     * @throws ApiErrorException
     */
    public function handleEvent(PaymentIntent $paymentIntent): void
    {
        $stripePayment = $this->findStripePayment($paymentIntent->id);

        if ($stripePayment === null) {
            return;
        }

        $this->record($this->retrieve($stripePayment), $stripePayment->getOrderId());
    }

    public function recordRetrievedPaymentIntent(PaymentIntent $retrievedPaymentIntent): void
    {
        $stripePayment = $this->findStripePayment($retrievedPaymentIntent->id);

        if ($stripePayment === null) {
            return;
        }

        $this->record($retrievedPaymentIntent, $stripePayment->getOrderId());
    }

    private function record(PaymentIntent $current, int $orderId): void
    {
        $unpaid = [OrderDomainObjectAbstract::PAYMENT_STATUS, 'in', array_map(
            static fn (OrderPaymentStatus $status) => $status->name,
            self::UNPAID_STATUSES,
        )];

        if ($current->status === PaymentIntent::STATUS_PROCESSING) {
            $affected = $this->orderRepository->updateWhere(
                attributes: [
                    OrderDomainObjectAbstract::PAYMENT_PROCESSING_AT => now(),
                    OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::AWAITING_PAYMENT->name,
                ],
                where: [
                    OrderDomainObjectAbstract::ID => $orderId,
                    $unpaid,
                ],
            );
        } elseif ($current->status !== PaymentIntent::STATUS_SUCCEEDED) {
            $affected = $this->orderRepository->updateWhere(
                attributes: [OrderDomainObjectAbstract::PAYMENT_PROCESSING_AT => null],
                where: [
                    OrderDomainObjectAbstract::ID => $orderId,
                    [OrderDomainObjectAbstract::PAYMENT_PROCESSING_AT, 'not null', null],
                    $unpaid,
                ],
            );
        } else {
            return;
        }

        if ($affected === 0) {
            return;
        }

        $this->domainEventDispatcherService->dispatch(
            new OrderEvent(
                type: DomainEventType::ORDER_UPDATED,
                orderId: $orderId,
            ),
        );
    }

    private function findStripePayment(string $paymentIntentId): ?StripePaymentDomainObject
    {
        return $this->stripePaymentsRepository->findFirstWhere([
            StripePaymentDomainObjectAbstract::PAYMENT_INTENT_ID => $paymentIntentId,
        ]);
    }

    /**
     * @throws ApiErrorException
     */
    private function retrieve(StripePaymentDomainObject $stripePayment): PaymentIntent
    {
        return $this->stripeClientFactory
            ->createForPlatform($stripePayment->getStripePlatformEnum())
            ->paymentIntents
            ->retrieve(
                $stripePayment->getPaymentIntentId(),
                [],
                $stripePayment->getConnectedAccountId() !== null
                    ? ['stripe_account' => $stripePayment->getConnectedAccountId()]
                    : [],
            );
    }
}
