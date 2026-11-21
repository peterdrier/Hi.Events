<?php

namespace Tests\Feature\Http\Actions\Orders;

use HiEvents\DomainObjects\Enums\EventType;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Feature\Support\InsertsRecurringEventRows;
use Tests\TestCase;

class AnonymizeCheckoutOrderActionTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsRecurringEventRows;

    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->insertAccountAndOrganizer();
        $this->eventId = $this->insertEvent(EventType::SINGLE->name);
        DB::table('event_settings')->insert(['event_id' => $this->eventId, 'created_at' => now(), 'updated_at' => now()]);
        $this->insertOccurrence();
        $productId = $this->insertProduct();
        $this->insertPrice($productId, null, price: 225.00);

        $this->orderId = $this->postJson("/events/{$this->eventId}/orders/checkout", [
            'external_reference' => 'humans-order-1',
            'first_name' => 'Ana',
            'last_name' => 'Buyer',
            'email' => 'ana@example.test',
            'locale' => 'es',
            'expires_at' => '2030-01-01T12:00:00Z',
            'items' => [
                ['product_id' => $productId, 'price' => 225, 'attendee' => ['first_name' => 'Ana García López']],
                ['product_id' => $productId, 'price' => 225, 'attendee' => ['first_name' => 'Luis Pérez']],
            ],
        ], $this->authHeaders())->assertCreated()->json('data.id');
    }

    public function test_it_scrubs_the_buyer_and_every_attendee_and_keeps_the_amounts(): void
    {
        $this->postJson($this->url($this->orderId), [], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Anonymized');

        $order = DB::table('orders')->find($this->orderId);
        $this->assertSame('Anonymized', $order->first_name);
        $this->assertSame('Anonymized', $order->last_name);
        $this->assertStringEndsWith('@anonymized.invalid', $order->email);
        $this->assertEquals(450.0, (float) $order->total_gross);

        $attendees = DB::table('attendees')->where('order_id', $this->orderId)->get();
        $this->assertCount(2, $attendees);
        foreach ($attendees as $attendee) {
            $this->assertSame('Anonymized', $attendee->first_name);
            $this->assertStringEndsWith('@anonymized.invalid', $attendee->email);
        }
    }

    public function test_no_trace_of_the_buyer_is_left_in_stripe_customers_webhook_logs_or_deleted_attendees(): void
    {
        DB::table('attendees')->where('order_id', $this->orderId)->limit(1)->update(['deleted_at' => now()]);
        DB::table('stripe_customers')->insert([
            'name' => 'Ana Buyer', 'email' => 'ana@example.test', 'stripe_customer_id' => 'cus_test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $webhookId = DB::table('webhooks')->insertGetId([
            'url' => 'https://humans.example.test/HiEvents/Webhook', 'event_types' => '[]', 'secret' => 'whsec',
            'user_id' => $this->userId, 'account_id' => $this->accountId, 'event_id' => $this->eventId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderPayload = json_encode(['event_type' => 'order.created', 'payload' => ['id' => $this->orderId, 'email' => 'ana@example.test']]);
        $attendeePayload = json_encode(['event_type' => 'attendee.created', 'payload' => ['id' => 9, 'order_id' => $this->orderId, 'first_name' => 'Luis Pérez']]);
        $otherPayload = json_encode(['event_type' => 'order.created', 'payload' => ['id' => $this->orderId + 1000]]);
        foreach ([$orderPayload, $attendeePayload, $otherPayload] as $payload) {
            DB::table('webhook_logs')->insert([
                'webhook_id' => $webhookId, 'payload' => $payload, 'event_type' => 'ORDER_CREATED',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->postJson($this->url($this->orderId), [], $this->authHeaders())->assertOk();

        foreach (['Ana', 'Buyer', 'ana@example.test', 'García', 'Pérez'] as $value) {
            $this->assertSame(0, DB::table('orders')->where('id', $this->orderId)
                ->where(fn ($q) => $q->where('first_name', 'like', "%$value%")->orWhere('last_name', 'like', "%$value%")->orWhere('email', 'like', "%$value%"))
                ->count(), $value);
            $this->assertSame(0, DB::table('attendees')->where('order_id', $this->orderId)
                ->where(fn ($q) => $q->where('first_name', 'like', "%$value%")->orWhere('last_name', 'like', "%$value%")->orWhere('email', 'like', "%$value%"))
                ->count(), $value);
            $this->assertSame(0, DB::table('stripe_customers')->where('name', 'like', "%$value%")->orWhere('email', 'like', "%$value%")->count(), $value);
            $this->assertSame(0, DB::table('webhook_logs')->where('payload', 'like', "%$value%")->count(), $value);
        }
        $this->assertSame(1, DB::table('webhook_logs')->where('webhook_id', $webhookId)->whereNull('deleted_at')->count(), 'Logs of other orders stay');
    }

    public function test_an_order_not_created_through_the_checkout_endpoint_is_refused(): void
    {
        DB::table('orders')->where('id', $this->orderId)->update(['external_reference' => null]);

        $this->postJson($this->url($this->orderId), [], $this->authHeaders())->assertStatus(409);

        $this->assertSame('Ana', DB::table('orders')->find($this->orderId)->first_name);
    }

    public function test_repeating_it_is_harmless(): void
    {
        $this->postJson($this->url($this->orderId), [], $this->authHeaders())->assertOk();
        $this->postJson($this->url($this->orderId), [], $this->authHeaders())->assertOk();

        $this->assertSame('Anonymized', DB::table('orders')->find($this->orderId)->first_name);
    }

    public function test_an_order_of_another_event_is_not_found_and_left_alone(): void
    {
        $otherEventId = $this->insertEvent(EventType::SINGLE->name);

        $this->postJson("/events/{$otherEventId}/orders/{$this->orderId}/anonymize", [], $this->authHeaders())
            ->assertNotFound();

        $this->assertSame('Ana', DB::table('orders')->find($this->orderId)->first_name);
    }

    private function url(int $orderId): string
    {
        return "/events/{$this->eventId}/orders/{$orderId}/anonymize";
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::claims(['account_id' => $this->accountId])->fromUser(User::find($this->userId))];
    }
}
