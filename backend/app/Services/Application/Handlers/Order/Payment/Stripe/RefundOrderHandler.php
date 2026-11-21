<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\Stripe;

use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\RefundNotPossibleException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRefundRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderResultDTO;
use HiEvents\Services\Domain\Order\OrderCancelService;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentRefundService;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use HiEvents\Values\MoneyValue;
use Illuminate\Database\DatabaseManager;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

class RefundOrderHandler
{
    public function __construct(
        private readonly StripePaymentIntentRefundService $refundService,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderRefundRepositoryInterface $orderRefundRepository,
        private readonly OrderCancelService $orderCancelService,
        private readonly DatabaseManager $databaseManager,
        private readonly StripeClientFactory $stripeClientFactory,
    ) {}

    /**
     * @throws RefundNotPossibleException
     * @throws ApiErrorException
     * @throws Throwable
     */
    public function handle(RefundOrderDTO $refundOrderDTO): RefundOrderResultDTO
    {
        return $this->databaseManager->transaction(fn () => $this->refundOrder($refundOrderDTO));
    }

    private function fetchOrder(int $eventId, int $orderId): OrderDomainObject
    {
        $order = $this->orderRepository
            ->loadRelation(new Relationship(StripePaymentDomainObject::class, name: 'stripe_payment'))
            ->findFirstWhere(['event_id' => $eventId, 'id' => $orderId]);

        if (! $order) {
            throw new ResourceNotFoundException(__('Order :id not found for event :eventId', [
                'id' => $orderId,
                'eventId' => $eventId,
            ]));
        }

        return $order;
    }

    /**
     * @throws RefundNotPossibleException
     */
    private function validateRefundability(OrderDomainObject $order): void
    {
        if (! $order->getStripePayment()) {
            throw new RefundNotPossibleException(__('There is no Stripe data associated with this order.'));
        }

        if ($order->getRefundStatus() === OrderRefundStatus::REFUND_PENDING->name) {
            throw new RefundNotPossibleException(
                __('There is already a refund pending for this order.
                Please wait for the refund to be processed before requesting another one.')
            );
        }
    }

    /**
     * @throws ApiErrorException
     */
    private function findRefundUnderKey(OrderDomainObject $order, ?string $idempotencyKey): ?RefundOrderResultDTO
    {
        if ($idempotencyKey === null) {
            return null;
        }

        $recorded = $this->orderRefundRepository->findFirstWhere([
            'order_id' => $order->getId(),
            'metadata->'.StripePaymentIntentRefundService::IDEMPOTENCY_KEY_METADATA_KEY => $idempotencyKey,
        ]);

        if ($recorded !== null) {
            return new RefundOrderResultDTO($order, $recorded->getRefundId(), $recorded->getStatus());
        }

        if (! $order->getStripePayment()) {
            return null;
        }

        $refund = $this->refundService->findRefundUnderKey(
            payment: $order->getStripePayment(),
            stripeClient: $this->stripeClientFactory->createForPlatform($order->getStripePayment()->getStripePlatformEnum()),
            idempotencyKey: $idempotencyKey,
        );

        return $refund === null ? null : new RefundOrderResultDTO($order, $refund->id, $refund->status);
    }

    private function markOrderRefundPending(OrderDomainObject $order): OrderDomainObject
    {
        return $this->orderRepository->updateFromArray(
            id: $order->getId(),
            attributes: [
                OrderDomainObjectAbstract::REFUND_STATUS => OrderRefundStatus::REFUND_PENDING->name,
            ]
        );
    }

    private function countFailedRefunds(OrderDomainObject $order): int
    {
        return $this->orderRefundRepository->countWhere([
            'order_id' => $order->getId(),
            ['status', 'in', [Refund::STATUS_FAILED, Refund::STATUS_CANCELED]],
        ]);
    }

    /**
     * @throws ApiErrorException
     * @throws UnknownCurrencyException
     * @throws RefundNotPossibleException
     * @throws Throwable
     * @throws RoundingNecessaryException
     * @throws MathException
     * @throws NumberFormatException
     */
    private function refundOrder(RefundOrderDTO $refundOrderDTO): RefundOrderResultDTO
    {
        $order = $this->fetchOrder($refundOrderDTO->event_id, $refundOrderDTO->order_id);

        $existing = $this->findRefundUnderKey($order, $refundOrderDTO->idempotency_key);

        if ($existing !== null) {
            return $existing;
        }

        $amount = MoneyValue::fromFloat($refundOrderDTO->amount, $order->getCurrency());

        $this->validateRefundability($order);

        if ($refundOrderDTO->cancel_order) {
            $this->orderCancelService->cancelOrder($order);
        }

        $pendingOrder = $this->markOrderRefundPending($order);

        $paymentPlatform = $order->getStripePayment()->getStripePlatformEnum();

        $stripeClient = $this->stripeClientFactory->createForPlatform($paymentPlatform);

        $refund = $this->refundService->refundPayment(
            amount: $amount,
            alreadyRefunded: MoneyValue::fromFloat($order->getTotalRefunded(), $order->getCurrency()),
            failedRefundCount: $this->countFailedRefunds($order),
            payment: $order->getStripePayment(),
            stripeClient: $stripeClient,
            notifyBuyer: $refundOrderDTO->notify_buyer && $order->getEmail() !== null && $order->getSendBuyerEmails(),
            idempotencyKey: $refundOrderDTO->idempotency_key,
        );

        return new RefundOrderResultDTO($pendingOrder, $refund->id, $refund->status);
    }
}
