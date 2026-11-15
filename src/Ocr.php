<?php
declare(strict_types=1);

/**
 * Turns an invoice picture / PDF into structured data.
 *   claude    - Anthropic Messages API (vision + structured JSON output). Best quality, reads photos, PDFs and any layout.
 *               The picture is sent to the Anthropic API; use the offline provider if documents must stay on premises.
 *   tesseract - local Tesseract OCR + rule-based reader (OcrParser). Works offline; good on clean scans, weak on photos.
 * Both return: ['ok' => bool, 'error' => ?string, 'provider' => string, 'data' => array, 'raw' => string].
 */
final class Ocr
{
    public static function enabled(): bool { return Settings::get('ocr_provider', 'off') !== 'off'; }

    /** JSON Schema of the structured output requested from the model. */
    public static function schema(): array
    {
        $s = fn(string $d) => ['anyOf' => [['type' => 'string'], ['type' => 'null']], 'description' => $d];
        $n = fn(string $d) => ['anyOf' => [['type' => 'number'], ['type' => 'null']], 'description' => $d];
        $line = ['type' => 'object', 'additionalProperties' => false,
            'required' => ['description', 'material_code', 'hsn', 'qty', 'uom', 'rate', 'amount'],
            'properties' => [
                'description' => $s('Item / material description exactly as printed'), 'material_code' => $s('Item or material code if printed, else null'),
                'hsn' => $s('HSN/SAC code if printed'), 'qty' => $n('Quantity as a plain number'), 'uom' => $s('Unit of measure as printed, e.g. MT, KG, NOS'),
                'rate' => $n('Unit rate'), 'amount' => $n('Line amount before tax unless only a taxed amount is printed')]];
        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['invoice_no', 'invoice_date', 'supplier_name', 'supplier_tax_id', 'buyer_name', 'po_no', 'orig_invoice_no', 'vehicle_no', 'transporter',
                           'eway_bill_no', 'currency', 'subtotal', 'tax_amount', 'total_amount', 'lines', 'confidence', 'warnings'],
            'properties' => [
                'invoice_no' => $s('Invoice / bill / challan / return-note number of THIS document'), 'invoice_date' => $s('Document date as YYYY-MM-DD'),
                'supplier_name' => $s('Name of the party that issued the invoice, or for a material return the party the goods are returned to'),
                'supplier_tax_id' => $s('GSTIN / VAT / tax id of the supplier'), 'buyer_name' => $s('Name of the buyer / consignee'),
                'po_no' => $s('Purchase order number referenced on the document'), 'orig_invoice_no' => $s('For a return or debit note: the original invoice number it refers to'),
                'vehicle_no' => $s('Truck / vehicle registration number'), 'transporter' => $s('Transporter name'), 'eway_bill_no' => $s('E-way bill or LR / GR number'),
                'currency' => $s('ISO currency code, e.g. INR'), 'subtotal' => $n('Total before tax'), 'tax_amount' => $n('Total tax (all taxes added together)'),
                'total_amount' => $n('Final invoice total'), 'lines' => ['type' => 'array', 'items' => $line],
                'confidence' => ['type' => 'number', 'description' => 'Your overall confidence in the extraction, 0 to 1'],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Anything unreadable, cut off, ambiguous or inconsistent']]];
    }

    public static function extract(string $path, string $mime, string $docType): array
    {
        $provider = (string)Settings::get('ocr_provider', 'off');
        $res = ['ok' => false, 'error' => null, 'provider' => $provider, 'data' => [], 'raw' => ''];
        try {
            switch ($provider) {
                case 'claude': [$res['data'], $res['raw']] = self::viaClaude($path, $mime, $docType); break;
                case 'tesseract': [$res['data'], $res['raw']] = self::viaTesseract($path, $mime, $docType); break;
                default: throw new RuntimeException('OCR is switched off. An administrator can enable it in Setup > Documents.');
            }
            $res['ok'] = true;
        } catch (Throwable $e) { $res['error'] = $e->getMessage(); }
        return $res;
    }

    // ------------------------------------------------------------------ picture preparation
    /** Can GD decode a w x h picture without running the PHP process out of memory? Tries to raise memory_limit to 512M first. */
    public static function canDecode(int $w, int $h): bool
    {
        $need = (int)($w * $h * 4 * 1.8) + 8 * 1048576;      // truecolor + a working copy
        $lim = (string)ini_get('memory_limit');
        $bytes = static function (string $v): int { $v = trim($v); $n = (int)$v; return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n }; };
        if ($lim !== '-1' && $bytes($lim) < $need + memory_get_usage() && $bytes($lim) < 512 * 1048576) { @ini_set('memory_limit', '512M'); $lim = (string)ini_get('memory_limit'); }
        return $lim === '-1' || $bytes($lim) >= $need + memory_get_usage();
    }

