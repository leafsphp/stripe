<?php

namespace Leaf\Billing;

use Stripe\StripeClient;

/**
 * Leaf Billing Stripe
 * -----------
 * Stripe provider for Leaf Billing
 */
class Stripe implements BillingProvider
{
    /**
     * Stripe product
     */
    protected string $product;

    /**
     * Stripe plans/tiers
     */
    protected $tiers = [];

    /**
     * Config for billing
     */
    protected $config = [];

    /**
     * Errors caught during operations
     */
    protected array $errors = [];

    /**
     * Stripe client
     * @var StripeClient
     */
    protected $provider;

    public function __construct($billingSettings = [])
    {
        \Stripe\Stripe::setAppInfo('Leaf Billing', ($_ENV['APP_VERSION'] ?? '0.1.0'));
        \Stripe\Stripe::setMaxNetworkRetries(3);

        $config = [
            'api_key' => $billingSettings['connection']['secrets.apiKey'],
        ];

        if (!$config['api_key']) {
            return;
        }

        if (isset($billingSettings['connection']['secrets.publishableKey'])) {
            $config['client_id'] = $billingSettings['connection']['secrets.publishableKey'];
        }

        if (isset($billingSettings['connection']['version'])) {
            $config['stripe_version'] = $billingSettings['connection']['version'];
        }

        $this->config = $billingSettings;
        $this->provider = new StripeClient($config);

        if (storage()->exists(StoragePath('billing/stripe.json'))) {
            $provider = storage()->read(StoragePath('billing/stripe.json'));
            $provider = json_decode($provider, true);

            $this->product = $provider['product'];
            $this->tiers = $provider['tiers'];
        } else {
            $stripeProduct = $this->provider->products->create([
                'name' => _env('APP_NAME', '') . ' ' . time(),
            ]);

            $this->product = $stripeProduct->id;
            $this->initTiers($billingSettings['tiers']);

            storage()->createFile(StoragePath('billing/stripe.json'), json_encode([
                'product' => $this->product,
                'tiers' => $this->tiers,
            ]), ['recursive' => true]);
        }
    }

