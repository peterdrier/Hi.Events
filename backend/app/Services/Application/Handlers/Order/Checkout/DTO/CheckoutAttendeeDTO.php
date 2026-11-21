<?php

namespace HiEvents\Services\Application\Handlers\Order\Checkout\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class CheckoutAttendeeDTO extends BaseDataObject
{
    public function __construct(
        public string $first_name,
        public ?string $last_name = null,
        public ?string $email = null,
    ) {}
}
