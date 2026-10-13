<?php
declare(strict_types=1);

/** Minimal Modbus RTU master (function 3/4 reads) for RS-485 indicators/transmitters. */
final class Modbus
{
    public static function crc16(string $data): int
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]);
            for ($b = 0; $b < 8; $b++) { $crc = ($crc & 1) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1); }
        }
        return $crc;
    }

    public static function request(int $slave, int $func, int $addr, int $count): string
    {
        $f = pack('CCnn', $slave, $func, $addr, $count);
        return $f . pack('v', self::crc16($f));   // CRC is little-endian on the wire
    }

    /** Function 5: write one coil. Relay boards/PLCs echo the request as the reply. */
    public static function writeCoil(int $slave, int $addr, bool $on): string
    {
        $f = pack('CCnn', $slave, 5, $addr, $on ? 0xFF00 : 0x0000);
        return $f . pack('v', self::crc16($f));
    }

    /** Modbus TCP (MBAP header, no CRC) write-single-coil frame. */
    public static function writeCoilTcp(int $tid, int $unit, int $addr, bool $on): string
    {
        return pack('nnnCCnn', $tid, 0, 6, $unit, 5, $addr, $on ? 0xFF00 : 0x0000);
    }

    /** Returns register values (unsigned 16-bit) or throws. */
    public static function parse(string $resp, int $slave, int $func, int $count): array
    {
        $need = 5 + 2 * $count;
        if (strlen($resp) < $need) { throw new RuntimeException('Modbus: short/no response (' . strlen($resp) . ' of ' . $need . ' bytes) - check wiring A/B, baud, slave id'); }
        $resp = substr($resp, 0, $need);
        $crc = unpack('v', substr($resp, -2))[1];
        if ($crc !== self::crc16(substr($resp, 0, -2))) { throw new RuntimeException('Modbus: CRC error'); }
        if (ord($resp[0]) !== $slave) { throw new RuntimeException('Modbus: reply from unexpected slave ' . ord($resp[0])); }
        if (ord($resp[1]) === ($func | 0x80)) { throw new RuntimeException('Modbus exception code ' . ord($resp[2])); }
        if (ord($resp[1]) !== $func || ord($resp[2]) !== 2 * $count) { throw new RuntimeException('Modbus: malformed reply'); }
        return array_values(unpack('n' . $count, substr($resp, 3, 2 * $count)));
    }

    public static function toNumber(array $regs, bool $signed, string $wordOrder): float
    {
        if (count($regs) === 1) {
            $v = $regs[0];
            return (float)($signed && $v > 0x7FFF ? $v - 0x10000 : $v);
        }
        [$hi, $lo] = $wordOrder === 'little' ? [$regs[1], $regs[0]] : [$regs[0], $regs[1]];
        $v = ($hi << 16) | $lo;
        return (float)($signed && $v > 0x7FFFFFFF ? $v - 0x100000000 : $v);
    }
}
