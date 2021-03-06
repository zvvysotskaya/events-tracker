<?php namespace App;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model as Eloquent;

class ReviewType extends Eloquent {

    /**
     * @var Array
     *
     **/
    protected $fillable = [
        'name'
    ];

    /**
     * Additional fields to treat as Carbon instances.
     *
     * @var array
     */
    protected $dates = [];


    /**
     * A review type can belong to many event reviews
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function eventReviews()
    {
        return $this->belongsTo('App\EventReview');
    }


}
