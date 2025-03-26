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
                'name' => 'Leaf Billing ' . _env('APP_NAME', '') . ' ' . time(),
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
                'currency' => $this->config['connection']['currency.name'],
                'product' => $this->product,
                'nickname' => $tier['name'],
            ];

            if ($tier['price'] ?? null) {
                $stripePlan = $this->provider->prices->create(array_merge($plan, [
                    'unit_amount' => $tier['price'] * 100,
                ]));

                $this->tiers[$stripePlan->id] = (new Tier($stripePlan->id, $tier))->toArray();
            } else {
                if ($tier['price.daily'] ?? null) {
                    $stripePlan = $this->provider->prices->create(array_merge($plan, [
                        'unit_amount' => $tier['price.daily'] * 100,
                        'recurring' => ['interval' => 'day'],
                    ]));

                    $this->tiers[$stripePlan->id] = (new Tier($stripePlan->id, array_merge($tier, ['type' => 'daily'])))->toArray();
                }

                if ($tier['price.weekly'] ?? null) {
                    $stripePlan = $this->provider->prices->create(array_merge($plan, [
                        'unit_amount' => $tier['price.weekly'] * 100,
                        'recurring' => ['interval' => 'week'],
                    ]));

                    $this->tiers[$stripePlan->id] = (new Tier($stripePlan->id, array_merge($tier, ['type' => 'weekly'])))->toArray();
                }

                if ($tier['price.monthly'] ?? null) {
                    $stripePlan = $this->provider->prices->create(array_merge($plan, [
                        'unit_amount' => $tier['price.monthly'] * 100,
                        'recurring' => ['interval' => 'month'],
                    ]));

                    $this->tiers[$stripePlan->id] = (new Tier($stripePlan->id, array_merge($tier, ['type' => 'monthly'])))->toArray();
                }

                if ($tier['price.yearly'] ?? null) {
                    $stripePlan = $this->provider->prices->create(array_merge($plan, [
                        'unit_amount' => $tier['price.yearly'] * 100,
                        'recurring' => ['interval' => 'year'],
                    ]));

                    $this->tiers[$stripePlan->id] = (new Tier($stripePlan->id, array_merge($tier, ['type' => 'yearly'])))->toArray();
                }
            }
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
                    'currency' => $data['currency'],
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
            $this->provider->checkout->sessions->create([
                'payment_method_types' => ['card'],
                'line_items' => $line_items,
                'mode' => \Stripe\Checkout\Session::MODE_PAYMENT,
                'metadata' => $data['metadata'] ?? [],
                'customer_email' => $data['customer'] ?? null,
                'success_url' => $data['urls']['success'] ?? (request()->getUrl() . $this->config['urls']['success'] . '?session_id={CHECKOUT_SESSION_ID}'),
                'cancel_url' => $data['urls']['cancel'] ?? (request()->getUrl() . $this->config['urls']['cancel'] . '?session_id={CHECKOUT_SESSION_ID}'),
            ])
        );
    }

    /**
     * @inheritDoc
     */
    public function subscribe(array $data): Session
    {
        return new Session([]);
    }

    /**
     * @inheritDoc
     */
    public function subscription(string $id): ?Subscription
    {
        return new Subscription([]);
    }

    /**
     * @inheritDoc
     */
    public function subscriptions(): array
    {
        return [];
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
        return new Event([]);
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
    public function tiers(): array
    {
        return $this->tiers;
    }

    /**
     * @inheritDoc
     */
    public function periods(): array
    {
        return $this->config['periods'];
    }

    /**
     * @inheritDoc
     */
    public function tiersByPeriod($period = null): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function providerName(): string
    {
        return 'Stripe';
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
        return [];
    }
}
