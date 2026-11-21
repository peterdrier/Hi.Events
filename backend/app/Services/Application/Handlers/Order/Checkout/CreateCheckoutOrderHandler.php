<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Order\Checkout;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\Enterprise\Seating\Services\Domain\SeatedProductLookupService;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Helper\Currency;
use HiEvents\Helper\IdHelper;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Checkout\DTO\CheckoutOrderItemDTO;
use HiEvents\Services\Application\Handlers\Order\Checkout\DTO\CreateCheckoutOrderDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\ProductOrderDetailsDTO;
use HiEvents\Services\Domain\Order\DTO\ProcessedOrderItemDTO;
use HiEvents\Services\Domain\Order\OrderItemProcessingService;
use HiEvents\Services\Domain\Order\OrderManagementService;
use HiEvents\Services\Domain\Order\PaymentLink\OrderPaymentLinkTokenService;
use HiEvents\Services\Domain\Product\DTO\OrderProductPriceDTO;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateCheckoutOrderHandler
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly OrderManagementService $orderManagementService,
        private readonly OrderItemProcessingService $orderItemProcessingService,
        private readonly OrderPaymentLinkTokenService $paymentLinkTokenService,
        private readonly SeatedProductLookupService $seatedProductLookup,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws ValidationException
     * @throws ResourceConflictException
     */
    public function handle(CreateCheckoutOrderDTO $data): OrderDomainObject
    {
        $order = $this->databaseManager->transaction(function () use ($data) {
            $existing = $this->findExisting($data);

            if ($existing !== null) {
                $this->assertSameOrder($existing, $data);

                return $existing;
            }

            return $this->create($data);
        });

        return $order->setPaymentLinkUrl($this->paymentLinkTokenService->urlFor($order));
    }

    private function findExisting(CreateCheckoutOrderDTO $data): ?OrderDomainObject
    {
        return $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->loadRelation(AttendeeDomainObject::class)
            ->findFirstWhere([
                OrderDomainObjectAbstract::EVENT_ID => $data->event_id,
                OrderDomainObjectAbstract::EXTERNAL_REFERENCE => $data->external_reference,
            ]);
    }

    /**
     * @throws ResourceConflictException
     */
    private function assertSameOrder(OrderDomainObject $existing, CreateCheckoutOrderDTO $data): void
    {
        if (Currency::round($existing->getTotalGross()) !== $this->requestedTotal($data)) {
            throw new ResourceConflictException(
                __('An order with this external reference already exists with a different total')
            );
        }
    }

    /**
     * @throws ValidationException
     */
    private function create(CreateCheckoutOrderDTO $data): OrderDomainObject
    {
        $event = $this->eventRepository
            ->loadRelation(EventSettingDomainObject::class)
            ->findById($data->event_id);

        $priceIds = $this->resolvePriceIds($event, $data);

        $order = $this->orderManagementService->createNewOrder(
            eventId: $event->getId(),
            event: $event,
            timeOutMinutes: 0,
            locale: $data->locale,
            promoCode: null,
            sessionId: sha1(Str::uuid().Str::random(40)),
        );

        $orderItems = $this->orderItemProcessingService->process(
            order: $order,
            productsOrderDetails: collect($data->items)->map(fn (CheckoutOrderItemDTO $item, int $index) => new ProductOrderDetailsDTO(
                product_id: $item->product_id,
                quantities: collect([new OrderProductPriceDTO(
                    quantity: 1,
                    price_id: $priceIds[$index],
                    price: $item->price,
                )]),
                event_occurrence_id: $item->event_occurrence_id,
            )),
            event: $event,
            promoCode: null,
            allowClientPrices: true,
            applyPlatformFee: false,
        );

        $order = $this->orderManagementService->updateOrderTotals(
            $order,
            $orderItems->map(fn (ProcessedOrderItemDTO $item) => $item->order_item),
        );

        if (Currency::round($order->getTotalGross()) !== $this->requestedTotal($data)) {
            throw ValidationException::withMessages([
                'items' => __('The order total does not match the requested prices. Remove taxes and fees from these products.'),
            ]);
        }

        $this->orderRepository->updateFromArray($order->getId(), [
            OrderDomainObjectAbstract::EXTERNAL_REFERENCE => $data->external_reference,
            OrderDomainObjectAbstract::FIRST_NAME => $data->first_name,
            OrderDomainObjectAbstract::LAST_NAME => $data->last_name ?? '',
            OrderDomainObjectAbstract::EMAIL => $data->email,
            OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::AWAITING_PAYMENT->name,
            OrderDomainObjectAbstract::RESERVED_UNTIL => Carbon::parse($data->expires_at)->utc()->startOfSecond()->toDateTimeString(),
            OrderDomainObjectAbstract::PAYMENT_LINK_CREATED_AT => Carbon::now('UTC')->startOfSecond()->toDateTimeString(),
            OrderDomainObjectAbstract::RETURN_URL => $data->return_url,
            OrderDomainObjectAbstract::SEND_BUYER_EMAILS => $data->send_buyer_emails,
        ]);

        $this->createAttendees($order, $data, $orderItems->map(fn (ProcessedOrderItemDTO $item) => $item->order_item)->values());

        return $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->loadRelation(AttendeeDomainObject::class)
            ->findById($order->getId());
    }

    /**
     * @return array<int, int>
     *
     * @throws ValidationException
     */
    private function resolvePriceIds(EventDomainObject $event, CreateCheckoutOrderDTO $data): array
    {
        $products = $this->productRepository
            ->loadRelation(ProductPriceDomainObject::class)
            ->findWhereIn(
                field: ProductDomainObjectAbstract::ID,
                values: collect($data->items)->pluck('product_id')->unique()->values()->all(),
                additionalWhere: [ProductDomainObjectAbstract::EVENT_ID => $event->getId()],
            )
            ->keyBy(fn (ProductDomainObject $product) => $product->getId());

        $priceIds = [];

        foreach ($data->items as $index => $item) {
            $prices = $products->get($item->product_id)?->getProductPrices() ?? collect();
            $price = $item->product_price_id === null
                ? ($prices->count() === 1 ? $prices->first() : null)
                : $prices->first(fn (ProductPriceDomainObject $price) => $price->getId() === $item->product_price_id);

            if ($price === null) {
                throw ValidationException::withMessages([
                    "items.$index.product_price_id" => __('Product price not found for this event'),
                ]);
            }

            if ($this->seatedProductLookup->isSeated($item->product_id)) {
                throw ValidationException::withMessages([
                    "items.$index.product_id" => __('Please choose a seat for every ticket'),
                ]);
            }

            $priceIds[$index] = $price->getId();
        }

        return $priceIds;
    }

    /**
     * @param  Collection<int, OrderItemDomainObject>  $orderItems
     */
    private function createAttendees(OrderDomainObject $order, CreateCheckoutOrderDTO $data, Collection $orderItems): void
    {
        $inserts = [];

        foreach ($data->items as $index => $item) {
            $orderItem = $orderItems->get($index);

            if ($orderItem === null || $orderItem->getProductType() !== ProductType::TICKET->name) {
                continue;
            }

            $inserts[] = [
                AttendeeDomainObjectAbstract::EVENT_ID => $order->getEventId(),
                AttendeeDomainObjectAbstract::PRODUCT_ID => $orderItem->getProductId(),
                AttendeeDomainObjectAbstract::PRODUCT_PRICE_ID => $orderItem->getProductPriceId(),
                AttendeeDomainObjectAbstract::EVENT_OCCURRENCE_ID => $orderItem->getEventOccurrenceId(),
                AttendeeDomainObjectAbstract::STATUS => AttendeeStatus::AWAITING_PAYMENT->name,
                AttendeeDomainObjectAbstract::EMAIL => $item->attendee?->email ?? $data->email,
                AttendeeDomainObjectAbstract::FIRST_NAME => $item->attendee?->first_name ?? $data->first_name,
                AttendeeDomainObjectAbstract::LAST_NAME => $item->attendee !== null
                    ? ($item->attendee->last_name ?? '')
                    : ($data->last_name ?? ''),
                AttendeeDomainObjectAbstract::ORDER_ID => $order->getId(),
                AttendeeDomainObjectAbstract::PUBLIC_ID => IdHelper::publicId(IdHelper::ATTENDEE_PREFIX),
                AttendeeDomainObjectAbstract::SHORT_ID => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
                AttendeeDomainObjectAbstract::LOCALE => $order->getLocale(),
            ];
        }

        if ($inserts !== []) {
            $this->attendeeRepository->insert($inserts);
        }
    }

    private function requestedTotal(CreateCheckoutOrderDTO $data): float
    {
        return Currency::round(collect($data->items)->sum(fn (CheckoutOrderItemDTO $item) => $item->price));
    }
}
