<?php

namespace Leaf\Billing;

use Leaf\Billing;
use Stripe\StripeClient;

/**
 * Leaf Billing Stripe
 * -----------
 * Stripe provider for Leaf Billing
 */
class Stripe
{
    use Billing;

    /**
     * Stripe product
     */
    protected string $product;

    /**
     * Stripe plans/tiers
     */
    protected $tiers = [];

    protected function initProvider($billingSettings = [])
    {
        if ($billingSettings['provider']) {
            \Stripe\Stripe::setAppInfo('Leaf Billing', '0.0.1');
            \Stripe\Stripe::setMaxNetworkRetries(3);

            $config = [
                'api_key' => $billingSettings['secrets.apiKey'],
            ];

            if (isset($billingSettings['secrets.clientId'])) {
                $config['client_id'] = $billingSettings['secrets.clientId'];
            }

            if (isset($billingSettings['provider.version'])) {
                $config['stripe_version'] = $billingSettings['provider.version'];
            }

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
                $this->initTiers($billingSettings);

                storage()->createFile(StoragePath('billing/stripe.json'), json_encode([
                    'product' => $this->product,
                    'tiers' => $this->tiers,
                ]), ['recursive' => true]);
            }
        }

        $this->config($billingSettings);
    }

    protected function initTiers(array $billingSettings)
    {
        foreach ($billingSettings['tiers'] as $tier) {
            $plan = [
                'currency' => $billingSettings['currency.name'],
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
     * Open payment link for item
     */
    public function link(string $item)
    {
        // $item = $this->tiers[$item];

        $session = $this->provider->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'line_items' => [
                [
                    'price' => $item,
                    'quantity' => 1,
                ],
            ],
            'mode' => 'subscription',
            'success_url' => (request()->getUrl() . $this->config['url.success'] . '?session_id={CHECKOUT_SESSION_ID}' . '&item=' . $item . (auth()->user() ? '&user=' . auth()->id() : '')),
            'cancel_url' => (request()->getUrl() . $this->config['url.cancel']),
        ]);

        // $provider->checkout->sessions->create([
        //     'success_url' => 'https://example.com/success',
        //     'cancel_url' => 'https://example.com/cancel',
        //     'payment_method_types' => ['card'],
        //     'line_items' => [
        //         [
        //             'price' => $tier['price'],
        //             'quantity' => 1,
        //         ]
        //     ],
        //     'mode' => 'subscription',
        // ]);

        return $session->url;
    }

    /**
     * Verify payment
     */
    public function isSuccess()
    {
        return ($this->provider->checkout->sessions->retrieve(
            request()->get('session_id')
        ))->payment_status === 'paid';
    }
}
