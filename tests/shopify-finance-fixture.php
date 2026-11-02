<?php
namespace Inklura\CommercePro;
/** Only the authority transport is emulated; export checks execute native Woo product code. */
final class WooPlugin
{
    public static $gift=false,$unavailable=false,$calls=[];
    public function config():array{return ['gift_cards'=>['enabled'=>true]];}
    public function financeRequest(string $action,array $input):array
    {
        self::$calls[]=[$action,$input];if(self::$unavailable)throw new \RuntimeException('Fixture authority unavailable');$products=[];foreach($input['products']as$key)$products[$key]=['gift_card'=>self::$gift];return ['ok'=>true,'products'=>$products];
    }
}
