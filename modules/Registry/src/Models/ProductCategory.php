<?php

namespace Modules\Registry\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A node of the product category tree (P1-22a). `path` lists the ancestors' IDs ("/12/45/"), `depth` counts them.
 * Categories are not classified: every product.view holder may see the tree. No mass assignment.
 *
 * @property int $id
 * @property ?int $parent_id
 * @property string $name
 * @property string $path
 * @property int $depth
 */
class ProductCategory extends Model
{
    /** Six levels: a top-level category has depth 0. */
    public const MAX_DEPTH = 5;

    protected $guarded = ['*'];

    /** The path its children get. */
    public function childPath(): string
    {
        return $this->path.$this->id.'/';
    }
}
