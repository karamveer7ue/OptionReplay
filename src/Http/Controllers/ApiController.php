<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Settings;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Market\MarketData;
use App\Market\TradingCalendar;
use App\Pricing\BlackScholes;

final class ApiController
{
    public function underlyings(): Response
    {
        return Response::json([
            'underlyings' => MarketData::underlyings(),
            'settings' => [
                'risk_free_rate' => Settings::float('risk_free_rate'),
                'charges' => [
                    'brokerage_per_order' => Settings::float('brokerage_per_order'),
                    'stt_sell_pct' => Settings::float('stt_sell_pct'),
                    'exchange_txn_pct' => Settings::float('exchange_txn_pct'),
                    'sebi_fee_pct' => Settings::float('sebi_fee_pct'),
                    'stamp_duty_buy_pct' => Settings::float('stamp_duty_buy_pct'),
                    'gst_pct' => Settings::float('gst_pct'),
                ],
                'site_name' => Settings::get('site_name'),
            ],
        ]);
    }

    /** Market state at an instant: spot, future, expiries, normalised time. */
    public function snapshot(): Response
    {
        $u = $this->underlying();
        $t = TradingCalendar::normalise(Request::time('at'));
        $spot = MarketData::spot((int) $u['id'], $t);
        $expiries = MarketData::expiries((int) $u['id'], $t);
        return Response::json([
            'at' => $t->format('Y-m-d\TH:i'),
            'is_trading_day' => TradingCalendar::isTradingDay($t),
            'spot' => $spot,
            'future' => MarketData::future($u, $t, $spot['ltp']),
            'expiries' => $expiries,
            'moves' => MarketData::expectedMoves($u, $t, $spot['ltp'], $expiries),
            'range' => MarketData::dataRange((int) $u['id']),
            'lot_size' => (int) $u['lot_size'],
            'strike_step' => (float) $u['strike_step'],
        ]);
    }

    public function chain(): Response
    {
        $u = $this->underlying();
        $t = TradingCalendar::normalise(Request::time('at'));
        $expiry = Request::requireQuery('expiry');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
            throw new HttpException('Invalid expiry', 400);
        }
        $spot = MarketData::spot((int) $u['id'], $t);
        $chain = MarketData::chain($u, $expiry, $t, $spot['ltp']);
        return Response::json(['at' => $t->format('Y-m-d\TH:i'), 'expiry' => $expiry, 'spot' => $spot['ltp'], ...$chain]);
    }

    /** Quotes + Greeks for arbitrary instrument ids (strategy legs across expiries). */
    public function quotes(): Response
    {
        $u = $this->underlying();
        $t = TradingCalendar::normalise(Request::time('at'));
        $ids = Request::intList('ids');
        if (count($ids) > 40) {
            throw new HttpException('Too many instruments', 400);
        }
        $spot = MarketData::spot((int) $u['id'], $t)['ltp'];
        $quotes = MarketData::quotesAt($ids, $t);
        $insts = $ids ? Database::all('SELECT id, kind, strike, expiry, lot_size, trading_symbol FROM instruments WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) : [];
        $r = Settings::float('risk_free_rate');
        $out = [];
        foreach ($insts as $i) {
            $id = (int) $i['id'];
            $q = $quotes[$id] ?? null;
            $row = ['id' => $id, 'kind' => $i['kind'], 'strike' => (float) $i['strike'], 'expiry' => $i['expiry'], 'symbol' => $i['trading_symbol'], 'lot_size' => (int) $i['lot_size'],
                'ltp' => $q['ltp'] ?? null, 'stale' => $q['stale'] ?? true, 'expired' => $i['expiry'] < $t->format('Y-m-d'), 'iv' => null, 'delta' => null, 'gamma' => null, 'theta' => null, 'vega' => null];
            if ($row['expired'] && $spot !== null && $i['kind'] !== 'FUT') {
                // Settle at intrinsic value on the expiry day close.
                $settle = MarketData::spot((int) $u['id'], new \DateTimeImmutable($i['expiry'] . ' 15:30', new \DateTimeZone('Asia/Kolkata')))['ltp'] ?? $spot;
                $row['ltp'] = round(max(0.0, $i['kind'] === 'CE' ? $settle - (float) $i['strike'] : (float) $i['strike'] - $settle), 2);
                $row['settled'] = true;
            } elseif ($q && $spot && $i['kind'] !== 'FUT') {
                $tte = TradingCalendar::yearsToExpiry($t, $i['expiry']);
                $iv = BlackScholes::impliedVol($i['kind'], $q['ltp'], $spot, (float) $i['strike'], $tte, $r);
                if ($iv !== null) {
                    $g = BlackScholes::greeks($i['kind'], $spot, (float) $i['strike'], $tte, $r, $iv);
                    $row['iv'] = round($iv * 100, 2);
                    $row['delta'] = round($g['delta'], 3);
                    $row['gamma'] = round($g['gamma'], 5);
                    $row['theta'] = round($g['theta'], 2);
                    $row['vega'] = round($g['vega'], 2);
                }
            }
            $out[] = $row;
        }
        return Response::json(['at' => $t->format('Y-m-d\TH:i'), 'spot' => $spot, 'quotes' => $out]);
    }

    /** Price paths for legs and spot between two instants, for the P&L-over-time chart. */
    public function series(): Response
    {
        $u = $this->underlying();
        $from = TradingCalendar::normalise(Request::time('from'));
        $to = TradingCalendar::normalise(Request::time('to'));
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $ids = Request::intList('ids');
        $span = $to->getTimestamp() - $from->getTimestamp();
        $step = $span <= 5 * 86400 ? 5 : ($span <= 30 * 86400 ? 15 : 60);
        return Response::json([
            'step_minutes' => $step,
            'legs' => MarketData::closeSeries($ids, $from, $to, $step),
            'spot' => array_map(fn ($c) => [date('Y-m-d H:i:s', $c['time']), $c['close']], MarketData::spotSeries((int) $u['id'], $from, $to, $step === 5 ? '5m' : ($step === 15 ? '15m' : '1h'))),
        ]);
    }

    public function spotSeries(): Response
    {
        $u = $this->underlying();
        $from = Request::time('from');
        $to = Request::time('to');
        $interval = Request::query('interval', '5m') ?? '5m';
        if (!in_array($interval, ['1m', '5m', '15m', '1h', '1d'], true)) {
            throw new HttpException('Invalid interval', 400);
        }
        return Response::json(['candles' => MarketData::spotSeries((int) $u['id'], $from, $to, $interval)]);
    }

    public function step(): Response
    {
        $t = Request::time('at');
        $step = Request::requireQuery('step');
        try {
            $next = TradingCalendar::step($t, $step);
        } catch (\InvalidArgumentException $e) {
            throw new HttpException($e->getMessage(), 400);
        }
        return Response::json(['at' => $next->format('Y-m-d\TH:i')]);
    }

    /** @return array<string,mixed> */
    private function underlying(): array
    {
        $u = MarketData::underlying(Request::requireQuery('u'));
        if ($u === null) {
            throw new HttpException('Unknown underlying', 404);
        }
        return $u;
    }
}
