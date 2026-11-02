<?php

namespace Modules\Registry\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A tag (P1-23b): a name within a taxonomy (a lower-case code such as `segment`), unclassified reference data
 * maintained by taxonomy managers. Linked to records through `taggables`; so far only competitors are tagged. A tag in
 * use is not removed. No mass assignment.
 *
 * @property int $id
 * @property string $taxonomy
 * @property string $name
 */
class Tag extends Model
{
    /** The only taggable type so far (taggables_type_check). */
    public const COMPETITOR = 'competitor';

    protected $guarded = ['*'];

    /** Whether any record carries this tag, read under a shared lock inside the caller's transaction. */
    public function inUse(): bool
    {
        return $this->getConnection()->table('taggables')->where('tag_id', $this->id)->sharedLock()->exists();
    }
}
