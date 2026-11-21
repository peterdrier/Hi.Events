<?php

namespace Tests\Feature\Http\Actions\Orders;

use HiEvents\DomainObjects\Enums\EventType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Models\User;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Order\PaymentLink\OrderPaymentLinkTokenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Feature\Support\InsertsRecurringEventRows;
use Tests\TestCase;

class CreateCheckoutOrderActionTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsRecurringEventRows;

    private int $ticketProductId;

    private int $donationProductId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->insertAccountAndOrganizer();
        $this->eventId = $this->insertEvent(EventType::SINGLE->name);
        DB::table('event_settings')->insert(['event_id' => $this->eventId, 'created_at' => now(), 'updated_at' => now()]);
        $this->ticketProductId = $this->insertProduct();
        $this->insertPrice($this->ticketProductId, null, price: 300.00);
        $this->donationProductId = $this->insertProduct(ProductType::GENERAL->name);
        $this->insertPrice($this->donationProductId, null, price: 1.00);
    }

    public function test_it_creates_a_reserved_order_at_the_given_prices_with_names_and_a_payment_link(): void
    {
        $response = $this->postJson($this->url(), $this->body(), $this->authHeaders())
            ->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::RESERVED->name)
            ->assertJsonPath('data.payment_status', OrderPaymentStatus::AWAITING_PAYMENT->name)
            ->assertJsonPath('data.external_reference', 'humans-order-1')
            ->assertJsonPath('data.email', 'ana@example.test');

        $this->assertEquals(1000.0, $response->json('data.total_gross'));

        $orderId = $response->json('data.id');
        $order = app(OrderRepositoryInterface::class)->findById($orderId);
        $this->assertSame(app(OrderPaymentLinkTokenService::class)->urlFor($order), $response->json('data.payment_link_url'));
        $this->assertSame('2030-01-01 12:00:00', $order->getReservedUntil());

        $this->assertEqualsCanonicalizing(
            [225.0, 775.0],
            DB::table('order_items')->where('order_id', $orderId)->pluck('price')->map(fn ($price) => (float) $price)->all(),
        );

        $attendees = DB::table('attendees')->where('order_id', $orderId)->get();
        $this->assertCount(1, $attendees);
        $this->assertSame('Ana García López', $attendees[0]->first_name);
        $this->assertSame('ana@example.test', $attendees[0]->email);
        $this->assertSame(AttendeeStatus::AWAITING_PAYMENT->name, $attendees[0]->status);
    }

    public function test_a_repeat_with_the_same_reference_returns_the_same_order(): void
    {
        $first = $this->postJson($this->url(), $this->body(), $this->authHeaders())->assertCreated();
        $second = $this->postJson($this->url(), $this->body(), $this->authHeaders())->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, DB::table('orders')->where('external_reference', 'humans-order-1')->count());
    }

    public function test_a_repeat_with_the_same_reference_and_another_total_conflicts(): void
    {
        $this->postJson($this->url(), $this->body(), $this->authHeaders())->assertCreated();

        $body = $this->body();
        $body['items'][0]['price'] = 250;

        $this->postJson($this->url(), $body, $this->authHeaders())->assertStatus(409);
    }

    public function test_a_product_with_taxes_is_refused_because_the_total_would_differ(): void
    {
        $taxId = DB::table('taxes_and_fees')->insertGetId([
            'name' => 'VAT',
            'calculation_type' => 'PERCENTAGE',
            'rate' => 10,
            'type' => 'TAX',
            'account_id' => $this->accountId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_taxes_and_fees')->insert(['product_id' => $this->ticketProductId, 'tax_and_fee_id' => $taxId]);

        $this->postJson($this->url(), $this->body(), $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertSame(0, DB::table('orders')->where('external_reference', 'humans-order-1')->count());
    }

    public function test_a_product_from_another_event_is_refused(): void
    {
        $otherEventId = $this->insertEvent(EventType::SINGLE->name);
        $otherProductId = DB::table('products')->insertGetId([
            'title' => 'Other',
            'event_id' => $otherEventId,
            'product_type' => ProductType::TICKET->name,
            'type' => 'PAID',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertPrice($otherProductId, null);

        $body = $this->body();
        $body['items'][0]['product_id'] = $otherProductId;

        $this->postJson($this->url(), $body, $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.product_price_id');
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson($this->url(), $this->body())->assertUnauthorized();
    }

    private function url(): string
    {
        return "/events/{$this->eventId}/orders/checkout";
    }

    private function body(): array
    {
        return [
            'external_reference' => 'humans-order-1',
            'first_name' => 'Ana Buyer',
            'email' => 'ana@example.test',
            'locale' => 'es',
            'expires_at' => '2030-01-01T12:00:00Z',
            'items' => [
                ['product_id' => $this->ticketProductId, 'price' => 225, 'attendee' => ['first_name' => 'Ana García López']],
                ['product_id' => $this->donationProductId, 'price' => 775],
            ],
        ];
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::claims(['account_id' => $this->accountId])->fromUser(User::find($this->userId))];
    }
}
