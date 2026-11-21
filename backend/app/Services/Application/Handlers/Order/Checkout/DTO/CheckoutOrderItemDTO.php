<?php

namespace HiEvents\Services\Application\Handlers\Order\Checkout\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class CheckoutOrderItemDTO extends BaseDataObject
{
    public function __construct(
        public int $product_id,
        public float $price,
        public ?int $product_price_id = null,
        public ?int $event_occurrence_id = null,
        public ?CheckoutAttendeeDTO $attendee = null,
    ) {}
}
