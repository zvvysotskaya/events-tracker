<?php namespace App;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model as Eloquent;

class Forum extends Eloquent {


	public static function boot()
	{
		parent::boot();

		static::creating(function($event)
		{
			$event->created_by = Auth::user()->id;
			$event->updated_by =  Auth::user() ? Auth::user()->id : NULL;	
		});

		static::updating(function($event)
		{
			$event->updated_by = Auth::user() ? Auth::user()->id : NULL;			
		});
	}

    /**
     * The storage format of the model's date columns.
     *
     * @var string
     */
    //protected $dateFormat = 'Y-m-d\\TH:i';

	/**
	 * @var Array
	 *
	 **/
	protected $fillable = [
	'name', 'slug', 'short',
	'description',
	'visibility_id', 
	'event_status_id',
	'event_type_id',
	'is_benefit', 'promoter_id', 'venue_id',
	'presale_price','door_price',
	'ticket_link','primary_link',
	'series_id',
	'soundcheck_at','door_at', 'start_at', 'end_at',
	'min_age',
	
	];


	protected $dates = ['created_at','updated_at'];

	// building filter
	public function scopeFilter($query, QueryFilter $filters)
	{
		return $filters->apply($query);
	}


	public function scopeFuture($query)
	{
		$query->where('start_at','>=', Carbon::today()->startOfDay())
						->orderBy('start_at', 'asc');
	}

	public function scopePast($query)
	{
		$query->where('start_at','<', Carbon::today()->startOfDay())
						->orderBy('start_at', 'desc');
	}


	/**
	 * Returns visible events
	 *
	 */
	public function scopeVisible($query, $user)
	{

		$public = Visibility::where('name','=','Public')->first();
		
 		$query->where('visibility_id','=', $public ? $public->id : NULL )->orWhere('created_by','=',($user ? $user->id : NULL));

	}


    /**
     * Get all of the events comments
     */
    public function comments()
    {
        return $this->morphMany('App\Comment', 'commentable')->orderBy('created_at', 'DESC');
    }

	/**
	 * An event is owned by a user
	 *
	 * @ return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function user()
	{
		return $this->belongsTo('App\User','created_by');
	}

	/**
	 * An event is created by one user
	 *
	 * @ return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function creator()
	{
		return $this->belongsTo('App\User','created_by');
	}

	/**
	 * An event is created by one user
	 *
	 * @ param User $user
	 * 
	 * @ return boolean
	 */
	public function ownedBy(User $user)
	{
		return $this->created_by == $user->id;
	}

	/**
	 * An event has one status
	 *
	 */
	public function eventStatus()
	{
		return $this->hasOne('App\EventStatus','id','event_status_id');
	}

    /**
     * Get all of the events photos
     */
    public function photos()
    {
		return $this->belongsToMany('App\Photo')->withTimestamps();
    }

	/**
	 * An event has one visibility
	 *
	 */
	public function visibility()
	{
		return $this->hasOne('App\Visibility','id','visibility_id');
	}


	/**
	 * An event has one series
	 *
	 */
	public function series()
	{
		return $this->hasOne('App\Series','id','series_id');
	}
	/**
	 * The tags that belong to the event
	 *
	 * @ return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function tags()
	{
		return $this->belongsToMany('App\Tag')->withTimestamps();
	}


	/**
	 * The entities that belong to the event
	 *
	 * @ return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function entities()
	{
		return $this->belongsToMany('App\Entity')->withTimestamps();
	}

	/**
	 * A user can have many event responses
	 *
	 */
	public function eventResponses()
	{
		return $this->hasMany('App\EventResponse');
	}

	
	/**
	 * Get a list of tag ids associated with the event
	 *
	 * @ return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function getTagListAttribute()
	{
		return $this->tags->lists('id')->all();
	}

	/**
	 * Get a list of entity ids associated with the event
	 *
	 * @ return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function getEntityListAttribute()
	{
		return $this->entities->lists('id')->all();
	}


	/**
	 * Return a collection of events with the passed tag
	 * 
	 * @return Collection $events
	 * 
	 **/
	public static function getByTag($tag)
	{
		// get a list of events that have the passed tag
		$events = self::whereHas('tags', function($q) use ($tag)
		{
			$q->where('name','=', ucfirst($tag));
		});

		return $events;
	}


	/**
	 * Return a collection of events with the passed event type
	 * 
	 * @return Collection $events
	 * 
	 **/
	public static function getByType($slug)
	{
		// get a list of events that have the passed tag
		$events = self::whereHas('eventType', function($q) use ($slug)
		{
			$q->where('name','=', $slug);
		})->orderBy('name','ASC');

		return $events;
	}

	/**
	 * Return a collection of events with the passed series
	 * 
	 * @return Collection $events
	 * 
	 **/
	public static function getBySeries($slug)
	{
		// get a list of events that have the passed tag
		$events = self::whereHas('series', function($q) use ($slug)
		{
			$q->where('name','=', $slug);
		})->orderBy('name','ASC');

		return $events;
	}

	/**
	 * Return a collection of events with the passed entity
	 * 
	 * @return Collection $events
	 * 
	 **/
	public static function getByEntity($slug)
	{
		// get a list of events that have the passed entity
		$events = self::whereHas('entities', function($q) use ($slug)
		{
			$q->where('slug','=', $slug);
		});

		return $events;
	}

	/**
	 * Returns the response status for the passed user
	 * 
	 * @return Collection $events
	 * 
	 **/
	public function getEventResponse($user)
	{
		$event = $this;
		$response = EventResponse::where('event_id','=', $event->id)
		->where('user_id','=',$user->id)->first();
		// get a list of events that have the passed entity
	

		return $response;
	}



	public function addPhoto(Photo $photo)
	{
		return $this->photos()->attach($photo->id);;
	}


	/**
	 * Return the flyer for this event
	 * 
	 * @return Photo $photo
	 * 
	 **/
	public function getFlyer()
	{
		// get a list of events that start on the passed date
		$flyer = $this->photos()->first();

		return $flyer;
	}

	/**
	 * Return the primary photo for this event
	 * 
	 * @return Photo $photo
	 * 
	 **/
	public function getPrimaryPhoto()
	{
		// gets the first photo related to this event
		$primary = $this->photos()->where('photos.is_primary','=','1')->first();

		return $primary;
	}
 
	// Post model
	public function threadsCount()
	{
	  return $this->hasOne('App\Thread')
	    ->selectRaw('forum_id, count(*) as aggregate')
	    ->groupBy('forum_id');
	}
	 
	public function getThreadsCountAttribute()
	{
	  // if relation is not loaded already, let's do it first
	  if ( ! array_key_exists('threadsCount', $this->relations)) 
	    $this->load('threadsCount');
	 
	  $related = $this->getRelation('threadsCount');
	 
	  // then return the count directly
	  return ($related) ? (int) $related->aggregate : 0;
	}
}
