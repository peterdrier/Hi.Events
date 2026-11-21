<?php

namespace HiEvents\Services\Application\Handlers\Order\Checkout\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use Spatie\LaravelData\Attributes\DataCollectionOf;

class CreateCheckoutOrderDTO extends BaseDataObject
{
    public function __construct(
        public int $event_id,
        public string $external_reference,
        public string $first_name,
        public ?string $last_name,
        public string $email,
        public string $locale,
        public string $expires_at,
        /** @var CheckoutOrderItemDTO[] */
        #[DataCollectionOf(CheckoutOrderItemDTO::class)]
        public array $items,
    ) {}
}
