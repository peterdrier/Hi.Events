<?php

namespace HiEvents\Services\Application\Handlers\Order\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\OrderDomainObject;

class RefundOrderResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly OrderDomainObject $order,
        public readonly ?string $refund_id = null,
        public readonly ?string $refund_status = null,
    ) {}
}
