<?php
/** Shopify Admin GraphQL transport. GPL-2.0-or-later. */
namespace WD29\Bridge\Shopify;

class Failure extends \RuntimeException
{
    public $reason;
    public $retryAfter;
    public function __construct(string $reason, int $retryAfter = 0)
    {
        // Only internal codes reach the queue/UI; API messages can contain personal data.
        parent::__construct($reason); $this->reason = $reason; $this->retryAfter = $retryAfter;
    }
}

final class Client
{
    const VERSION = '2026-10';
    private $config;
    private $transport;
    private $saveToken;
    public function __construct(array $config, ?callable $transport = null, ?callable $saveToken = null) { $this->config = $config; $this->transport = $transport; $this->saveToken=$saveToken; }

    public static function shop(string $shop): string
    {
        $shop = strtolower(trim($shop));
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.myshopify\.com$/D', $shop)) { throw new Failure('invalid_shop_domain'); }
        return $shop;
    }

    public static function gid(string $type, $id): string
    {
        if (!in_array($type, ['Product','ProductVariant','ProductImage','Customer','Order','InventoryItem','Location','Collection','DiscountCodeNode','DiscountAutomaticNode','Fulfillment','Refund','OrderTransaction'], true)) { throw new Failure('invalid_resource_type'); }
        $id = (string) $id;
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $id)) { return 'gid://shopify/' . $type . '/' . $id; }
        if (!preg_match('~^gid://shopify/' . $type . '/[1-9][0-9]{0,19}$~D', $id)) { throw new Failure('invalid_resource_id'); }
        return $id;
    }

    public function query(string $query, array $variables = []): array
    {
        $shop = self::shop($this->config['shop'] ?? '');
        $token = $this->token();
        if ($token === '' || preg_match('/[\r\n]/', $token)) { throw new Failure('missing_access_token'); }
        $body = json_encode(['query'=>$query, 'variables'=>$variables ?: new \stdClass()], JSON_THROW_ON_ERROR);
        $result = $this->transport
            ? ($this->transport)('https://' . $shop . '/admin/api/' . self::VERSION . '/graphql.json', $token, $body)
            : self::http($shop, $token, $body);
        $status = (int) ($result['status'] ?? 0);
        if ($status === 429 || $status >= 500 || $status === 0) { throw new Failure('shopify_unavailable', max(5, min(3600, (int) ($result['retry_after'] ?? 60)))); }
        if ($status === 401 || $status === 403) { throw new Failure('shopify_access_denied'); }
        if ($status !== 200) { throw new Failure('shopify_http_error'); }
        try { $value = json_decode($result['body'], true, 128, JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { throw new Failure('shopify_invalid_response'); }
        if (!empty($value['errors'])) {
            foreach ($value['errors'] as $error) {
                if (($error['extensions']['code'] ?? '') === 'THROTTLED') { throw new Failure('shopify_throttled', 60); }
            }
            throw new Failure('shopify_graphql_error');
        }
        if (!is_array($value['data'] ?? null)) { throw new Failure('shopify_invalid_response'); }
        return $value['data'];
    }

    public function mutation(string $field, string $query, array $variables): array
    {
        $result = $this->query($query, $variables)[$field] ?? null;
        if (!is_array($result)) { throw new Failure('shopify_invalid_response'); }
        if (!empty($result['userErrors'])) {
            $codes = array_column($result['userErrors'], 'code');
            if (in_array('CHANGE_FROM_QUANTITY_STALE', $codes, true) || in_array('COMPARE_QUANTITY_STALE', $codes, true)) { throw new Failure('inventory_compare_conflict'); }
            throw new Failure('shopify_validation_error');
        }
        return $result;
    }

    private function token(): string
    {
        $token=(string)($this->config['access_token'] ?? '');
        if (($this->config['auth'] ?? 'token')!=='client_credentials') { return $token; }
        if ($token!=='' && (int)($this->config['token_expires_at'] ?? 0)>time()+120) { return $token; }
        $id=(string)($this->config['client_id'] ?? ''); $secret=(string)($this->config['client_secret'] ?? '');
        if (!preg_match('/^[a-fA-F0-9]{16,128}$/D',$id) || strlen($secret)<16) { throw new Failure('missing_app_credentials'); }
        $shop=self::shop($this->config['shop'] ?? '');
        $body=json_encode(['grant_type'=>'client_credentials','client_id'=>$id,'client_secret'=>$secret],JSON_THROW_ON_ERROR);
        $result=$this->transport?($this->transport)('https://'.$shop.'/admin/oauth/access_token','',$body):self::http($shop,'',$body,'/admin/oauth/access_token');
        $value=json_decode($result['body'] ?? '',true);
        if (($result['status'] ?? 0)!==200 || empty($value['access_token']) || (int)($value['expires_in'] ?? 0)<300) { throw new Failure('client_credentials_grant_failed'); }
        $this->config['access_token']=(string)$value['access_token']; $this->config['token_expires_at']=time()+(int)$value['expires_in'];
        if ($this->saveToken) { ($this->saveToken)($this->config['access_token'],$this->config['token_expires_at']); }
        return $this->config['access_token'];
    }

    private static function http(string $shop, string $token, string $body, string $path = ''): array
    {
        $ips = gethostbynamel($shop) ?: [];
        if (!$ips) { throw new Failure('shopify_dns_unavailable', 60); }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { throw new Failure('shopify_nonpublic_address'); }
        }
        $response = ''; $retry = 0; $apiVersion = '';
        $curl = curl_init('https://' . $shop . ($path==='/admin/oauth/access_token'?$path:'/admin/api/' . self::VERSION . '/graphql.json'));
        $headers=['Content-Type: application/json']; if ($token!=='') { $headers[]='X-Shopify-Access-Token: '.$token; }
        curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>20, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_RESOLVE=>[$shop . ':443:' . $ips[0]],
            CURLOPT_HEADERFUNCTION=>static function ($handle, $line) use (&$retry, &$apiVersion) {
                if (stripos($line, 'Retry-After:') === 0) { $retry = (int) trim(substr($line, 12)); }
                if (stripos($line, 'X-Shopify-API-Version:') === 0) { $apiVersion = trim(substr($line, strlen('X-Shopify-API-Version:'))); }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>static function ($handle, $chunk) use (&$response) {
                if (strlen($response) + strlen($chunk) > 4194304) { return 0; }
                $response .= $chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); unset($curl);
        if ($ok === false) { throw new Failure('shopify_transport_error', 60); }
        if ($apiVersion !== '' && $apiVersion !== self::VERSION) { throw new Failure('shopify_api_version_mismatch'); }
        return ['status'=>$status, 'body'=>$response, 'retry_after'=>$retry];
    }
}
