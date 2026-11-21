<?php

namespace HiEvents\Services\Domain\Payment\Stripe;

use Brick\Math\Exception\MathException;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Values\MoneyValue;
use Illuminate\Config\Repository;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;
use Stripe\StripeClient;

class StripePaymentIntentRefundService
{
    public const NOTIFY_BUYER_METADATA_KEY = 'notify_buyer';

    public const IDEMPOTENCY_KEY_METADATA_KEY = 'idempotency_key';

    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * @throws ApiErrorException
     * @throws MathException
     */
    public function refundPayment(
        MoneyValue $amount,
        MoneyValue $alreadyRefunded,
        int $failedRefundCount,
        StripePaymentDomainObject $payment,
        StripeClient $stripeClient,
        bool $notifyBuyer,
        ?string $idempotencyKey = null,
    ): Refund {
        $params = [
            'payment_intent' => $payment->getPaymentIntentId(),
            'amount' => $amount->toMinorUnit(),
        ];

        if ($notifyBuyer) {
            $params['metadata'][self::NOTIFY_BUYER_METADATA_KEY] = '1';
        }

        if ($idempotencyKey !== null) {
            $params['metadata'][self::IDEMPOTENCY_KEY_METADATA_KEY] = $idempotencyKey;
        }

        return $stripeClient->refunds->create(
            params: $params,
            opts: array_merge(
                $this->getStripeAccountData($payment),
                ['idempotency_key' => $idempotencyKey === null
                    ? $this->idempotencyKey($payment, $amount, $alreadyRefunded, $failedRefundCount)
                    : sprintf('refund_%s_key_%s', $payment->getPaymentIntentId(), $idempotencyKey)],
            ),
        );
    }

    /**
     * @throws ApiErrorException
     */
    public function findRefundUnderKey(
        StripePaymentDomainObject $payment,
        StripeClient $stripeClient,
        string $idempotencyKey,
    ): ?Refund {
        $refunds = $stripeClient->refunds->all(
            params: ['payment_intent' => $payment->getPaymentIntentId(), 'limit' => 100],
            opts: $this->getStripeAccountData($payment),
        );

        foreach ($refunds->data as $refund) {
            if (($refund->metadata[self::IDEMPOTENCY_KEY_METADATA_KEY] ?? null) === $idempotencyKey) {
                return $refund;
            }
        }

        return null;
    }

    private function idempotencyKey(
        StripePaymentDomainObject $payment,
        MoneyValue $amount,
        MoneyValue $alreadyRefunded,
        int $failedRefundCount,
    ): string {
        $key = sprintf('refund_%s_amount_%d', $payment->getPaymentIntentId(), $amount->toMinorUnit());

        if ($alreadyRefunded->toMinorUnit() === 0 && $failedRefundCount === 0) {
            return $key;
        }

        return sprintf('%s_after_%d_failed_%d', $key, $alreadyRefunded->toMinorUnit(), $failedRefundCount);
    }

    private function getStripeAccountData(StripePaymentDomainObject $payment): array
    {
        if ($this->config->get('app.saas_mode_enabled')) {
            if ($payment->getConnectedAccountId() === null) {
                throw new RuntimeException(
                    __('Cannot Refund: Stripe connect account not found and saas_mode_enabled is enabled')
                );
            }

            return [
                'stripe_account' => $payment->getConnectedAccountId(),
            ];
        }

        return [];
    }
}
