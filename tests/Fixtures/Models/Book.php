<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    public const CREATED_AT = 'added_on';

    public const UPDATED_AT = 'changed_on';

    protected $table = 'legacy_books';

    protected $primaryKey = 'book_no';

    protected $guarded = [];

    public function shelf()
    {
        return $this->belongsTo(Shelf::class, 'shelf_code', 'code');
    }

    public function authors()
    {
        return $this->belongsToMany(Author::class, 'legacy_book_author', 'book_ref', 'author_ref', 'isbn', 'pen_name')->withPivot('role');
    }
}
