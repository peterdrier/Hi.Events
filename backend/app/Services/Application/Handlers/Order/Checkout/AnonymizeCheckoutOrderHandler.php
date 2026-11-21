<?php

namespace HiEvents\Services\Application\Handlers\Order\Checkout;

use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

class AnonymizeCheckoutOrderHandler
{
    private const SCRUBBED_NAME = 'Anonymized';

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
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

            $this->attendeeRepository->updateWhere(
                attributes: [
                    AttendeeDomainObjectAbstract::FIRST_NAME => self::SCRUBBED_NAME,
                    AttendeeDomainObjectAbstract::LAST_NAME => '',
                    AttendeeDomainObjectAbstract::EMAIL => sprintf('anonymized+order-%d@anonymized.invalid', $orderId),
                    AttendeeDomainObjectAbstract::NOTES => null,
                ],
                where: [AttendeeDomainObjectAbstract::ORDER_ID => $orderId],
            );

            return $this->orderRepository->updateFromArray($orderId, [
                OrderDomainObjectAbstract::FIRST_NAME => self::SCRUBBED_NAME,
                OrderDomainObjectAbstract::LAST_NAME => '',
                OrderDomainObjectAbstract::EMAIL => sprintf('anonymized+order-%d@anonymized.invalid', $orderId),
                OrderDomainObjectAbstract::ADDRESS => null,
                OrderDomainObjectAbstract::NOTES => null,
            ]);
        });
    }
}
