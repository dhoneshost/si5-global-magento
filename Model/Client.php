<?php
/**
 * SI5 Global - API client (same rules as the PrestaShop, OpenCart and WooCommerce modules).
 *
 * Only the barcode and the store token go to the API, with a fixed user agent (no store address).
 * Pictures are downloaded only from SI5 Global hosts, without redirects and with a size limit.
 *
 * @license OSL-3.0
 */
declare(strict_types=1);

namespace Si5\GlobalCatalog\Model;

use Magento\Framework\Exception\LocalizedException;

class Client
{
    public const VERSION = '1.0.1';
    private const API = 'https://global.si5.com.br/api/produtos/';
    private const IMAGE_HOSTS = ['global.si5.com.br', 'cdn2.si5.com.br', 'cdn3.si5.com.br'];
    private const JSON_LIMIT = 262144;
    private const IMAGE_LIMIT = 5242880;

    /**
     * Normalises and validates a GTIN (EAN-8, UPC-A, EAN-13, GTIN-14). Restricted-circulation prefixes
     * (20-29, 040-049, 200-299 in EAN-13) are not looked up.
     */
    public static function gtin($value): ?string
    {
        $code = preg_replace('/\s+/', '', (string) $value);
        if (!preg_match('/^(\d{8}|\d{12,14})$/', $code) || trim($code, '0') === '') {
            return null;
        }
        $unit = strlen($code) === 14 ? substr($code, 1) : (strlen($code) === 12 ? '0' . $code : $code);
        $prefix = (int) substr($unit, 0, 3);
        if (strlen($unit) === 13 && (($prefix >= 20 && $prefix <= 29) || ($prefix >= 40 && $prefix <= 49) || ($prefix >= 200 && $prefix <= 299))) {
            return null;
        }
        $sum = 0;
        $digits = strrev(substr($code, 0, -1));
        for ($i = 0, $n = strlen($digits); $i < $n; $i++) {
            $sum += (int) $digits[$i] * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) substr($code, -1) ? $code : null;
    }

    /**
     * @return array{gtin:string,nome:string,marca:string,categoria:string,peso_gramas:?float,imagem_url:string,ncm:string,ncm_descricao:string,cest:string}
     * @throws LocalizedException with a friendly message (never the token or the URL)
     */
    public function lookup(string $token, string $barcode): array
    {
        $gtin = self::gtin($barcode);
        if ($gtin === null) {
            throw new LocalizedException(__('Invalid or restricted-circulation barcode.'));
        }
        $token = trim($token);
        if ($token === '' || !preg_match('/^[!-~]{1,4096}$/', $token)) {
            throw new LocalizedException(__('Set the SI5 Global API token in Stores › Configuration › SI5 › SI5 Global.'));
        }
        // the catalogue stores UPC-A (12 digits) as EAN-13 with a leading zero
        $query = strlen($gtin) === 12 ? '0' . $gtin : $gtin;
        [$status, $type, $body] = $this->http(self::API . $query, ['Accept: application/json', 'Authorization: Bearer ' . $token], self::JSON_LIMIT);

        if ($status === 404) {
            throw new LocalizedException(__('Product not found in the SI5 Global catalogue.'));
        }
        if ($status === 401) {
            throw new LocalizedException(__('Invalid or expired SI5 Global token.'));
        }
        if ($status === 429) {
            throw new LocalizedException(__('Plan lookup limit reached. Try again later.'));
        }
        if ($status !== 200 || stripos($type, 'application/json') !== 0) {
            throw new LocalizedException(__('SI5 Global did not answer as expected. Try again.'));
        }
        $d = json_decode($body, true, 32);
        if (!is_array($d) || !isset($d['codbar']) || str_pad((string) $d['codbar'], 14, '0', STR_PAD_LEFT) !== str_pad($gtin, 14, '0', STR_PAD_LEFT)) {
            throw new LocalizedException(__('Invalid answer from SI5 Global.'));
        }
        $text = static function (string $key) use ($d): string {
            return isset($d[$key]) && is_string($d[$key]) ? trim(mb_substr($d[$key], 0, 2048)) : '';
        };
        $weight = isset($d['peso']) && is_numeric($d['peso']) && (float) $d['peso'] > 0 && (float) $d['peso'] < 100000000 ? (float) $d['peso'] : null;
        $name = $text('produto');
        if ($name === '') {
            throw new LocalizedException(__('Invalid answer from SI5 Global.'));
        }

        return [
            'gtin' => $gtin,
            'nome' => $name,
            'marca' => $text('marca'),
            'categoria' => $text('categoria'),
            'peso_gramas' => $weight,
            'imagem_url' => $text('imagem_url'),
            'ncm' => (string) preg_replace('/\D/', '', $text('ncm')),
            'ncm_descricao' => $text('ncm_descricao'),
            'cest' => (string) preg_replace('/\D/', '', $text('cest_codigo')),
        ];
    }

    /**
     * Downloads the picture (SI5 Global hosts only).
     *
     * @return array{0:string,1:string} [bytes, extension]
     * @throws LocalizedException
     */
    public function downloadImage(string $url): array
    {
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || !in_array(strtolower($p['host'] ?? ''), self::IMAGE_HOSTS, true)
            || isset($p['port']) || isset($p['user']) || isset($p['query']) || !preg_match('#^/img/[0-9/]+\.(webp|png|jpe?g)$#', $p['path'] ?? '')) {
            throw new LocalizedException(__('Picture address not allowed.'));
        }
        [$status, $type, $body] = $this->http($url, ['Accept: image/*'], self::IMAGE_LIMIT);
        $mime = strtolower(trim(explode(';', $type)[0]));
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if ($status !== 200 || !isset($ext[$mime])) {
            throw new LocalizedException(__('Could not download the picture.'));
        }
        $signatureOk = ($mime === 'image/png' && strpos($body, "\x89PNG\r\n\x1a\n") === 0)
            || ($mime === 'image/jpeg' && strpos($body, "\xff\xd8\xff") === 0)
            || ($mime === 'image/webp' && substr($body, 0, 4) === 'RIFF' && substr($body, 8, 4) === 'WEBP');
        if (!$signatureOk) {
            throw new LocalizedException(__('Picture format not allowed.'));
        }

        return [$body, $ext[$mime]];
    }

    /** @return array{0:int,1:string,2:string} */
    private function http(string $url, array $headers, int $limit): array
    {
        if (!function_exists('curl_init')) {
            throw new LocalizedException(__('The PHP cURL extension is required.'));
        }
        $body = '';
        $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'SI5Global-Magento/' . self::VERSION,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $part) use (&$body, $limit, &$tooBig): int {
                $body .= $part;
                if (strlen($body) > $limit) {
                    $tooBig = true;

                    return 0;
                }

                return strlen($part);
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_errno($ch);
        curl_close($ch);
        if ($tooBig) {
            throw new LocalizedException(__('Answer larger than allowed.'));
        }
        if ($error) {
            throw new LocalizedException(__('Network failure while contacting SI5 Global. Try again.'));
        }

        return [$status, $type, $body];
    }
}
