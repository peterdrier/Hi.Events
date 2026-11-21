<?php

namespace HiEvents\Http\Actions\Orders;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Attributes\RequiresPermission;
use HiEvents\Resources\Order\OrderResource;
use HiEvents\Services\Application\Handlers\Order\Checkout\AnonymizeCheckoutOrderHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

#[RequiresPermission(Permission::EDIT)]
class AnonymizeCheckoutOrderAction extends BaseAction
{
    public function __construct(
        private readonly AnonymizeCheckoutOrderHandler $handler,
    ) {}

    /**
     * Anonymize order
     *
     * For an order created through the checkout endpoint: scrubs the buyer's and every attendee's personal data the way
     * account deletion does (soft-deleted attendees included), the buyer's saved Stripe customer row, and the webhook
     * delivery logs that carried the order, keeping amounts, statuses and dates. Other orders get 409. Repeating the
     * call changes nothing further.
     *
     * @throws Throwable
     */
    public function __invoke(int $eventId, int $orderId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        try {
            $order = $this->handler->handle($eventId, $orderId);
        } catch (ResourceNotFoundException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (ResourceConflictException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->resourceResponse(OrderResource::class, $order);
    }
}
