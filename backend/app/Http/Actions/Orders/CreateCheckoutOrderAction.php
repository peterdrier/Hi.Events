<?php

namespace HiEvents\Http\Actions\Orders;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Attributes\RequiresPermission;
use HiEvents\Http\Request\Order\CreateCheckoutOrderRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Order\OrderResource;
use HiEvents\Services\Application\Handlers\Order\Checkout\CreateCheckoutOrderHandler;
use HiEvents\Services\Application\Handlers\Order\Checkout\DTO\CreateCheckoutOrderDTO;
use Illuminate\Http\JsonResponse;

#[RequiresPermission(Permission::EDIT)]
class CreateCheckoutOrderAction extends BaseAction
{
    public function __construct(
        private readonly CreateCheckoutOrderHandler $handler,
    ) {}

    /**
     * Create checkout order
     *
     * Creates a reserved order at the prices given, with the buyer and attendee details filled in, and returns
     * `payment_link_url`, where the buyer pays. Each item is one unit; taxes and fees configured on a product are added
     * on top, so the request fails unless the products used carry none. A repeat with the same `external_reference`
     * returns the existing order instead of creating another. After paying, the buyer is sent to `return_url` when one
     * is given; `send_buyer_emails: false` stops every email Hi.Events would send the buyer or attendees for the order.
     */
    public function __invoke(CreateCheckoutOrderRequest $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        try {
            $order = $this->handler->handle(CreateCheckoutOrderDTO::from([
                ...$request->validated(),
                'event_id' => $eventId,
            ]));
        } catch (ResourceConflictException $e) {
            return $this->errorResponse($e->getMessage(), ResponseCodes::HTTP_CONFLICT);
        }

        return $this->resourceResponse(
            resource: OrderResource::class,
            data: $order,
            statusCode: ResponseCodes::HTTP_CREATED,
        );
    }
}
