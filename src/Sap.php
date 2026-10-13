<?php
declare(strict_types=1);

/**
 * SAP over HTTP: send a document as JSON to an endpoint you expose (SAP Gateway/OData service, ICF handler,
 * SAP Integration Suite / API Management), and read purchase orders back for validation.
 * Auth: basic or bearer token. Optional SAP Gateway CSRF handshake ("X-CSRF-Token: Fetch" then POST with the token + cookies).
 * There is no RFC/BAPI here on purpose (that needs SAP's proprietary SDK); put a BAPI/RFC behind an HTTP service.
 */
final class Sap
{
    public static function configured(): bool { return trim((string)Settings::get('sap_url')) !== ''; }

    private static function url(string $u): string
    {
        $c = trim((string)Settings::get('sap_client'));
        if ($c !== '' && !str_contains($u, 'sap-client=')) { $u .= (str_contains($u, '?') ? '&' : '?') . 'sap-client=' . rawurlencode($c); }
        if (!preg_match('#^https?://#i', $u)) { throw new RuntimeException('The SAP URL must start with https:// (or http://).'); }
        return $u;
    }

    /** @param resource|\CurlHandle $ch */
    private static function apply($ch, array $extraHeaders): void
    {
        $h = array_merge(['Accept: application/json'], $extraHeaders);
        $auth = (string)Settings::get('sap_auth', 'basic');
        if ($auth === 'basic') { curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC); curl_setopt($ch, CURLOPT_USERPWD, Settings::get('sap_user') . ':' . Settings::get('sap_pass')); }
        elseif ($auth === 'bearer') { $h[] = 'Authorization: Bearer ' . Settings::get('sap_token'); }
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => max(3, (int)Settings::get('sap_timeout', '15')),
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
    }

    /** One HTTP exchange on a shared handle (cookies kept in memory so the CSRF token stays valid). */
    private static function call($ch, string $method, string $url, ?string $body, array $headers, ?string &$csrf = null): array
    {
        self::apply($ch, $headers);
        $respHeaders = [];
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
        if ($body === null) { curl_setopt($ch, CURLOPT_POSTFIELDS, null); }
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $line) use (&$respHeaders) {
            if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $respHeaders[strtolower(trim($k))] = trim($v); }
            return strlen($line);
        });
        $out = curl_exec($ch);
        if ($out === false) { throw new RuntimeException('SAP unreachable: ' . curl_error($ch)); }
        if ($csrf !== null && isset($respHeaders['x-csrf-token']) && strtolower($respHeaders['x-csrf-token']) !== 'required') { $csrf = $respHeaders['x-csrf-token']; }
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), (string)$out, $respHeaders];
    }

    private static function errorText(int $code, string $body): string
    {
        $j = json_decode($body, true);
        $m = is_array($j) ? ($j['error']['message']['value'] ?? $j['error']['message'] ?? $j['message'] ?? $j['error_description'] ?? null) : null;
        $hint = match (true) { $code === 401 => 'login refused (user / password / token)', $code === 403 => 'not authorised (or CSRF token rejected)', $code === 404 => 'endpoint not found', $code >= 500 => 'SAP server error', default => 'rejected' };
        return "HTTP $code $hint" . ($m ? ': ' . mb_substr(is_string($m) ? $m : json_encode($m), 0, 250) : ($body !== '' ? ': ' . mb_substr(trim(strip_tags($body)), 0, 160) : ''));
    }

    private static function path(array $j, string $path): mixed
    {
        foreach (explode('.', $path) as $k) { if (!is_array($j) || !array_key_exists($k, $j)) { return null; } $j = $j[$k]; }
        return $j;
    }

    /** @return array{ok: bool, ref: ?string, message: string} */
    public static function send(array $payload): array
    {
        $url = self::url((string)Settings::get('sap_url'));
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_COOKIEFILE, '');
        try {
            $csrf = null;
            if (Settings::get('sap_csrf') === '1') {
                $csrf = '';
                [$c0] = self::call($ch, 'GET', $url, null, ['X-CSRF-Token: Fetch'], $csrf);
                if ($c0 === 401 || $c0 === 403) { return ['ok' => false, 'ref' => null, 'message' => 'SAP login refused while fetching the CSRF token (HTTP ' . $c0 . ').']; }
            }
            $h = ['Content-Type: application/json'];
            if ($csrf) { $h[] = 'X-CSRF-Token: ' . $csrf; }
            [$code, $body] = self::call($ch, 'POST', $url, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $h);
        } catch (Throwable $e) { return ['ok' => false, 'ref' => null, 'message' => $e->getMessage()]; }
        finally { curl_close($ch); }
        if ($code < 200 || $code >= 300) { return ['ok' => false, 'ref' => null, 'message' => self::errorText($code, $body)]; }
        $ref = null; $j = json_decode($body, true);
        if (is_array($j)) {
            $p = trim((string)Settings::get('sap_ref_path'));
            $v = $p !== '' ? self::path($j, $p) : null;
            foreach (['documentNo', 'DocumentNumber', 'MaterialDocument', 'id', 'ref', 'reference'] as $k) { if ($v === null && isset($j[$k])) { $v = $j[$k]; } if ($v === null && isset($j['d'][$k])) { $v = $j['d'][$k]; } }
            $ref = is_scalar($v) ? mb_substr((string)$v, 0, 60) : null;
        }
        return ['ok' => true, 'ref' => $ref, 'message' => 'Accepted by SAP (HTTP ' . $code . ')' . ($ref ? ", reference $ref" : '')];
    }

    /** Connection + login + CSRF check for the Setup test button. Sends nothing to SAP. */
    public static function test(): array
    {
        try {
            $url = self::url((string)Settings::get('sap_url'));
            $ch = curl_init(); curl_setopt($ch, CURLOPT_COOKIEFILE, '');
            $csrf = Settings::get('sap_csrf') === '1' ? '' : null;
            [$code] = self::call($ch, 'GET', $url, null, $csrf !== null ? ['X-CSRF-Token: Fetch'] : [], $csrf);
            curl_close($ch);
        } catch (Throwable $e) { return ['ok' => false, 'message' => $e->getMessage()]; }
        if ($code === 401 || $code === 403) { return ['ok' => false, 'message' => "SAP answered HTTP $code: login refused - check user, password / token and client."]; }
        if ($code === 404) { return ['ok' => false, 'message' => 'SAP answered HTTP 404: the endpoint URL is wrong.']; }
        if ($code >= 500) { return ['ok' => false, 'message' => "SAP answered HTTP $code (server error)."]; }
        return ['ok' => true, 'message' => "SAP reachable, login accepted (HTTP $code)." . ($csrf ? ' CSRF token received.' : (Settings::get('sap_csrf') === '1' ? ' No CSRF token was returned (fine if the service does not need one).' : ''))];
    }

    // ---------------------------------------------------------------- purchase orders
    /** @return array{po_no: string, vendor: ?string, po_date: ?string, lines: array}|null */
    public static function fetchPo(string $po): ?array
    {
        $tpl = trim((string)Settings::get('sap_po_url'));
        if ($tpl === '') { return null; }
        $url = self::url(str_replace(['{po}', '{PO}'], rawurlencode(trim($po)), $tpl));
        $ch = curl_init();
        try { [$code, $body] = self::call($ch, 'GET', $url, null, []); } finally { curl_close($ch); }
        if ($code === 404) { return null; }
        if ($code < 200 || $code >= 300) { throw new RuntimeException('SAP PO lookup: ' . self::errorText($code, $body)); }
        $j = json_decode($body, true);
        return is_array($j) ? self::normalizePo($j, $po) : null;
    }

    private static function pick(array $a, array $keys): mixed
    {
        foreach ($keys as $k) { foreach ($a as $ak => $v) { if (is_string($ak) && strcasecmp($ak, $k) === 0 && $v !== null && $v !== '') { return $v; } } }
        return null;
    }

    /** Accepts the simple contract {po_no, vendor, lines[]}, OData v2 ({"d": {...}}) and OData v4 ({"value":[...]} / flat) purchase-order shapes. */
    public static function normalizePo(array $j, string $asked = ''): ?array
    {
        if (isset($j['d']) && is_array($j['d'])) { $j = $j['d']; }
        if (isset($j['value']) && is_array($j['value']) && isset($j['value'][0]) && is_array($j['value'][0])) { $j = $j['value'][0]; }
        $items = null;
        foreach (['lines', 'items', 'Items', 'to_PurchaseOrderItem', '_PurchaseOrderItem', 'PurchaseOrderItem', 'results'] as $k) {
            if (isset($j[$k])) { $items = $j[$k]; break; }
        }
        if (is_array($items) && isset($items['results'])) { $items = $items['results']; }
        if (!is_array($items)) { return null; }
        $lines = [];
        foreach ($items as $it) {
            if (!is_array($it)) { continue; }
            $qty = Documents::num(self::pick($it, ['qty', 'quantity', 'OrderQuantity', 'menge']));
            if ($qty === null) { continue; }
            $lines[] = [
                'line_no' => (string)(self::pick($it, ['line_no', 'lineNo', 'item', 'PurchaseOrderItem', 'ebelp']) ?? ''),
                'material_code' => (string)(self::pick($it, ['material_code', 'materialCode', 'material', 'Material', 'matnr']) ?? ''),
                'description' => (string)(self::pick($it, ['description', 'text', 'PurchaseOrderItemText', 'ShortText']) ?? ''),
                'qty' => $qty, 'uom' => (string)(self::pick($it, ['uom', 'unit', 'PurchaseOrderQuantityUnit', 'OrderQuantityUnit', 'meins']) ?? ''),
                'rate' => Documents::num(self::pick($it, ['rate', 'price', 'NetPriceAmount', 'netpr', 'unit_price'])),
            ];
        }
        return ['po_no' => (string)(self::pick($j, ['po_no', 'poNo', 'PurchaseOrder', 'ebeln']) ?? $asked),
                'vendor' => ($v = self::pick($j, ['vendor', 'supplier', 'Supplier', 'SupplierName', 'vendor_name', 'lifnr'])) !== null ? (string)$v : null,
                'po_date' => Documents::date((string)(self::pick($j, ['po_date', 'PurchaseOrderDate', 'CreationDate', 'bedat']) ?? '')), 'lines' => $lines];
    }
}
