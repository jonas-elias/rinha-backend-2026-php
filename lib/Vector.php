<?php
declare(strict_types=1);

namespace App;

/**
 * Constrói o vetor de 14 features a partir de um payload já decodificado
 * (associative array do json_decode). Port 1:1 de rust-ext/src/vector.rs.
 *
 * Saída: float[] indexado de 0..13.
 */
final class Vector
{
    /** @var array<int,float> */
    private const MCC_RISK = [
        5411 => 0.15,
        5812 => 0.30,
        5912 => 0.20,
        5944 => 0.45,
        7801 => 0.80,
        7802 => 0.75,
        7995 => 0.85,
        4511 => 0.35,
        5311 => 0.25,
        5999 => 0.50,
    ];

    private const T_DOW = [0, 3, 2, 5, 0, 3, 5, 1, 4, 6, 2, 4];

    /**
     * @param array<string,mixed> $p Payload já no formato dict.
     * @return array<int,float>      14 floats [0..13].
     */
    public static function vectorize(array $p): array
    {
        $tx = $p['transaction'];
        $cu = $p['customer'];
        $me = $p['merchant'];
        $te = $p['terminal'];

        $amount = (float) $tx['amount'];
        $installments = (int) $tx['installments'];
        $reqAt = (string) $tx['requested_at'];

        // ISO 8601: "YYYY-MM-DDTHH:MM:SSZ"
        $reqY = (int) substr($reqAt, 0, 4);
        $reqMo = (int) substr($reqAt, 5, 2);
        $reqD = (int) substr($reqAt, 8, 2);
        $reqH = (int) substr($reqAt, 11, 2);
        $reqMin = (int) substr($reqAt, 14, 2);

        $custAvg = (float) $cu['avg_amount'];
        $txCount = (int) $cu['tx_count_24h'];
        $knownMerchants = $cu['known_merchants'] ?? [];

        $merchantId = (string) $me['id'];
        $mcc = (int) $me['mcc'];
        $merchantAvg = (float) $me['avg_amount'];

        $isOnline = (bool) $te['is_online'];
        $cardPresent = (bool) $te['card_present'];
        $kmHome = (float) $te['km_from_home'];

        $last = $p['last_transaction'] ?? null;
        $hasLast = is_array($last);

        $minutesSinceLast = 0;
        $kmFromCurrent = 0.0;
        if ($hasLast) {
            $lastAt = (string) ($last['timestamp'] ?? $last['requested_at'] ?? '');
            $lY = (int) substr($lastAt, 0, 4);
            $lMo = (int) substr($lastAt, 5, 2);
            $lD = (int) substr($lastAt, 8, 2);
            $lH = (int) substr($lastAt, 11, 2);
            $lMin = (int) substr($lastAt, 14, 2);
            $kmFromCurrent = (float) $last['km_from_current'];
            $minutesSinceLast = self::minutesBetween(
                $lY, $lMo, $lD, $lH, $lMin,
                $reqY, $reqMo, $reqD, $reqH, $reqMin,
            );
        }

        $isUnknownMerchant = true;
        foreach ($knownMerchants as $km) {
            if ($km === $merchantId) {
                $isUnknownMerchant = false;
                break;
            }
        }

        $v = [];

        $v[0] = self::clamp01($amount / 10000.0);
        $v[1] = self::clamp01($installments / 12.0);

        $ratio = $custAvg > 0.0 ? ($amount / $custAvg) / 10.0 : 1.0;
        $v[2] = self::clamp01($ratio);

        $v[3] = self::round4($reqH / 23.0);
        $v[4] = self::round4(self::dayOfWeek($reqY, $reqMo, $reqD) / 6.0);

        if ($hasLast) {
            $v[5] = self::clamp01($minutesSinceLast / 1440.0);
            $v[6] = self::clamp01($kmFromCurrent / 1000.0);
        } else {
            $v[5] = -1.0;
            $v[6] = -1.0;
        }

        $v[7] = self::clamp01($kmHome / 1000.0);
        $v[8] = self::clamp01($txCount / 20.0);
        $v[9] = $isOnline ? 1.0 : 0.0;
        $v[10] = $cardPresent ? 1.0 : 0.0;
        $v[11] = $isUnknownMerchant ? 1.0 : 0.0;
        $v[12] = self::MCC_RISK[$mcc] ?? 0.50;
        $v[13] = self::clamp01($merchantAvg / 10000.0);

        return $v;
    }

    private static function round4(float $x): float
    {
        return round($x * 10000.0) * 0.0001;
    }

    private static function clamp01(float $v): float
    {
        if ($v < 0.0) {
            $v = 0.0;
        } elseif ($v > 1.0) {
            $v = 1.0;
        }
        return self::round4($v);
    }

    private static function dayOfWeek(int $y, int $m, int $d): int
    {
        $ya = $m < 3 ? $y - 1 : $y;
        $dow = ($ya + intdiv($ya, 4) - intdiv($ya, 100) + intdiv($ya, 400)
            + self::T_DOW[$m - 1] + $d) % 7;
        return ($dow + 6) % 7;
    }

    private static function daysSinceEpoch(int $y, int $m, int $d): int
    {
        $y2 = $m <= 2 ? $y - 1 : $y;
        $era = $y2 >= 0 ? intdiv($y2, 400) : intdiv($y2 - 399, 400);
        $yoe = $y2 - $era * 400;
        $mm = $m > 2 ? $m - 3 : $m + 9;
        $doy = intdiv(153 * $mm + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        return $era * 146097 + $doe - 719468;
    }

    private static function minutesBetween(
        int $y1, int $mo1, int $d1, int $h1, int $mi1,
        int $y2, int $mo2, int $d2, int $h2, int $mi2,
    ): int {
        $da1 = self::daysSinceEpoch($y1, $mo1, $d1);
        $da2 = self::daysSinceEpoch($y2, $mo2, $d2);
        $m1 = $da1 * 1440 + $h1 * 60 + $mi1;
        $m2 = $da2 * 1440 + $h2 * 60 + $mi2;
        $diff = $m2 - $m1;
        return $diff < 0 ? 0 : $diff;
    }
}
