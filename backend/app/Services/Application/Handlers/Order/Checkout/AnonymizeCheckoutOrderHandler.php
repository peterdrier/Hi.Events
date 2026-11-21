<?php

namespace HiEvents\Services\Application\Handlers\Order\Checkout;

use HiEvents\DomainObjects\Enums\AnonymizationStrategy;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Account\Anonymization\AnonymizationContext;
use HiEvents\Services\Domain\Account\Anonymization\AnonymizationExecutor;
use HiEvents\Services\Domain\Account\Anonymization\Anonymizers\OrderAnonymizer;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

class AnonymizeCheckoutOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrderAnonymizer $orderAnonymizer,
        private readonly AnonymizationExecutor $executor,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws ResourceConflictException
     * @throws Throwable
     */
    public function handle(int $eventId, int $orderId): OrderDomainObject
    {
        return $this->databaseManager->transaction(function () use ($eventId, $orderId) {
            $order = $this->orderRepository->findFirstWhere([
                OrderDomainObjectAbstract::EVENT_ID => $eventId,
                OrderDomainObjectAbstract::ID => $orderId,
            ]);

            if ($order === null) {
                throw new ResourceNotFoundException(__('Order :id not found for event :eventId', [
                    'id' => $orderId,
                    'eventId' => $eventId,
                ]));
            }

            if ($order->getExternalReference() === null) {
                throw new ResourceConflictException(__('Only orders created through the checkout endpoint can be anonymized here'));
            }

            $context = $this->contextFor($eventId, $orderId);

            $this->orderAnonymizer->anonymize($context);
            $this->scrubStripeCustomer($order, $context);
            $this->deleteWebhookLogs($context->accountId, $orderId);

            return $this->orderRepository->findById($orderId);
        });
    }

    private function contextFor(int $eventId, int $orderId): AnonymizationContext
    {
        return new AnonymizationContext(
            accountId: $this->eventRepository->findById($eventId)->getAccountId(),
            eventIds: [],
            orderIds: [$orderId],
            organizerIds: [],
            soleUserIds: [],
            sharedUserIds: [],
            soleUserEmails: [],
            stripeAccountIds: [],
            imageFiles: [],
        );
    }

    private function scrubStripeCustomer(OrderDomainObject $order, AnonymizationContext $context): void
    {
        if ($order->getEmail() === null) {
            return;
        }

        $this->executor->scrub(
            query: $this->databaseManager->table('stripe_customers')->where('email', $order->getEmail()),
            entity: 'stripe_customers',
            columnStrategies: [
                'name' => AnonymizationStrategy::SCRUB_TEXT,
                'email' => AnonymizationStrategy::SCRUB_EMAIL,
            ],
            context: $context,
        );
    }

    private function deleteWebhookLogs(int $accountId, int $orderId): void
    {
        $this->executor->delete(
            query: $this->databaseManager->table('webhook_logs')
                ->whereIn('webhook_id', $this->databaseManager->table('webhooks')->select('id')->where('account_id', $accountId))
                ->where(fn ($query) => $query
                    ->whereRaw("(payload::jsonb ->> 'event_type') like 'order.%' and (payload::jsonb -> 'payload' ->> 'id') = ?", [(string) $orderId])
                    ->orWhereRaw("(payload::jsonb -> 'payload' ->> 'order_id') = ?", [(string) $orderId])),
            entity: 'webhook_logs',
        );
    }
}
