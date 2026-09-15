# Recoverable gallery synchronization

The settings panel provides **Restore detached gallery images**. Enter a mapped product or variation key from the synchronization journal. Restoration uses the worker lock and a database transaction, saves the recovered associations, and captures the parent product for synchronization. No attachment or image file is deleted.

Option `sync_gallery_removals` defaults to false. The helper never deletes attachments. With removals disabled, incoming images are added and previous images remain. With it enabled, only images tagged with `_wd29_bridge_source` are detached when absent from the incoming image set. Manually uploaded, untagged images remain.

Integrate after importing URLs into attachment IDs:

```php
Gallery::apply($p, $images, !empty($this->config()['sync_gallery_removals']));
$p->save();
```

Use the same call for variations, replacing the direct set_image_id/_wd29_variant_image_ids overwrite. Execute even for an explicitly empty `images` array; omit execution when images field itself is omitted. The variation call uses its own image ID rather than the inherited parent thumbnail.

Detached IDs are retained in `_wd29_gallery_detached`. `Gallery::restore($product)` reattaches still-existing archived attachments; caller saves the product afterward. Reintroducing an image in source also restores the association. This archive does not recover files independently deleted by an administrator. Restore may select a previously detached image as primary; it does not preserve a complete historical ordering.

Private import markers prove an image was imported by the bridge, not that it was created exclusively for one product. The helper detaches only the current product association and never removes the attachment itself or its other usages. Tests create attachment records locally without network downloads.