    /** Fix phone-camera rotation, limit size, return JPEG/PNG bytes (needs ext-gd; without it the file is sent as is). */
    public static function prepareImage(string $path, string $mime, int $maxSide = 2000): array
    {
        $raw = (string)file_get_contents($path);
        $sz = @getimagesize($path);
        if (!function_exists('imagecreatefromstring') || !$sz || !self::canDecode((int)$sz[0], (int)$sz[1])) {
            if (strlen($raw) > 5 * 1048576) { throw new RuntimeException('The picture is over 5 MB and cannot be shrunk on this server (GD missing or too little memory). Take the photo at a lower resolution, or enable php-gd.'); }
            return [$raw, $mime];
        }
        $img = @imagecreatefromstring($raw);
        if (!$img) { return [$raw, $mime]; }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $ex = @exif_read_data($path);
            $rot = [3 => 180, 6 => -90, 8 => 90][$ex['Orientation'] ?? 1] ?? 0;
            if ($rot) { $r = imagerotate($img, $rot, 0); if ($r) { $img = $r; } }
        }
        $w = imagesx($img); $h = imagesy($img);
        if (max($w, $h) > $maxSide) {
            $k = $maxSide / max($w, $h);
            $n = imagescale($img, (int)round($w * $k), (int)round($h * $k), IMG_BICUBIC);
            if ($n) { $img = $n; }
        }
        ob_start(); imagejpeg($img, null, 90); $jpg = (string)ob_get_clean();
        return [$jpg, 'image/jpeg'];
    }

    // ------------------------------------------------------------------ Claude
    private static function viaClaude(string $path, string $mime, string $docType): array
    {
        $key = (string)Settings::get('ocr_claude_key', '');
        if ($key === '') { throw new RuntimeException('No Anthropic API key. Enter it in Setup > Documents.'); }
        $url = (string)Settings::get('ocr_claude_url') ?: 'https://api.anthropic.com/v1/messages';
        if (!preg_match('#^https?://#i', $url)) { throw new RuntimeException('The API URL must start with https://'); }
        $model = (string)Settings::get('ocr_claude_model') ?: 'claude-opus-5-5';

        if ($mime === 'application/pdf') {
            $block = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode((string)file_get_contents($path))]];
        } else {
            [$bytes, $mt] = self::prepareImage($path, $mime);
            $block = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mt, 'data' => base64_encode($bytes)]];
        }
        $kind = $docType === 'MATERIAL_RETURN'
            ? 'a MATERIAL RETURN document (return challan / debit note / rejection note) sent back to a supplier. "supplier_name" is the party the goods are returned TO. Put the original purchase invoice it refers to in "orig_invoice_no".'
            : 'a PURCHASE INVOICE / delivery challan from a supplier for goods received against a purchase order. "supplier_name" is the party that issued the invoice.';
        $prompt = "This is $kind\n\nRead the document and return the requested fields.\n"
            . "- Copy text exactly as printed; do not correct or guess. Use null when a field is not present or not legible.\n"
            . "- Dates as YYYY-MM-DD (Indian documents are day-first: 05/09/2026 is 5 September 2026).\n"
            . "- Numbers as plain numbers without thousands separators or currency symbols (\"1,38,225.00\" becomes 138225.00).\n"
            . "- Quantity and unit are separate fields (\"28.500 MT\": qty 28.5, uom \"MT\").\n"
            . "- tax_amount is the sum of all taxes (CGST + SGST or IGST, etc.).\n"
            . "- Include every line item, in order, and nothing that is a total or a tax line.\n"
            . "- Put anything blurred, cut off, handwritten or inconsistent (for example totals that do not add up) in \"warnings\", and lower \"confidence\" accordingly.";
        $body = [
            'model' => $model, 'max_tokens' => 16000,
            'output_config' => ['effort' => in_array(Settings::get('ocr_effort'), ['low', 'medium', 'high'], true) ? Settings::get('ocr_effort') : 'medium',
                                'format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            'messages' => [['role' => 'user', 'content' => [$block, ['type' => 'text', 'text' => $prompt]]]],
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
            CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false]);
        $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($resp === false) { throw new RuntimeException("Cannot reach the Anthropic API: $err"); }
        $j = json_decode((string)$resp, true);
        if ($code >= 400 || !is_array($j) || ($j['type'] ?? '') === 'error') {
            $msg = $j['error']['message'] ?? mb_substr((string)$resp, 0, 200);
            $hint = match (true) {
                $code === 401 => 'The API key was rejected - check it in Setup > Documents.',
                $code === 403 => 'The API key is not allowed to use this model.',
                $code === 404 => "Model '$model' was not found for this key - check the model name in Setup > Documents.",
                $code === 413 => 'The file is too large for the API - upload a smaller picture.',
                $code === 429 => 'Rate limited - wait a minute and press "Read again".',
                $code >= 500 => 'The API is busy or down (' . $code . ') - try again shortly.',
                default => "The API refused the request (HTTP $code).",
            };
            throw new RuntimeException("$hint $msg");
        }
        if (($j['stop_reason'] ?? '') === 'refusal') { throw new RuntimeException('The model declined to read this document. Enter the details by hand.'); }
        if (($j['stop_reason'] ?? '') === 'max_tokens') { throw new RuntimeException('The document is too long to read in one go. Split it into fewer pages.'); }
        $text = '';
        foreach ($j['content'] ?? [] as $b) { if (($b['type'] ?? '') === 'text') { $text .= $b['text']; } }
        $data = json_decode($text, true);
        if (!is_array($data)) { throw new RuntimeException('The model returned an unreadable answer. Press "Read again", or enter the details by hand.'); }
        $data['lines'] = is_array($data['lines'] ?? null) ? $data['lines'] : [];
        $data['warnings'] = is_array($data['warnings'] ?? null) ? array_map('strval', $data['warnings']) : [];
        $data['confidence'] = isset($data['confidence']) ? max(0.0, min(1.0, (float)$data['confidence'])) : null;
        return [$data, $text];
    }

    // ------------------------------------------------------------------ Tesseract
    private static function viaTesseract(string $path, string $mime, string $docType): array
    {
        $cmd = trim((string)Settings::get('ocr_tesseract_cmd'));
        if ($cmd === '') { throw new RuntimeException('The OCR command is empty (Setup > Documents).'); }
        $tmpFiles = [];
        try {
            $images = [];
            if ($mime === 'application/pdf') {
                $bin = trim((string)@shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where pdftoppm 2>NUL' : 'command -v pdftoppm 2>/dev/null'));
                if ($bin === '') { throw new RuntimeException('PDF files need the "pdftoppm" tool (poppler) for offline OCR, or use the Claude provider. Upload a JPEG/PNG instead.'); }
                $prefix = tempnam(sys_get_temp_dir(), 'wbpdf'); @unlink($prefix);
                $p = proc_open([strtok($bin, "\r\n"), '-r', '200', '-l', '3', '-png', $path, $prefix], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
                if (is_resource($p)) { stream_get_contents($pp[1]); stream_get_contents($pp[2]); proc_close($p); }
                $images = glob($prefix . '*.png') ?: []; $tmpFiles = $images;
                if (!$images) { throw new RuntimeException('Could not convert the PDF to pictures.'); }
            } else {
                $images = [self::forOcr($path, $mime, $tmpFiles)];
            }
            $text = '';
            foreach ($images as $img) { $text .= self::runOcr($cmd, $img) . "\n"; }
        } finally { foreach ($tmpFiles as $f) { @unlink($f); } }
        if (strlen(trim($text)) < 20) { throw new RuntimeException('No text could be read from the picture. Try a sharper, straight-on picture, or enter the details by hand.'); }
        return [OcrParser::parse($text, $docType), $text];
    }

    /**
     * Prepare a picture for Tesseract: fix phone rotation, enlarge small pictures to >= 1800 px wide, grayscale, save lossless PNG.
     * (Measured on clean, small, JPEG-compressed and blurred versions of test invoices: no contrast tweak and no JPEG round trip - both lost characters.)
     */
    private static function forOcr(string $path, string $mime, array &$tmp): string
    {
        $sz = @getimagesize($path);
        if (!function_exists('imagecreatefromstring') || !$sz || !self::canDecode((int)$sz[0], (int)$sz[1])) { return $path; }   // read it as it is
        $img = @imagecreatefromstring((string)file_get_contents($path));
        if (!$img) { return $path; }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $rot = [3 => 180, 6 => -90, 8 => 90][@exif_read_data($path)['Orientation'] ?? 1] ?? 0;
            if ($rot) { $r = imagerotate($img, $rot, 0); if ($r) { $img = $r; } }
        }
        $w = imagesx($img); $h = imagesy($img);
        if ($w < 1800) { $n = imagescale($img, 1800, (int)round($h * 1800 / $w), IMG_BICUBIC); if ($n) { $img = $n; } }
        elseif (max($w, $h) > 4000) { $k = 4000 / max($w, $h); $n = imagescale($img, (int)round($w * $k), (int)round($h * $k), IMG_BICUBIC); if ($n) { $img = $n; } }
        imagefilter($img, IMG_FILTER_GRAYSCALE);
        $base = tempnam(sys_get_temp_dir(), 'wbocr'); $out = $base . '.png';
        imagepng($img, $out); @unlink($base);
        $tmp[] = $out;
        return $out;
    }

    private static function runOcr(string $cmd, string $image): string
    {
        $argv = preg_split('/\s+/', $cmd) ?: [];
        $argv = array_map(fn($a) => str_replace('{image}', $image, $a), $argv);
        if (!str_contains($cmd, '{image}')) { $argv[] = $image; }
        $p = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) { throw new RuntimeException('Cannot start the OCR program. Is Tesseract installed? (' . $argv[0] . ')'); }
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out = ''; $err = ''; $end = microtime(true) + 90;
        while (microtime(true) < $end) {
            $out .= (string)stream_get_contents($pipes[1]); $err .= (string)stream_get_contents($pipes[2]);
            if (!proc_get_status($p)['running']) { $out .= (string)stream_get_contents($pipes[1]); $err .= (string)stream_get_contents($pipes[2]); break; }
            usleep(50000);
        }
        if (proc_get_status($p)['running']) { proc_terminate($p); throw new RuntimeException('The OCR program took too long.'); }
        $code = proc_close($p);
        if ($out === '' && $code !== 0) { throw new RuntimeException('The OCR program failed: ' . mb_substr(trim($err), 0, 200)); }
        return $out;
    }
}
