<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Settings;
use App\Http\Response;
use App\Http\View;
use App\Market\MarketData;

final class SimulatorController
{
    public function index(): Response
    {
        return Response::html(View::render('simulator', [
            'siteName' => Settings::get('site_name'),
            'underlyings' => MarketData::underlyings(),
            'defaultUnderlying' => Settings::get('default_underlying'),
        ]));
    }
}
