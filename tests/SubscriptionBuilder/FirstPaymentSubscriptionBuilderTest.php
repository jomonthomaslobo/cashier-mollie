<?php

namespace Laravel\Cashier\Tests\SubscriptionBuilder;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Events\FirstPaymentPaid;
use Laravel\Cashier\Events\OrderProcessed;
use Laravel\Cashier\FirstPayment\Actions\AddGenericOrderItem;
use Laravel\Cashier\FirstPayment\Actions\StartSubscription;
use Laravel\Cashier\SubscriptionBuilder\FirstPaymentSubscriptionBuilder;
use Laravel\Cashier\Tests\BaseTestCase;

class FirstPaymentSubscriptionBuilderTest extends BaseTestCase
{
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withTestNow('2019-01-01');
        $this->withPackageMigrations();
        $this->withConfiguredPlans();
        $this->user = $this->getCustomerUser(true, ['tax_percentage' => 20]);
    }

    /** @test */
    public function createsMandatePaymentForSubscription()
    {
        config(['cashier.locale' => 'nl_NL']);

        $builder = $this->getBuilder()
            ->nextPaymentAt(now()->addDays(12))
            ->trialDays(5);

        $response = $builder->create();

        $this->assertInstanceOf(RedirectResponse::class, $response);

        $payload = $builder->getMandatePaymentBuilder()->getMolliePayload();

        $this->assertEquals([
            "sequenceType" => "first",
            "method" => "ideal",
            "customerId" => $this->user->mollie_customer_id,
            "description" => config('app.name'),
            "amount" => [
                "value" => "0.06",
                "currency" => "EUR",
            ],
            "webhookUrl" => config('cashier.first_payment.webhook_url'),
            "redirectUrl" => config('app.url'),
            "locale" => "nl_NL",
            "metadata" => [
                "owner" => [
                    "type" => get_class($this->user),
                    "id" => 1,
                ],
                "actions" => [
                    [
                        "handler" => StartSubscription::class,
                        "description" => "Monthly payment",
                        "subtotal" => [
                            "value" => "0.00",
                            "currency" => "EUR",
                        ],
                        "taxPercentage" => 20,
                        "plan" => "monthly-10-1",
                        "name" => "default",
                        "quantity" => 1,
                        "nextPaymentAt" => now()->addDays(12)->toIso8601String(),
                        "trialUntil" => now()->addDays(5)->toIso8601String(),
                    ],
                    [
                        "handler" => AddGenericOrderItem::class,
                        "description" => "Test mandate payment",
                        "subtotal" => [
                            "value" => "0.05",
                            "currency" => "EUR",
                        ],
                        "taxPercentage" => 20,
                    ],
                ],
            ],
        ], $payload);

        // For creating a new paid first payment, use:
        // dd(
        //     $builder->getMandatePaymentBuilder()->getMolliePayment()->getCheckoutUrl(), // visit this Mollie checkout url and set status to 'paid'
        //     $builder->getMandatePaymentBuilder()->getMolliePayment()->id // store this in phpunit.xml: SUBSCRIPTION_MANDATE_PAYMENT_PAID_ID
        // );
    }

    /** @test */
    public function handlesAPaidMandatePayment()
    {
        $this->withoutExceptionHandling();

        Event::fake();

        $this->assertFalse($this->user->subscribed());
        $this->assertNull($this->user->mollie_mandate_id);

        $response = $this->post(route('webhooks.mollie.first_payment', [
            'id' => $this->getSubscriptionMandatePaymentID()
        ]));

        $response->assertStatus(200);

        $this->user = $this->user->fresh();
        $this->assertTrue($this->user->subscribed());
        $this->assertTrue($this->user->onTrial());
        $this->assertNotNull($this->user->mollie_mandate_id);

        Event::assertDispatched(OrderProcessed::class);
        Event::assertDispatched(FirstPaymentPaid::class);
    }

    /**
     * @return \Laravel\Cashier\SubscriptionBuilder\FirstPaymentSubscriptionBuilder
     */
    protected function getBuilder()
    {
        return new FirstPaymentSubscriptionBuilder(
            $this->user,
            'default',
            'monthly-10-1'
        );
    }
}
