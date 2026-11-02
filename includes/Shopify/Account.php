<?php
namespace WD29\Bridge\Shopify;
require_once __DIR__ . '/../I18n.php';

/** Read-only Shopify app proxy. The signed customer subject is the only account selector. */
final class Account
{
    public static function subject(string $query,array $config,?int $now=null):?string
    {
        if(strlen($query)>8192)throw new Failure('proxy_authentication_failed');$params=[];
        foreach(explode('&',$query)as$pair){if($pair==='')continue;$parts=explode('=',$pair,2);$key=urldecode($parts[0]);$value=urldecode($parts[1]??'');
            if($key==='' || strlen($key)>128 || strlen($value)>2048 || preg_match('/[\x00-\x1f\x7f]/',$key.$value))throw new Failure('proxy_authentication_failed');$params[$key][]=$value;
        }
        if(count($params)>64)throw new Failure('proxy_authentication_failed');
        foreach(['shop','timestamp','logged_in_customer_id','path_prefix','signature']as$key){if(count($params[$key]??[])!==1)throw new Failure('proxy_authentication_failed');}
        $signature=$params['signature'][0];unset($params['signature']);$sorted=[];foreach($params as$key=>$values){$sorted[]=$key.'='.implode(',',$values);}sort($sorted,SORT_STRING);
        $secret=(string)($config['client_secret']??'');
        if(strlen($secret)<16 || !preg_match('/^[a-f0-9]{64}$/D',$signature) || !hash_equals(hash_hmac('sha256',implode('',$sorted),$secret),$signature)
            || $params['shop'][0]!==Client::shop($config['shop']??'') || $params['path_prefix'][0]!==($config['account_proxy_path']??'/apps/inklura-benefits')
            || !preg_match('/^[0-9]{10}$/D',$params['timestamp'][0]) || abs(($now??time())-(int)$params['timestamp'][0])>300)throw new Failure('proxy_authentication_failed');
        $subject=$params['logged_in_customer_id'][0];if($subject!=='' && !preg_match('/^[1-9][0-9]{0,24}$/D',$subject))throw new Failure('proxy_authentication_failed');return $subject===''?null:Client::gid('Customer',$subject);
    }
    public static function read(string $remote,Repository $repo,?callable $reader=null):array
    {
        $map=$repo->map('customer',Client::gid('Customer',$remote));if(!$map)throw new Failure('proxy_customer_not_linked');$id=(int)$map['local_id'];
        (new Woo())->customer($id);
        if(get_user_meta($id,'_inklura_shopify_gid',true)!==$remote || get_user_meta($id,'_wd29_bridge_customer_disabled',true))throw new Failure('proxy_customer_not_linked');
        $e=\wd29_bridge();
        if($e->mode()!=='live' || empty($e->config()['native_customers']) || !$e->licence()->allowsLive() || !class_exists('Inklura\\CommercePro\\RewardsAccount'))throw new Failure('proxy_account_unavailable');
        $key=\WD29\Bridge\OrderCustomers::source($e,$id);if(!$key)throw new Failure('proxy_customer_not_linked');
        if(\WD29\Bridge\OrderCustomers::resolve($e,$key)!==$id)throw new Failure('proxy_customer_not_linked');
        return \Inklura\CommercePro\RewardsAccount::read($key,$reader??static function($key)use($e){return $e->peer(['op'=>'commerce_account','customer_key'=>$key],15);});
    }
    public static function response($request)
    {
        $config=Admin::config();$status=200;
        if(empty($config['account_proxy']) || ($config['mode']??'')!=='live'){$status=404;$html=\WD29\Bridge\I18n::t('<p>Page unavailable.</p>');}
        else{
            try{
                $remote=self::subject((string)($_SERVER['QUERY_STRING']??''),$config);
                if($remote===null){$html=\WD29\Bridge\I18n::t('<p>Sign in to your Shopify customer account to see your benefits.</p>');}
                else{$account=self::read($remote,Admin::repo());$html=\Inklura\CommercePro\RewardsAccount::html($account).\WD29\Bridge\I18n::t('<p>These benefits can be redeemed in KerAwen POS and sites connected to the same program. Redemption at Shopify checkout is not enabled.</p>');}
            }catch(Failure $error){$status=$error->reason==='proxy_authentication_failed'?403:503;$html=\WD29\Bridge\I18n::t('<p>Your benefits are temporarily unavailable. Please try again later.</p>');}
            catch(\Throwable $error){$status=503;$html=\WD29\Bridge\I18n::t('<p>Your benefits are temporarily unavailable. Please try again later.</p>');}
        }
        return new \WP_REST_Response(\WD29\Bridge\I18n::t('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Your benefits</title></head><body><main><h1>Your benefits</h1>').$html.'</main></body></html>',$status,
            ['Content-Type'=>'text/html; charset=utf-8','Cache-Control'=>'private, no-store, max-age=0','Surrogate-Control'=>'no-store','Vary'=>'Cookie','X-Robots-Tag'=>'noindex, nofollow','Referrer-Policy'=>'no-referrer','X-Content-Type-Options'=>'nosniff']);
    }
    public static function serve($served,$result,$request,$server)
    {
        if($request->get_route()!=='/inklura-sync/v1/shopify/account')return $served;
        if(is_string($result->get_data())){echo $result->get_data();return true;}return $served;
    }
}
