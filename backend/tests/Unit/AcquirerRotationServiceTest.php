<?php

declare(strict_types=1);

use App\Services\AcquirerRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rotates primary acquirer in round robin strategy', function () {
    /** @var AcquirerRotationService $service */
    $service = app(AcquirerRotationService::class);

    $service->updateSettings([
        'enabled' => true,
        'methods' => [
            'pix' => [
                'strategy' => 'round_robin',
                'weights' => [],
            ],
        ],
    ]);

    $candidates = ['payfort', 'blupay', 'enki'];

    expect($service->choosePrimaryAcquirer('pix', $candidates))->toBe('payfort');
    expect($service->choosePrimaryAcquirer('pix', $candidates))->toBe('blupay');
    expect($service->choosePrimaryAcquirer('pix', $candidates))->toBe('enki');
});

it('prioritizes higher weights when weighted strategy is enabled', function () {
    /** @var AcquirerRotationService $service */
    $service = app(AcquirerRotationService::class);

    $service->updateSettings([
        'enabled' => true,
        'methods' => [
            'pix' => [
                'strategy' => 'weighted',
                'weights' => [
                    'enki' => 10,
                    'payfort' => 5,
                    'blupay' => 1,
                ],
            ],
        ],
    ]);

    $order = $service->getOrderedAcquirers('pix', ['payfort', 'blupay', 'enki']);

    expect($order[0])->toBe('enki');
    expect($order[1])->toBe('payfort');
    expect($order[2])->toBe('blupay');
});
