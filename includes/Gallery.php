<?php
namespace WD29\Bridge;
/** Reversible gallery associations only; original attachments are never deleted. */
final class Gallery
{
    public static function apply($product,array $desired,bool $remove=false): void
    {
        $desired=array_values(array_unique(array_filter(array_map('intval',$desired))));
        foreach ($desired as $id) { if (get_post_type($id)!=='attachment') { throw new \RuntimeException('Gallery attachment missing.'); } }
        $variant=$product->is_type('variation');
        $current=array_values(array_unique(array_filter(array_merge([(int)$product->get_image_id('edit')],$variant?(array)$product->get_meta('_wd29_variant_image_ids'):$product->get_gallery_image_ids('edit')))));
        $current=array_values(array_unique(array_filter(array_map('intval',$current))));
        $keep=[]; $removed=[];
        foreach ($current as $id) {
            $owned=preg_match('/^[a-f0-9]{64}$/D',(string)get_post_meta($id,'_wd29_bridge_source',true));
            if ($remove && $owned && !in_array($id,$desired,true)) { $removed[]=$id; } else { $keep[]=$id; }
        }
        $ids=array_values(array_unique(array_merge($desired,$keep)));
        $archive=array_values(array_unique(array_merge((array)$product->get_meta('_wd29_gallery_detached'),$removed)));
        $archive=array_values(array_diff(array_filter(array_map('intval',$archive)),$ids));
        $product->update_meta_data('_wd29_gallery_detached',$archive);
        $product->set_image_id($ids[0]??0);
        if ($variant) { $product->update_meta_data('_wd29_variant_image_ids',$ids); }
        else { $product->set_gallery_image_ids(array_slice($ids,1)); }
    }
    public static function restore($product): void
    {
        $ids=array_values(array_filter((array)$product->get_meta('_wd29_gallery_detached'),function($id){return get_post_type((int)$id)==='attachment';}));
        self::apply($product,$ids,false);
    }
}