    protected function initTiers(array $tierSettings)
    {
        foreach ($tierSettings as $tier) {
            $plan = [
                'currency' => $tier['currency']['name'] ?? $this->config['connection']['currency']['name'] ?? 'usd',
                'product' => $this->product,
                'nickname' => $tier['name'],
            ];

            if ($tier['price'] ?? null) {
                $stripePlan = $this->provider->prices->create(array_merge($plan, [
                    'unit_amount' => $tier['price'] * 100,
                ]));

                $tier['currency'] = $stripePlan->currency;
                $this->tiers[$stripePlan->id] = (new Tier($stripePlan->id, $tier))->toArray();
            } else {
                if ($tier['price.daily'] ?? null) {
                    $stripePlan = $this->provider->prices->create(array_merge($plan, [
                        'unit_amount' => $tier['price.daily'] * 100,
                        'recurring' => ['interval' => 'day'],
                    ]));

                    $this->tiers[$stripePlan->id] = (new Tier($stripePlan->id, array_merge($tier, [
                        'billingPeriod' => 'daily',
                        'currency' => $stripePlan->currency,
                    ])))->toArray();
                }

                if ($tier['price.weekly'] ?? null) {
                    $stripePlan = $this->provider->prices->create(array_merge($plan, [
                        'unit_amount' => $tier['price.weekly'] * 100,
                        'recurring' => ['interval' => 'week'],
                    ]));

                    $this->tiers[$stripePlan->id] = (new Tier($stripePlan->id, array_merge($tier, [
                        'billingPeriod' => 'weekly',
                        'currency' => $stripePlan->currency,
                    ])))->toArray();
                }

                if ($tier['price.monthly'] ?? null) {
                    $stripePlan = $this->provider->prices->create(array_merge($plan, [
                        'unit_amount' => $tier['price.monthly'] * 100,
                        'recurring' => ['interval' => 'month'],
                    ]));

                    $this->tiers[$stripePlan->id] = (new Tier($stripePlan->id, array_merge($tier, [
                        'billingPeriod' => 'monthly',
                        'currency' => $stripePlan->currency,
                    ])))->toArray();
                }

                if ($tier['price.yearly'] ?? null) {
                    $stripePlan = $this->provider->prices->create(array_merge($plan, [
                        'unit_amount' => $tier['price.yearly'] * 100,
                        'recurring' => ['interval' => 'year'],
                    ]));

                    $this->tiers[$stripePlan->id] = (new Tier($stripePlan->id, array_merge($tier, [
                        'billingPeriod' => 'yearly',
                        'currency' => $stripePlan->currency,
                    ])))->toArray();
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function customer(): ?\Stripe\Customer
    {
        try {
            return $this->provider->customers->retrieve(auth()->user()->billing_id);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function updateCustomer(string $customerId): void
    {
        if (auth()->user()->billing_id === $customerId) {
            return;
        }

        db()
            ->update(\Leaf\Auth\Config::get('db.table'))
            ->params(['billing_id' => $customerId])
            ->where(\Leaf\Auth\Config::get('id.key'), auth()->user()->id)
            ->execute();
    }

    /**
     * @inheritDoc
     */
    public function createCustomer(?array $data = null): bool
    {
        if ($customer = $this->customer()) {
            return true;
        }

        if (!$data) {
            $data = auth()->user();
        }

        try {
            $customer = $this->provider->customers->create([
                'name' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'metadata' => [
                    'user_id' => auth()->user()->id,
                ],
            ]);

            $this->updateCustomer($customer->id);

            return true;
        } catch (\Throwable $th) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function charge(array $data): Session
    {
        $line_items = $data['items'] ?? array_map(function ($item) use ($data) {
            return [
                'price_data' => [
                    'currency' => $item['currency'] ?? $data['currency'],
                    'product_data' => ['name' => $item['item']],
                    'unit_amount' => $item['amount'],
                ],
                'quantity' => $item['quantity'] ?? 1,
            ];
        }, $data['metadata']['items'] ?? []);

        if (!isset($data['items'])) {
            unset($data['metadata']['items']);
        }

        return new Session(
            $this->provider->checkout->sessions->create(
                array_merge([
                    'payment_method_types' => ['card'],
                    'line_items' => $line_items,
                    'mode' => \Stripe\Checkout\Session::MODE_PAYMENT,
                    'metadata' => $data['metadata'] ?? [],
                    'customer_email' => $data['customer'] ?? null,
                    'success_url' => $data['urls']['success'] ?? (request()->getUrl() . $this->config['urls']['success'] . '?session_id={CHECKOUT_SESSION_ID}'),
                    'cancel_url' => $data['urls']['cancel'] ?? (request()->getUrl() . $this->config['urls']['cancel'] . '?session_id={CHECKOUT_SESSION_ID}'),
                ], $data['_stripe'] ?? [])
            )
        );
    }

    /**
     * @inheritDoc
     */
    public function subscribe(array $data): Session
    {
        $trialEnd = null;
        $user = auth()->user();

        $tier = ($data['id'] ?? null) ? $this->tiers[$data['id']] : array_values(array_filter($this->tiers, function ($tier) use ($data) {
            return $tier['name'] === $data['name'];
        }))[0];

        if ($tier['trialDays'] ?? null) {
            // Checkout Sessions are active for 24 hours after their creation and within that time frame the customer
            // can complete the payment at any time. Stripe requires the trial end at least 48 hours in the future
            // so that there is still at least a one day trial if your customer pays at the end of the 24 hours.
            // We also add 10 seconds of extra time to account for any delay with an API request onto Stripe.
            $minimumTrialPeriod = tick()
                ->add(48, 'hours')
                ->add(10, 'seconds');

            $trialEnd = (tick()->add($tier['trialDays'], 'days')->isBefore($minimumTrialPeriod)
            ? $minimumTrialPeriod->toTimestamp()
            : tick()->add($tier['trialDays'] + 1, 'days'))->toTimestamp();
        }

        $stripeData = [
            'customer' => $user->email,
            'items' => [
                [
                    'price' => $tier['id'],
                    'quantity' => 1,
                ]
            ],
            'metadata' => [
                'tier' => $tier['name'],
                'tier_id' => $tier['id'],
                'user' => $user->id,
            ],
            'urls' => [
                'success' => $data['urls']['success'] ?? (request()->getUrl() . $this->config['urls']['success'] . '?session_id={CHECKOUT_SESSION_ID}'),
                'cancel' => $data['urls']['cancel'] ?? (request()->getUrl() . $this->config['urls']['cancel'] . '?session_id={CHECKOUT_SESSION_ID}'),
            ],
            '_stripe' => [
                'mode' => 'subscription',
                'subscription_data' => [
                    'trial_end' => $trialEnd,
                ],
            ],
        ];

        if ($data['metadata'] ?? null) {
            $stripeData['metadata'] = array_merge($stripeData['metadata'], $data['metadata']);
        }

        if (!$trialEnd) {
            unset($stripeData['_stripe']['subscription_data']['trial_end']);
        }

        $session = $this->charge($stripeData);
        $subscription = auth()->user()->subscriptions()->first();

        if (!$subscription) {
            $originalTimeStampsConfig = \Leaf\Auth\Config::get('timestamps');

            \Leaf\Auth\Config::set(['timestamps' => false]);

            $subscription = auth()->user()->subscriptions()->create([
                'name' => $tier['name'],
                'plan_id' => $tier['id'],
                'payment_session_id' => $session->id,
                'status' => Subscription::STATUS_INCOMPLETE,
                'start_date' => tick()->format('YYYY-MM-DD HH:mm:ss'),
                'end_date' => tick()->add(1, rtrim($tier['billingPeriod'], 'ly'))->format('YYYY-MM-DD HH:mm:ss'),
                'trial_ends_at' => $trialEnd ? tick()->add($tier['trialDays'] + 1, 'days')->format('YYYY-MM-DD HH:mm:ss') : null,
            ]);

            \Leaf\Auth\Config::set(['timestamps' => $originalTimeStampsConfig]);
        }

        return $session;
    }

    /**
     * @inheritDoc
     */
    public function changeSubcription(array $data): bool
    {
        $user = auth()->user();

        $tier = ($data['id'] ?? null) ? $this->tiers[$data['id']] : array_values(array_filter($this->tiers, function ($tier) use ($data) {
            return $tier['name'] === $data['name'];
        }))[0];

        $oldSubscription = $this->provider->subscriptions->retrieve($user->subscription()['subscription_id']);
        $stripeData = [
            'items' => [
                [
                    'id' => $oldSubscription->items->data[0]->id,
                    'price' => $tier['id'],
                ]
            ],
            'proration_behavior' => 'create_prorations',
        ];

        if ($data['metadata'] ?? null) {
            $stripeData['metadata'] = array_merge($stripeData['metadata'], $data['metadata']);
        }

        try {
            $this->provider->subscriptions->update($oldSubscription->id, $stripeData);
        } catch (\Throwable $th) {
            $this->errors[] = $th->getMessage();
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function cancelSubscription(string $id): bool
    {
        $user = auth()->user();

        if (!$user->subscription()) {
            return true;
        }

        try {
            $this->provider->subscriptions->cancel($id);
        } catch (\Throwable $th) {
            $this->errors[] = $th->getMessage();
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function session(string $id): ?Session
    {
        try {
            return new Session(
                $this->provider->checkout->sessions->retrieve($id)
            );
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function webhook(): Event
    {
        try {
            $event = \Stripe\Webhook::constructEvent(
                @file_get_contents('php://input'),
                $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '',
                $this->config['connection']['secrets.webhook'] ?? null
            );
        } catch (\Throwable $th) {
            response()->exit($th, 400);
        }

        return new Event([
            'type' => $event['type'],
            'data' => $event['data'],
            'id' => $event['id'],
            'created' => $event['created'],
        ]);
    }

    /**
     * @inheritDoc
     */
    public function callback(): ?Session
    {
        return $this->session(request()->get('session_id'));
    }

    /**
     * @inheritDoc
     */
    public function tiers(?string $billingPeriod = null): array
    {
        if (!$billingPeriod) {
            return $this->tiers;
        }

        return array_filter($this->tiers, function ($tier) use ($billingPeriod) {
            return $tier['billingPeriod'] === $billingPeriod;
        });
    }

    /**
     * @inheritDoc
     */
    public function tier(string $id): ?array
    {
        return $this->tiers[$id] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function periods(): array
    {
        $populatedTiers = [];

        foreach ($this->tiers as $tier) {
            $populatedTiers[] = $tier['billingPeriod'];
        }

        return array_unique($populatedTiers);
    }

    /**
     * @inheritDoc
     */
    public function providerName(): string
    {
        return 'stripe';
    }

    /**
     * @inheritDoc
     */
    public function provider(): StripeClient
    {
        return $this->provider;
    }

    /**
     * @inheritDoc
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
