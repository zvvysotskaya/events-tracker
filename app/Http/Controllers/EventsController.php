<?php namespace App\Http\Controllers;

use App\Filters\EventFilters;
use App\Http\Requests\EventRequest;
use App\OccurrenceDay;
use App\OccurrenceType;
use App\OccurrenceWeek;
use App\Thread;
use App\Traits\Followable;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use Illuminate\Http\Request;
use Carbon\Carbon;

use SammyK;

use DB;
use Log;
use Mail;
use App\Event;
use App\Entity;
use App\EventType;
use App\Series;
use App\Tag;
use App\Visibility;
use App\Photo;
use App\EventResponse;
use App\User;
use App\Activity;
use App\Services\RssFeed;


class EventsController extends Controller
{
    protected $prefix;
    protected $rpp;
    protected $gridRpp;
    protected $page;
    protected $sort;
    protected $sortBy;
    protected $sortOrder;
    protected $defaultCriteria;
    protected $filters;
    protected $hasFilter;
    protected $event;
    protected $criteria;

    public function __construct (Event $event)
    {
        $this->middleware('auth', ['only' => array('create', 'edit', 'store', 'update', 'indexAttending', 'calendarAttending')]);
        $this->event = $event;

        // prefix for session storage
        $this->prefix = 'app.events.';

        // default list variables
        $this->rpp = 8;
        $this->gridRpp = 24;
        $this->page = 1;
        $this->sort = array('name', 'desc');
        $this->sortBy = 'name';
        $this->sortOrder = 'asc';
        $this->defaultCriteria = NULL;
        $this->hasFilter = 0;
        parent::__construct();
    }

    /**
     * Gets the reporting options from the request and saves to session
     *
     * @param Request $request
     */
    public function getReportingOptions (Request $request)
    {
        foreach (array('page', 'rpp', 'sort', 'criteria') as $option) {
            if (!$request->has($option)) {
                continue;
            }
            if ($option === 'sort') {
                $value = array
                (
                    $request->input($option),
                    $request->input('sort_order', 'asc'),
                );
            } else {
                $value = $request->input($option);
            }
            $this->{sprintf('set%s', ucwords($option))}($value);
        }
    }

    /**
     * Criteria provides a way to define criteria to be applied to a tab on the index page.
     *
     * @return array
     */
    public function getCriteria (): array
    {
        return $this->criteria;
    }

    /**
     * Set criteria.
     *
     * @param array $input
     * @return string
     */
    public function setCriteria ($input)
    {
        $this->criteria = $input;
        return $this->criteria;
    }

    /**
     * Get the current page for this module
     *
     * @return integer
     */
    public function getPage ()
    {
        return $this->getAttribute('page', 1);
    }

    /**
     * Set page attribute
     *
     * @param integer $input
     * @return integer
     */
    public function setPage ($input)
    {
        return $this->setAttribute('page', $input);
    }

    /**
     * Get user session attribute
     *
     * @param String $attribute
     * @param Mixed $default
     * @param Request $request
     * @return Mixed
     */
    public function getAttribute ($attribute, $default = null, Request $request)
    {
        return $request->session()
            ->get($this->prefix . $attribute, $default);
    }

    /**
     * Set user session attribute
     *
     * @param String $attribute
     * @param Mixed $value
     * @param Request $request
     * @return Mixed
     */
    public function setAttribute ($attribute, $value, Request $request)
    {
        return $request->session()->put($this->prefix . $attribute, $value);
    }

    /**
     * Get the current results per page
     *
     * @param Request $request
     * @return integer
     */
    public function getRpp (Request $request)
    {
        return $this->getAttribute('rpp', $this->rpp, $request);
    }

    /**
     * Set results per page attribute
     *
     * @param integer $input
     * @return integer
     */
    public function setRpp ($input)
    {
        return $this->setAttribute('rpp', 5);
    }

    /**
     * Get the sort order and column
     *
     * @param Request $request
     * @return array
     */
    public function getSort (Request $request)
    {
        return $this->getAttribute('sort', $this->getDefaultSort(), $request);
    }

    /**
     * Set sort order attribute
     *
     * @param array $input
     * @return array
     */
    public function setSort (array $input)
    {
        return $this->setAttribute('sort', $input);
    }

    /**
     * Get the default sort array
     *
     * @return array
     */
    public function getDefaultSort ()
    {
        return array('id', 'desc');
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return View
     * @throws \Throwable
     */
    public function index (Request $request)
    {
        // updates sort, rpp from request
        $this->updatePaging($request);

        // get filters from session
        $filters = $this->getFilters($request);

        $this->hasFilter = count($filters);

        // base criteria
        $query_past = $this->buildCriteria($request);//,'start_at', 'desc' );
        $query_future = $this->buildCriteria($request);//, 'start_at', 'asc');

        $query_past->past();
        $query_future->future();

        // build future events query
        $query_future
            // public or where created by
            ->where(function ($query) {
                $query->whereIn('visibility_id', [1, 2])
                    ->where('created_by', '=', $this->user ? $this->user->id : NULL);
                // if logged in, can see guarded
                if ($this->user) {
                    $query->orWhere('visibility_id', '=', 4);
                }
                $query->orWhere('visibility_id', '=', 3);
                return $query;
            });
        // get future events
        $future_events = $query_future
            ->with('visibility')
            ->paginate($this->rpp);

        // build past events query
        $query_past
            // public or where created by
            ->where(function ($query) {
                $query->whereIn('visibility_id', [1, 2])
                    ->where('created_by', '=', $this->user ? $this->user->id : NULL);
                // if logged in, can see guarded
                if ($this->user) {
                    $query->orWhere('visibility_id', '=', 4);
                }
                $query->orWhere('visibility_id', '=', 3);
                return $query;
            });
        // get past events
        $past_events = $query_past
            ->with('visibility')
            ->paginate($this->rpp);

        return view('events.index')
            ->with(['rpp' => $this->rpp, 'sortBy' => $this->sortBy, 'sortOrder' => $this->sortOrder, 'hasFilter' => $this->hasFilter, 'filters' => $filters,
            ])
            ->with(compact('future_events'))
            ->with(compact('past_events'))
            ->render();
    }

    /**
     * Update the page list parameters from the request
     * @param $request
     */
    protected function updatePaging ($request)
    {
        if (!empty($request->input('filter_sort_by'))) {
            $this->sortBy = $request->input('filter_sort_by');
            $this->filters['filter_sort_by'] = $this->sortBy;
        }

        if (!empty($request->input('filter_sort_order'))) {
            $this->sortOrder = $request->input('filter_sort_order');
            $this->filters['filter_sort_order'] = $this->sortOrder;
        }

        if (!empty($request->input('filter_rpp'))) {
            $this->rpp = $request->input('filter_rpp');
            $this->filters['filter_rpp'] = $this->rpp;
        }

    }

    /**
     * Get session filters
     *
     * @param Request $request
     * @return array
     */
    public function getFilters (Request $request)
    {
        return $this->getAttribute('filters', $this->getDefaultFilters(), $request);
    }

    /**
     * Set filters attribute
     *
     * @param Request $request
     * @param array $input
     * @return array
     */
    public function setFilters (Request $request, array $input)
    {
        return $this->setAttribute('filters', $input, $request);
    }

    /**
     * Get the default filters array
     *
     * @return array
     */
    public function getDefaultFilters ()
    {
        return array();
    }

    /**
     * Builds the criteria from the session
     *
     * @param Request $request
     * @return \Illuminate\Database\Eloquent\Builder $query
     */
    public function buildCriteria (Request $request)
    {
        // get all the filters from the session
        $filters = $this->getFilters($request);

        // base criteria
        $query = Event::query();

        // add the criteria from the session
        // check request for passed filter values
        if (!empty($filters['filter_name'])) {
            // getting name from the request
            $name = $filters['filter_name'];
            $query->where('name', 'like', '%' . $name . '%');
            $filters['filter_name'] = $name;
        }

        if (!empty($filters['filter_tag'])) {
            $tag = $filters['filter_tag'];
            $query->whereHas('tags', function ($q) use ($tag) {
                $q->where('name', '=', ucfirst($tag));
            });

            // add to filters array
            $filters['filter_tag'] = $tag;
        }

        if (!empty($filters['filter_venue'])) {
            $venue = $filters['filter_venue'];
            // add has clause
            $query->whereHas('venue', function ($q) use ($venue) {
                $q->where('name', '=', $venue);
            });

            // add to filters array
            $filters['filter_venue'] = $venue;
        };

        if (!empty($filters['filter_related'])) {
            $related = $filters['filter_related'];
            $query->whereHas('entities', function ($q) use ($related) {
                $q->where('name', '=', ucfirst($related));
            });

            // add to filters array
            $filters['filter_related'] = $related;
        }

        // change this - should be separate
        if (!empty($filters['filter_rpp'])) {
            $this->rpp = $filters['filter_rpp'];
        }

        return $query;
    }

    /**
     * Display a grid listing of the resource.
     *
     * @param Request $request
     * @return View
     * @throws \Throwable
     */
    public function grid (Request $request)
    {
        // updates sort, rpp from request
        $this->updatePaging($request);

        $this->rpp = $this->gridRpp;

        // updates the filters in the session
        $this->updateFilters($request);

        // get filters from session
        $filters = $this->getFilters($request);

        $this->hasFilter = count($filters);

        // base criteria
        $query = $this->buildCriteria($request);//,'start_at', 'desc' );

        // get future events
        $events = $query->orderBy('created_at', 'desc')->paginate($this->rpp);
        $events->filter(function ($e) {
            return ((isset($e->visibility) && $e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        return view('events.grid')
            ->with(['rpp' => $this->rpp, 'sortBy' => $this->sortBy, 'sortOrder' => $this->sortOrder, 'hasFilter' => $this->hasFilter, 'filters' => $filters,
                'filter_name' => isset($filters['filter_name']) ? $filters['filter_name'] : NULL,  // there should be a better way to do this...
                'filter_venue' => isset($filters['filter_venue']) ? $filters['filter_venue'] : NULL,
                'filter_tag' => isset($filters['filter_tag']) ? $filters['filter_tag'] : NULL,
                'filter_related' => isset($filters['filter_related']) ? $filters['filter_related'] : NULL,
                'filter_rpp' => isset($filters['filter_rpp']) ? $filters['filter_rpp'] : NULL
            ])
            ->with(compact('events'))
            ->render();

    }

    /**
     * Update the filters parameters from the request
     * @param $request
     */
    protected function updateFilters ($request)
    {
        $filters = array();

        if (!empty($request->input('filter_name'))) {
            $filters['filter_name'] = $request->input('filter_name');
        };

        if (!empty($request->input('filter_venue'))) {
            $filters['filter_venue'] = $request->input('filter_venue');
        };

        if (!empty($request->input('filter_tag'))) {
            $filters['filter_tag'] = $request->input('filter_tag');
        };

        if (!empty($request->input('filter_related'))) {
            $filters['filter_related'] = $request->input('filter_related');
        };

        // save filters to session
        $this->setFilters($request, $filters);
    }

    /**
     * Display a listing of events from this point in time both future and past
     *
     * @param Request $request
     * @return View
     * @throws \Throwable
     */
    public function indexTimeline (Request $request)
    {
        $hasFilter = 1;

        // updates sort, rpp from request
        $this->updatePaging($request);

        // get filters from session
        $filters = $this->getFilters($request);

        // base criteria
        $query_past = $this->buildCriteria($request);
        $query_future = $this->buildCriteria($request);

        $query_past->past();
        $query_future->future();

        // get future events
        $future_events = $query_future->where('start_at', '>', Carbon::today()->startOfDay())
            ->where(function ($query) {
                return $query->where('visibility_id', '=', 3)
                    ->orWhere('created_by', '=', $this->user ? $this->user->id : NULL);
            })
            ->with('visibility')->paginate($this->rpp);

        // get past events
        $past_events = $query_past->where('start_at', '<', Carbon::today()->startOfDay())->where(function ($query) {
            return $query->where('visibility_id', '=', 3)
                ->orWhere('created_by', '=', $this->user ? $this->user->id : NULL);
        })
            ->with('visibility')->paginate($this->rpp);

        return view('events.index')
            ->with(['rpp' => $this->rpp, 'sortBy' => $this->sortBy, 'sortOrder' => $this->sortOrder, 'hasFilter' => $hasFilter, 'filters' => $filters,
            ])
            ->with(compact('future_events'))
            ->with(compact('past_events'))
            ->render();
    }

    /**
     * Filter the list of events
     *
     * @param Request $request
     * @return View
     * @throws \Throwable
     */
    public function filter (Request $request)
    {
        // get all the filters from the session
        $this->filters = $this->getFilters($request);

        // update filters based on the request input
        $this->setFilters($request, array_merge($this->getFilters($request), $request->input()));

        // get the merged filters
        $this->filters = $this->getFilters($request);

        // updates sort, rpp from request
        $this->updatePaging($request);

        // flag that there are filters
        $this->hasFilter = count($this->filters);

        // base criteria
        $query_future = $this->event->future()->orderBy($this->sortBy, $this->sortOrder);
        $query_past = $this->event->past()->orderBy($this->sortBy, $this->sortOrder);

        // add the criteria from the session - move this?
        if (!empty($this->filters['filter_name'])) {
            $query_future->where('name', 'like', '%' . $this->filters['filter_name'] . '%');
            $query_past->where('name', 'like', '%' . $this->filters['filter_name'] . '%');
        };

        if (!empty($this->filters['filter_venue'])) {
            $venue = $this->filters['filter_venue'];
            // add has clause
            $query_future->whereHas('venue', function ($q) use ($venue) {
                $q->where('name', '=', $venue);
            });
            $query_past->whereHas('venue', function ($q) use ($venue) {
                $q->where('name', '=', $venue);
            });
        };

        if (!empty($this->filters['filter_tag'])) {
            $tag = $this->filters['filter_tag'];
            $query_future->whereHas('tags', function ($q) use ($tag) {
                $q->where('name', '=', ucfirst($tag));
            });
            $query_past->whereHas('tags', function ($q) use ($tag) {
                $q->where('name', '=', ucfirst($tag));
            });
        };

        if (!empty($this->filters['filter_related'])) {
            $related = $this->filters['filter_related'];
            $query_future->whereHas('entities', function ($q) use ($related) {
                $q->where('name', '=', ucfirst($related));
            });
            $query_past->whereHas('entities', function ($q) use ($related) {
                $q->where('name', '=', ucfirst($related));
            });
        };

        // change this - should be seperate
        if (!empty($this->filters['filter_rpp'])) {
            $this->rpp = $this->filters['filter_rpp'];
        };

        // get future events
        $future_events = $query_future->paginate($this->rpp);
        $future_events->filter(function ($e) {
            return (($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        // get past events
        $past_events = $query_past->paginate($this->rpp);
        $past_events->filter(function ($e) {
            return (($e->visibility && $e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        return view('events.index')
            ->with(['rpp' => $this->rpp,
                'sortBy' => $this->sortBy,
                'sortOrder' => $this->sortOrder,
                'hasFilter' => $this->hasFilter,
                'filters' => $this->filters,
            ])
            ->with(compact('future_events'))
            ->with(compact('past_events'))
            ->render();
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return View
     */
    public function indexAll (Request $request)
    {
        // updates sort, rpp from request
        $this->updatePaging($request);

        // get filters from session
        $filters = $this->getFilters($request);

        $this->hasFilter = count($filters);

        $future_events = Event::future()->paginate(100000);
        $future_events->filter(function ($e) {
            return (($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        $past_events = Event::past()->paginate(100000);
        $past_events->filter(function ($e) {
            return (($e->visibility && $e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        return view('events.index')
            ->with(['rpp' => $this->rpp, 'sortBy' => $this->sortBy, 'sortOrder' => $this->sortOrder, 'hasFilter' => $this->hasFilter])
            ->with(compact('future_events'))
            ->with(compact('past_events'));
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Response
     */
    public function indexFuture (Request $request)
    {
        // updates sort, rpp from request
        $this->updatePaging($request);

        // get filters from session
        $filters = $this->getFilters($request);

        $this->hasFilter = count($filters);

        $this->rpp = 10;

        $future_events = Event::future()->paginate($this->rpp);
        $future_events->filter(function ($e) {
            return (($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        return view('events.index')
            ->with(['rpp' => $this->rpp, 'sortBy' => $this->sortBy, 'sortOrder' => $this->sortOrder, 'hasFilter' => $this->hasFilter])
            ->with(compact('future_events'));
    }


    /**
     * Display a listing of today's events
     *
     * @param Request $request
     * @return Response
     */
    public function indexToday (Request $request)
    {
        // updates sort, rpp from request
        $this->updatePaging($request);

        // get filters from session
        $filters = $this->getFilters($request);

        $this->hasFilter = count($filters);

        $this->rpp = 10;

        $events = Event::today()->paginate($this->rpp);
        $events->filter(function ($e) {
            return (($e->visibility && $e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        return view('events.index')
            ->with(['rpp' => $this->rpp, 'sortBy' => $this->sortBy, 'sortOrder' => $this->sortOrder, 'hasFilter' => $this->hasFilter])
            ->with(compact('events'));
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Response
     */
    public function indexPast (Request $request)
    {
        // updates sort, rpp from request
        $this->updatePaging($request);

        // get filters from session
        $filters = $this->getFilters($request);

        $this->hasFilter = count($filters);

        $this->rpp = 10;

        $past_events = Event::past()->paginate($this->rpp);
        $past_events->filter(function ($e) {
            return (($e->visibility && $e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        return view('events.index')
            ->with(['rpp' => $this->rpp, 'sortBy' => $this->sortBy, 'sortOrder' => $this->sortOrder, 'hasFilter' => $this->hasFilter])
            ->with(compact('past_events'));
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Response
     */
    public function indexAttending (Request $request)
    {
        $this->middleware('auth');

        // updates sort, rpp from request
        $this->updatePaging($request);

        // get filters from session
        $filters = $this->getFilters($request);

        $this->hasFilter = count($filters);

        $this->rpp = 10;

        $events = $this->user->getAttending()->paginate($this->rpp);

        $events->filter(function ($e) {
            return (($e->visibility && $e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        return view('events.index')
            ->with(['tag' => 'Attending', 'rpp' => $this->rpp, 'sortBy' => $this->sortBy, 'sortOrder' => $this->sortOrder, 'hasFilter' => $this->hasFilter])
            ->with(compact('events'));
    }

    /**
     * Display a simple text feed of future events
     *
     * @return Response
     */
    public function feed ()
    {
        // set number of results per page
        $this->rpp = 10000;

        $events = Event::future()->simplePaginate($this->rpp);
        $events->filter(function ($e) {
            return (($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        return view('events.feed', compact('events'));
    }

    /**
     * Display a simple text feed of future events by tag
     *
     * @return Response
     */
    public function feedTags ($tag)
    {
        // set number of results per page
        $this->rpp = 10000;

        $events = Event::getByTag(ucfirst($tag))->future()->simplePaginate($this->rpp);
        $events->filter(function ($e) {
            return (($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        return view('events.feed', compact('events'));
    }

    /**
     * Reset the filtering of entities
     *
     * @param Request $request
     * @return Response
     */
    public function reset (Request $request)
    {
        // doesn't have filter, but temp
        $hasFilter = 0;

        // set the filters to empty
        $this->setFilters($request, $this->getDefaultFilters());

        // base criteria
        $query_future = $this->event->future();
        $query_past = $this->event->past();

        // updates sort, rpp from request
        $this->updatePaging($request);

        // get future events
        $future_events = $query_future->paginate($this->rpp);
        $future_events->filter(function ($e) {
            return (($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        // get past events
        $past_events = $query_past->paginate($this->rpp);
        $past_events->filter(function ($e) {
            return (($e->visibility && $e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        if ($redirect = $request->input('redirect')) {
            return redirect()->route($redirect);
        };

        return view('events.index')
            ->with(['rpp' => $this->rpp, 'sortBy' => $this->sortBy, 'sortOrder' => $this->sortOrder, 'hasFilter' => $hasFilter])
            ->with(compact('future_events'))
            ->with(compact('past_events'))
            ->render();

    }

    /**
     * Send a reminder to all users who are attending this event
     *
     * @param $id
     * @return Response
     */
    public function remind ($id)
    {
        if (!$event = Event::find($id)) {
            flash()->error('Error', 'No such event');
            return back();
        };

        // get all the users attending
        foreach ($event->eventResponses as $response) {
            $user = User::findOrFail($response->user_id);

            Mail::send('emails.reminder', ['user' => $user, 'event' => $event], function ($m) use ($user, $event) {
                $m->from('admin@events.cutupsmethod.com', 'Event Repo');

                $m->to($user->email, $user->name)->subject('Event Repo: ' . $event->start_at->format('D F jS') . ' ' . $event->name . ' REMINDER');
            });
        }

        flash()->success('Success', 'You sent an email reminder to ' . count($event->eventResponses) . ' user about ' . $event->name);

        return back();
    }

    /**
     * Get the events for one passed day
     *
     * @param Request $request
     * @param $day
     * @return Response
     * @throws \Throwable
     */
    public function day (Request $request, $day)
    {
        if (!$day) {
            flash()->error('Error', 'No such day');
            return back();
        };
        $day = \Carbon\Carbon::parse($day);
        return view('events.day')
            ->with(['day' => $day, 'position' => 0, 'offset' => 0])
            ->render();


    }

    /**
     * Send a reminder to all users about all events they are attending
     *
     * @return Response
     */
    public function daily ()
    {
        // get all the users
        $users = User::orderBy('name', 'ASC')->get();

        // cycle through all the users
        foreach ($users as $user) {
            $events = $user->getAttendingFuture()->take(100);

            Mail::send('emails.daily-events', ['user' => $user, 'events' => $events], function ($m) use ($user, $events) {
                $m->from('admin@events.cutupsmethod.com', 'Event Repo');

                $m->to($user->email, $user->name)->subject('Event Repo: Daily Events Reminder');
            });

        };

        flash()->success('Success', 'You sent an email reminder to ' . count($users) . ' users about events they are attending');

        return back();
    }

    /**
     * Display a listing of events related to entity
     *
     * @param Request $request
     * @param $slug
     * @return Response
     */
    public function calendarRelatedTo (Request $request, $slug)
    {
        $slug = urldecode($slug);

        $eventList = array();

        // get all events related to the entity
        $events = Event::getByEntity(strtolower($slug))
            ->orderBy('start_at', 'ASC')
            ->orderBy('name', 'ASC')
            ->get();

        $events->filter(function ($e) {
            return (($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        foreach ($events as $event) {
            $eventList[] = \Calendar::event(
                $event->name, //event title
                false, //full day event?
                $event->start_at->format('Y-m-d H:i'), //start time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg)
                ($event->end_time ? $event->end_time->format('Y-m-d H:i') : NULL), //end time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg),
                $event->id, //optional event ID
                [
                    'url' => 'events/' . $event->id,
                    //'color' => '#fc0'
                ]
            );
        };

        // get all the upcoming series events
        $series = Series::getByEntity(ucfirst($slug))->active()->get();

        $series = $series->filter(function ($e) {
            // all public events
            // all events that you created
            // all events that you are invited to
            return ((($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id)) AND $e->occurrenceType->name != 'No Schedule');
        });

        foreach ($series as $s) {
            if ($s->nextEvent() == NULL AND $s->nextOccurrenceDate() != NULL) {
                // add the next instance of each series to the calendar
                $eventList[] = \Calendar::event(
                    $s->name, //event title
                    false, //full day event?
                    $s->nextOccurrenceDate()->format('Y-m-d H:i'), //start time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg)
                    ($s->nextOccurrenceEndDate() ? $s->nextOccurrenceEndDate()->format('Y-m-d H:i') : NULL), //end time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg),
                    $s->id, //optional event ID
                    [
                        'url' => 'series/' . $s->id,
                        'color' => '#99bcdb'
                    ]
                );
            };
        };

        $calendar = \Calendar::addEvents($eventList)//add an array with addEvents
        ->setOptions([ //set fullcalendar options
            'firstDay' => 0,
            'height' => 840,
        ])->setCallbacks([ //set fullcalendar callback options (will not be JSON encoded)
            //'viewRender' => 'function() {alert("Callbacks!");}'
        ]);
        return view('events.calendar', compact('calendar', 'slug'));

    }

    /**
     * Display a listing of events by tag
     *
     * @param $tag
     * @return Response
     */
    public function calendarTags ($tag)
    {
        $tag = urldecode($tag);

        $eventList = array();

        $events = Event::getByTag(ucfirst($tag))
            ->orderBy('start_at', 'ASC')
            ->orderBy('name', 'ASC')
            ->get();

        $events->filter(function ($e) {
            return (($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        foreach ($events as $event) {
            $eventList[] = \Calendar::event(
                $event->name, //event title
                false, //full day event?
                $event->start_at->format('Y-m-d H:i'), //start time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg)
                ($event->end_time ? $event->end_time->format('Y-m-d H:i') : NULL), //end time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg),
                $event->id, //optional event ID
                [
                    'url' => 'events/' . $event->id,
                    //'color' => '#fc0'
                ]
            );
        };

        // get all the upcoming series events
        $series = Series::getByTag(ucfirst($tag))->active()->get();

        $series = $series->filter(function ($e) {
            // all public events
            // all events that you created
            // all events that you are invited to
            return ((($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id)) AND $e->occurrenceType->name != 'No Schedule');
        });

        foreach ($series as $s) {
            if ($s->nextEvent() == NULL AND $s->nextOccurrenceDate() != NULL) {
                // add the next instance of each series to the calendar
                $eventList[] = \Calendar::event(
                    $s->name, //event title
                    false, //full day event?
                    $s->nextOccurrenceDate()->format('Y-m-d H:i'), //start time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg)
                    ($s->nextOccurrenceEndDate() ? $s->nextOccurrenceEndDate()->format('Y-m-d H:i') : NULL), //end time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg),
                    $s->id, //optional event ID
                    [
                        'url' => 'series/' . $s->id,
                        'color' => '#99bcdb'
                    ]
                );
            };
        };

        $calendar = \Calendar::addEvents($eventList)//add an array with addEvents
        ->setOptions([ //set fullcalendar options
            'firstDay' => 0,
            'height' => 840,
        ])->setCallbacks([ //set fullcalendar callback options (will not be JSON encoded)
            //'viewRender' => 'function() {alert("Callbacks!");}'
        ]);
        return view('events.calendar', compact('calendar', 'tag'));

    }

    /**
     * Display a calendar view of events
     *
     * @return view
     **/
    public function calendar ()
    {
        // get all public events
        $events = Event::all();

        $events = $events->filter(function ($e) {
            // all public events
            // all events that you created
            // all events that you are invited to
            return (($e->visibility->name == 'Public') || ($this->user && $e->created_by === $this->user->id));
        });

        // get all the upcoming series events
        $series = Series::active()->get();

        $series = $series->filter(function ($e) {
            // all public events
            // all events that you created
            // all events that you are invited to
            return ((($e->visibility->name == 'Public') || ($this->user && $e->created_by === $this->user->id)) AND $e->occurrenceType->name != 'No Schedule');
        });


        return $this->renderCalendar($events, $series);
    }

    /**
     * Displays the calendar based on passed events and tag
     *
     * @param $events
     * @param null $series
     * @param null $tag
     * @return view
     */
    public function renderCalendar ($events, $series = NULL, $tag = NULL)
    {
        $eventList = array();

        foreach ($events as $event) {
            $eventList[] = \Calendar::event(
                $event->name, //event title
                false, //full day event?
                $event->start_at->format('Y-m-d H:i'), //start time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg)
                ($event->end_time ? $event->end_time->format('Y-m-d H:i') : NULL), //end time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg),
                $event->id, //optional event ID
                [
                    'url' => '/events/' . $event->id,
                    //'color' => '#fc0'
                ]
            );
        };

        foreach ($series as $s) {
            if ($s->nextEvent() == NULL AND $s->nextOccurrenceDate() != NULL) {
                // add the next instance of each series to the calendar
                $eventList[] = \Calendar::event(
                    $s->name, //event title
                    false, //full day event?
                    $s->nextOccurrenceDate()->format('Y-m-d H:i'), //start time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg)
                    ($s->nextOccurrenceEndDate() ? $s->nextOccurrenceEndDate()->format('Y-m-d H:i') : NULL), //end time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg),
                    $s->id, //optional event ID
                    [
                        'url' => '/series/' . $s->id,
                        'color' => '#99bcdb'
                    ]
                );
            };
        };

        $calendar = \Calendar::addEvents($eventList)//add an array with addEvents
        ->setOptions([ //set fullcalendar options
            'firstDay' => 0,
            'height' => 840,
        ])->setCallbacks([ //set fullcalendar callback options (will not be JSON encoded)
            //'viewRender' => 'function() {alert("Callbacks!");}'
        ]);


        return view('events.calendar', compact('calendar', 'tag'));
    }

    /**
     * Display a calendar view of events you are attending
     *
     * @return view
     **/
    public function calendarAttending ()
    {
        $this->middleware('auth');

        $events = $this->user->getAttending()
            ->orderBy('start_at', 'ASC')
            ->orderBy('name', 'ASC')
            ->get();

        $events = $events->filter(function ($e) {
            // all public events
            // all events that you created
            // all events that you are invited to
            return (($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });


        // get all the upcoming series events
        $series = $this->user->getSeriesFollowing();

        $series = $series->filter(function ($e) {
            // all public events
            // all events that you created
            // all events that you are invited to
            return ((($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id)) AND $e->occurrenceType->name != 'No Schedule');
        });

        $tag = "Attending";

        return $this->renderCalendar($events, $series, $tag);
    }

    /**
     * Display a calendar view of free events
     *
     * @return view
     **/
    public function calendarFree ()
    {
        $events = Event::where('door_price', 0)
            ->orderBy('start_at', 'ASC')
            ->orderBy('name', 'ASC')
            ->get();

        $events = $events->filter(function ($e) {
            // all public events
            // all events that you created
            // all events that you are invited to
            return (($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });


        // get all the upcoming series events
        $series = Series::where('door_price', 0)->active()->get();

        $series = $series->filter(function ($e) {
            // all public events
            // all events that you created
            // all events that you are invited to
            return ((($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id)) AND $e->occurrenceType->name != 'No Schedule');
        });

        $tag = "No Cover";

        return $this->renderCalendar($events, $series, $tag);
    }

    /**
     * Display a calendar view of all ages
     *
     * @param $age
     * @return view
     */
    public function calendarMinAge ($age)
    {
        $age = urldecode($age);

        $events = Event::where('min_age', '<=', $age)
            ->orderBy('start_at', 'ASC')
            ->orderBy('name', 'ASC')
            ->get();

        $events = $events->filter(function ($e) {
            // all public events
            // all events that you created
            // all events that you are invited to
            return (($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });


        // get all the upcoming series events
        $series = Series::where('min_age', '<=', $age)->active()->get();

        $series = $series->filter(function ($e) {
            // all public events
            // all events that you created
            // all events that you are invited to
            return ((($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id)) AND $e->occurrenceType->name != 'No Schedule');
        });

        $tag = "Min Age " . $age;

        return $this->renderCalendar($events, $series, $tag);

    }

    /**
     * Display a listing of events by event type
     *
     * @param $type
     * @return Response
     */
    public function calendarEventTypes ($type)
    {
        $tag = urldecode($type);

        $eventList = array();

        $events = Event::getByType(ucfirst($tag))
            ->orderBy('start_at', 'ASC')
            ->orderBy('name', 'ASC')
            ->get();

        $events->filter(function ($e) {
            return (($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });


        foreach ($events as $event) {
            $eventList[] = \Calendar::event(
                $event->name, //event title
                false, //full day event?
                $event->start_at->format('Y-m-d H:i'), //start time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg)
                ($event->end_time ? $event->end_time->format('Y-m-d H:i') : NULL), //end time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg),
                $event->id, //optional event ID
                [
                    'url' => 'events/' . $event->id,
                    //'color' => '#fc0'
                ]
            );
        }

        // get all the upcoming series events
        $series = Series::getByType(ucfirst($tag))->active()->get();

        $series = $series->filter(function ($e) {
            // all public events
            // all events that you created
            // all events that you are invited to
            return ((($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id)) AND $e->occurrenceType->name != 'No Schedule');
        });

        foreach ($series as $s) {
            if ($s->nextEvent() == NULL AND $s->nextOccurrenceDate() != NULL) {
                // add the next instance of each series to the calendar
                $eventList[] = \Calendar::event(
                    $s->name, //event title
                    false, //full day event?
                    $s->nextOccurrenceDate()->format('Y-m-d H:i'), //start time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg)
                    ($s->nextOccurrenceEndDate() ? $s->nextOccurrenceEndDate()->format('Y-m-d H:i') : NULL), //end time, must be a DateTime object or valid DateTime format (http://bit.ly/1z7QWbg),
                    $s->id, //optional event ID
                    [
                        'url' => 'series/' . $s->id,
                        'color' => '#99bcdb'
                    ]
                );
            }
        }

        $calendar = \Calendar::addEvents($eventList)//add an array with addEvents
        ->setOptions([ //set fullcalendar options
            'firstDay' => 0,
            'height' => 840,
        ])->setCallbacks([ //set fullcalendar callback options (will not be JSON encoded)
            //'viewRender' => 'function() {alert("Callbacks!");}'
        ]);

        return view('events.calendar', compact('calendar', 'tag'));

    }

    /**
     * Show a form to create a new Article.
     *
     * @return view
     **/
    public function create ()
    {
        // get a list of venues
        $venues = ['' => ''] + Entity::getVenues()->pluck('name', 'id')->all();

        // get a list of promoters
        $promoters = ['' => ''] + Entity::whereHas('roles', function ($q) {
                $q->where('name', '=', 'Promoter');
            })->orderBy('name', 'ASC')->pluck('name', 'id')->all();

        $eventTypes = ['' => ''] + EventType::orderBy('name', 'ASC')->pluck('name', 'id')->all();
        $seriesList = ['' => ''] + Series::orderBy('name', 'ASC')->pluck('name', 'id')->all();
        $visibilities = ['' => ''] + Visibility::orderBy('name', 'ASC')->pluck('name', 'id')->all();

        $tags = Tag::orderBy('name', 'ASC')->pluck('name', 'id')->all();
        $entities = Entity::orderBy('name', 'ASC')->pluck('name', 'id')->all();

        $userList = ['' => ''] + User::orderBy('name', 'ASC')->pluck('name', 'id')->all();


        return view('events.create', compact('venues', 'eventTypes', 'visibilities', 'tags', 'entities', 'promoters', 'seriesList', 'userList'));
    }

    /**
     * Makes a call to the FB API if there is a link present and downloads the event cover photo
     * @param Int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function importPhoto ($id)
    {
        $event = Event::findOrFail($id);

        if (!$event->primary_link || $event->primary_link == '') {
            flash()->error('Error', 'You must have a valid Facebook event linked to import the photo.  To add from your desktop, drop an image file to the right.');
            return back();
        };

        if ($this->addFbPhoto($event)) {
            flash()->success('Success', 'Successfully imported the event cover photo.');
        }

        return back();

    }

    /**
     * @param $event
     * @return bool|\Illuminate\Http\RedirectResponse
     */
    protected function addFbPhoto ($event)
    {
        $fb = app(SammyK\LaravelFacebookSdk\LaravelFacebookSdk::class);

        // some fields may have been deprecated - only need cover here
        //$fields = 'attending_count,category,cover,interested_count,type,name,noreply_count,maybe_count,owner,place,roles';
        $fields = 'cover';

        $str = $event->primary_link;
        $spl = explode("/", $str);

        if (!isset($spl[4])) {
            flash()->error('Error', 'The link supplied does not have an importable photo.  Using a facebook event link is recommended.');
            return back();
        }

        $event_id = $spl[4];


        try {
            $token = $fb->getJavaScriptHelper()->getAccessToken();
            $response = $fb->get($event_id . '?fields=' . $fields, $token);


            if ($cover = $response->getGraphNode()->getField('cover')) {
                $source = $cover->getField('source');

                $content = file_get_contents($source);
                $path = file_put_contents('photos/temp.jpg', $content);

                $file = new UploadedFile('photos/temp.jpg', 'temp.jpg', NULL, NULL, UPLOAD_ERR_OK, TRUE);

                // make the photo object from the file in the request
                $photo = $this->makePhoto($file);

                // count existing photos, and if zero, make this primary
                if (count($event->photos) == 0) {
                    $photo->is_primary = 1;
                }

                $photo->save();

                // attach to event
                $event->addPhoto($photo);
            }

        } catch (\Facebook\Exceptions\FacebookSDKException $e) {
            flash()->error('Error', 'You could not import the image.  Error: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    protected function makePhoto (UploadedFile $file)
    {
        return Photo::named($file->getClientOriginalName())
            ->move($file);
    }

    /**
     * Makes a call to the FB API if there is a link present and downloads the event cover photo
     * @return \Illuminate\Http\RedirectResponse
     */
    public function importPhotos ()
    {
        // get all the events with no photo, but a fb url
        $events = Event::has('photos', '<', 1)
            ->where('primary_link', '<>', '')
            ->where('primary_link', 'like', '%facebook%')
            ->get();

        $fb = app(SammyK\LaravelFacebookSdk\LaravelFacebookSdk::class);
        $fields = 'attending_count,category,cover,interested_count,name,noreply_count,maybe_count';

        foreach ($events as $event) {
            $str = $event->primary_link;
            $spl = explode("/", $str);
            $event_id = $spl[4];

            $token = $fb->getJavaScriptHelper()->getAccessToken();
            $response = $fb->get($event_id . '?fields=' . $fields, $token);

            // get the cover from FB
            if ($cover = $response->getGraphNode()->getField('cover')) {

                // if a cover was returned, get the source
                if ($source = $cover->getField('source')) {
                    $content = file_get_contents($source);
                    file_put_contents('photos/temp.jpg', $content);

                    $file = new UploadedFile('photos/temp.jpg', 'temp.jpg', NULL, NULL, UPLOAD_ERR_OK, TRUE);

                    // make the photo object from the file in the request
                    /** @var Photo $photo */
                    $photo = $this->makePhoto($file);

                    // count existing photos, and if zero, make this primary
                    if (count($event->photos) == 0) {
                        $photo->is_primary = 1;
                    };

                    $photo->save();

                    // attach to event
                    /** @var Event $event */
                    $event->addPhoto($photo);
                }
            }
        }

        flash()->success('Success', 'Successfully imported the event cover photos.');
        return back();
    }

    /**
     * Makes a call to the FB API and posts the link of the specified event to the wall
     * @param Event $event
     * @return \Illuminate\Contracts\View\Factory|View
     * @throws \Illuminate\Container\EntryNotFoundException
     */
    public function postEvent (Event $event)
    {
        if (empty((array)$event)) {
            abort(404);
        };

        $fb = app(SammyK\LaravelFacebookSdk\LaravelFacebookSdk::class);
        $fields = 'attending_count,category,cover,interested_count,type,name,noreply_count,maybe_count,owner,place,roles';

        try {
            // $response = $fb->get('/me?fields=id,name,email', 'user-access-token');
            $token = $fb->getJavaScriptHelper()->getAccessToken();
            $response = $fb->get('1750886384941695?fields=' . $fields, $token);

            dd($response->getGraphNode);
            $url = $response->cover->source;
            dd($url);
        } catch (\Facebook\Exceptions\FacebookSDKException $e) {
            dd($e->getMessage());
        }

        $userNode = $response->getGraphUser();
        printf('Hello, %s!', $userNode->getName());

        return view('events.show', compact('event'));
    }

    /**
     * Makes a call to the FB API if there is a link present and downloads the event cover photo
     */
    public function getToken ()
    {
        $fb = app(SammyK\LaravelFacebookSdk\LaravelFacebookSdk::class);

        // Obtain an access token.
        try {
            $token = $fb->getAccessTokenFromRedirect();
        } catch (Facebook\Exceptions\FacebookSDKException $e) {
            dd($e->getMessage());
        }

        // Access token will be null if the user denied the request
        // or if someone just hit this URL outside of the OAuth flow.
        if (!$token) {
            // Get the redirect helper
            $helper = $fb->getRedirectLoginHelper();

            if (!$helper->getError()) {
                abort(403, 'Unauthorized action.');
            }

            // User denied the request
            dd(
                $helper->getError(),
                $helper->getErrorCode(),
                $helper->getErrorReason(),
                $helper->getErrorDescription()
            );
        }

        if (!$token->isLongLived()) {
            // OAuth 2.0 client handler
            $oauth_client = $fb->getOAuth2Client();

            // Extend the access token.
            try {
                $token = $oauth_client->getLongLivedAccessToken($token);
            } catch (Facebook\Exceptions\FacebookSDKException $e) {
                dd($e->getMessage());
            }
        }

        $fb->setDefaultAccessToken($token);
    }

    public function show (Event $event)
    {
        if (empty((array)$event)) {
            abort(404);
        };

        $thread = Thread::where('event_id', '=', $event->id)->get();

        return view('events.show', compact('event'))->with(['thread' => $thread ? $thread->first() : NULL]);
    }

    public function store (EventRequest $request, Event $event)
    {
        $msg = '';

        // get the request
        $input = $request->all();

        // if slug is empty, use name
        if (!$request->input('slug') || count($request->input('slug')) < 3) {
            $input['slug'] = str_slug($request->input('name', '-'));
        } else {
            $input['slug'] = str_slug($request->input('slug', '-'));
        };

        // validation happening in EventRequest->rules
        $tagArray = $request->input('tag_list', []);
        $syncArray = array();

        // check the elements in the tag list, and if any don't match, add the tag
        foreach ($tagArray as $key => $tag) {
            if (!Tag::find($tag)) {

                $newTag = new Tag;
                $newTag->name = ucwords(strtolower($tag));
                $newTag->tag_type_id = 1;
                $newTag->save();

                // log adding of new tag
                Activity::log($newTag, $this->user, 1);

                $syncArray[] = $newTag->id;

                $msg .= ' Added tag ' . $tag . '.';

            } else {
                $syncArray[$key] = $tag;
            };
        }

        $event = $event->create($input);

        $event->tags()->attach($syncArray);
        $event->entities()->attach($request->input('entity_list'));

        // make a call to notify all users who are following any of the sync'd tags
        $this->notifyFollowing($event);

        // add to activity log
        Activity::log($event, $this->user, 1);

        flash()->success('Success', 'Your event has been created');

        // check if a FB link was included
        if (strpos($event->primary_link, 'facebook') !== false) {
            // try to import the photo
            $this->addFbPhoto($event);
        }

        //return redirect('events');
        return redirect()->route('events.show', compact('event'));
    }

    /**
     * @param $event
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function notifyFollowing ($event)
    {
        $reply_email = config('app.noreplyemail');
        $site = config('app.app_name');
        $url = config('app.url');

        // notify users following any of the tags
        $tags = $event->tags()->get();
        $users = array();

        // improve this so it will only sent one email to each user per event, and include a list of all tags they were following that led to the notification
        foreach ($tags as $tag) {
            foreach ($tag->followers() as $user) {
                // if the user hasn't already been notified, then email them
                if (!array_key_exists($user->id, $users)) {
                    Mail::send('emails.following', ['user' => $user, 'event' => $event, 'object' => $tag, 'reply_email' => $reply_email, 'site' => $site, 'url' => $url], function ($m) use ($user, $event, $tag, $reply_email, $site, $url) {
                        $m->from($reply_email, $site);

                        $m->to($user->email, $user->name)->subject($site . ': ' . $tag->name . ' :: ' . $event->start_at->format('D F jS') . ' ' . $event->name);
                    });
                    $users[$user->id] = $tag->name;
                };
            };
        };

        // notify users following any of the entities
        $entities = $event->entities()->get();

        // improve this so it will only sent one email to each user per event, and include a list of entities they were following that led to the notification
        foreach ($entities as $entity) {
            foreach ($entity->followers() as $user) {

                // if the user hasn't already been notified, then email them
                if (!array_key_exists($user->id, $users)) {
                    Mail::send('emails.following', ['user' => $user, 'event' => $event, 'object' => $entity, 'reply_email' => $reply_email, 'site' => $site, 'url' => $url], function ($m) use ($user, $event, $entity, $reply_email, $site, $url) {
                        $m->from($reply_email, $site);

                        $m->to($user->email, $user->name)->subject($site . ': ' . $entity->name . ' :: ' . $event->start_at->format('D F jS') . ' ' . $event->name);
                    });
                    $users[$user->id] = $entity->name;
                };
            };
        };

        return back();
    }

    public function edit (Event $event)
    {
        $this->middleware('auth');

        // moved necessary lists into AppServiceProvider

        return view('events.edit', compact('event'));
    }

    public function update (Event $event, EventRequest $request)
    {
        $msg = '';

        $event->fill($request->input())->save();

        if (!$event->ownedBy($this->user)) {
            $this->unauthorized($request);
        };

        $tagArray = $request->input('tag_list', []);
        $syncArray = array();

        // check the elements in the tag list, and if any don't match, add the tag
        foreach ($tagArray as $key => $tag) {

            if (!Tag::find($tag)) {

                $newTag = new Tag;
                $newTag->name = ucwords(strtolower($tag));
                $newTag->tag_type_id = 1;
                $newTag->save();

                // log adding of new tag
                Activity::log($newTag, $this->user, 1);

                $syncArray[strtolower($tag)] = $newTag->id;

                $msg .= ' Added tag ' . $tag . '.';
            } else {

                $syncArray[$key] = $tag;
            };
        }

        $event->tags()->sync($syncArray);
        $event->entities()->sync($request->input('entity_list', []));

        // add to activity log
        Activity::log($event, $this->user, 2);

        flash()->success('Success', 'Your event has been updated');

        //return redirect('events');
        return redirect()->route('events.show', compact('event'));
    }

    protected function unauthorized (EventRequest $request)
    {
        if ($request->ajax()) {
            return response(['message' => 'No way.'], 403);
        }

        \Session::flash('flash_message', 'Not authorized');

        return redirect('/');
    }

    public function destroy (Event $event)
    {
        // add to activity log
        Activity::log($event, $this->user, 3);

        $event->delete();

        flash()->success('Success', 'Your event has been deleted!');

        return redirect('events');
    }

    /**
     * Mark user as attending the event.
     *
     * @param $id
     * @param Request $request
     * @return Response
     * @throws \Throwable
     */
    public function attend ($id, Request $request)
    {
        // check if there is a logged in user
        if (!$this->user) {
            flash()->error('Error', 'No user is logged in.');
            return back();
        };

        if (!$event = Event::find($id)) {
            flash()->error('Error', 'No such event');
            return back();
        };

        // add the attending response
        $response = new EventResponse;
        $response->event_id = $id;
        $response->user_id = $this->user->id;
        $response->response_type_id = 1; // 1 = Attending, 2 = Interested, 3 = Uninterested, 4 = Cannot Attend
        $response->save();

        // add to activity log
        Activity::log($event, $this->user, 6);

        Log::info('User ' . $id . ' is attending ' . $event->name);

        // handle the request if ajax
        if ($request->ajax()) {
            return [
                'Message' => 'You are now attending the event - ' . $event->name,
                'Success' => view('events.single')
                    ->with(compact('event'))
                    ->with('month', '')
                    ->render()
                    ];

        };
        flash()->success('Success', 'You are now attending the event - ' . $event->name);
        return back();

    }

    /**
     * Mark user as unattending the event.
     *
     * @param $id
     * @param Request $request
     * @return Response
     * @throws \Throwable
     */
    public function unattend ($id, Request $request)
    {
        // check if there is a logged in user
        if (!$this->user) {
            flash()->error('Error', 'No user is logged in.');
            return back();
        };

        if (!$event = Event::find($id)) {
            flash()->error('Error', 'No such event');
            return back();
        };

        // delete the attending response
        $response = EventResponse::where('event_id', '=', $id)->where('user_id', '=', $this->user->id)->where('response_type_id', '=', 1)->first();
        $response->delete();

        // add to activity log
        Activity::log($event, $this->user, 7);

        // handle the request if ajax
        if ($request->ajax()) {
            return [
                'Message' => 'You are no longer attending the event - ' . $event->name,
                'Success' => view('events.single')
                    ->with(compact('event'))
                    ->with('month', '')
                    ->render()
            ];
        };
        flash()->success('Success', 'You are no longer attending the event - ' . $event->name);
        return back();

    }

    /**
     * Record a user's review of the event
     *
     * @param $id
     * @param Request $request
     * @return Response
     */
    public function review ($id, Request $request)
    {

        // check if there is a logged in user
        if (!$this->user) {
            flash()->error('Error', 'No user is logged in.');
            return back();
        };

        if (!$event = Event::find($id)) {
            flash()->error('Error', 'No such event');
            return back();
        };

        // add the event review
        $review = new EventReview;
        $review->event_id = $id;
        $review->user_id = $this->user->id;
        $review->review_type_id = 1; // 1 = Informational, 2 = Positive, 3 = Neutral, 4 = Negative
        $review->attended = $request->input('attended', 0);
        $review->confirmed = $request->input('confirmed', 0);
        $review->expecation = $request->input('expectation', NULL);
        $review->rating = $request->input('rating', NULL);
        $review->review = $request->input('review', NULL);
        $review->created_by = $this->user->id;
        $review->save();

        flash()->success('Success', 'You reviewed the event - ' . $event->name);

        return back();

    }

    /**
     * Display a listing of events by tag
     *
     * @param Request $request
     * @param $tag
     * @return Response
     */
    public function indexTags (Request $request, $tag)
    {
        $tag = urldecode($tag);

        // updates sort, rpp from request
        $this->updatePaging($request);

        // get filters from session
        $filters = $this->getFilters($request);

        $this->hasFilter = count($filters);

        $future_events = Event::getByTag(ucfirst($tag))->future()
            ->orderBy('start_at', 'ASC')
            ->orderBy('name', 'ASC')
            ->paginate($this->rpp);

        $future_events->filter(function ($e) {
            return (($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        $past_events = Event::getByTag(ucfirst($tag))->past()
            ->orderBy('start_at', 'ASC')
            ->orderBy('name', 'ASC')
            ->paginate($this->rpp);


        $past_events->filter(function ($e) {
            return (($e->visibility && $e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });


        return view('events.index')
            ->with(['rpp' => $this->rpp, 'sortBy' => $this->sortBy, 'sortOrder' => $this->sortOrder, 'hasFilter' => $this->hasFilter])
            ->with(compact('future_events'))
            ->with(compact('past_events'))
            ->with(compact('tag'));

    }

    /**
     * Display a listing of events related to entity
     *
     * @param Request $request
     * @param $slug
     * @return View
     */
    public function indexRelatedTo (Request $request, $slug)
    {
        $slug = urldecode($slug);

        // updates sort, rpp from request
        $this->updatePaging($request);

        // get filters from session
        $filters = $this->getFilters($request);

        $this->hasFilter = count($filters);

        $future_events = Event::getByEntity(strtolower($slug))
            ->future()
            ->where(function ($query) {
                $query->visible($this->user);
            })
            ->orderBy('start_at', 'ASC')
            ->orderBy('name', 'ASC')
            ->paginate($this->rpp);

        $past_events = Event::getByEntity(strtolower($slug))
            ->past()
            ->where(function ($query) {
                $query->visible($this->user);
            })
            ->orderBy('start_at', 'ASC')
            ->orderBy('name', 'ASC')
            ->paginate($this->rpp);

        return view('events.index')
            ->with(['rpp' => $this->rpp, 'sortBy' => $this->sortBy, 'sortOrder' => $this->sortOrder, 'hasFilter' => $this->hasFilter])
            ->with(compact('future_events'))
            ->with(compact('past_events'))
            ->with(compact('slug'));
    }

    /**
     * Display a listing of events that start on the specified day
     *
     * @param Request $request
     * @param $date
     * @return View
     */
    public function indexStarting (Request $request, $date)
    {
        // updates sort, rpp from request
        $this->updatePaging($request);

        // get filters from session
        $filters = $this->getFilters($request);

        $this->hasFilter = count($filters);

        $cdate = Carbon::parse($date);
        $cdate_yesterday = Carbon::parse($date)->subDay(1);
        $cdate_tomorrow = Carbon::parse($date)->addDay(1);

        $future_events = Event::where('start_at', '>', $cdate_yesterday->toDateString())
            ->where('start_at', '<', $cdate_tomorrow->toDateString())
            ->where(function ($query) {
                $query->visible($this->user);
            })
            ->orderBy('start_at', 'ASC')
            ->orderBy('name', 'ASC')
            ->paginate($this->rpp);

        return view('events.index')
            ->with(['rpp' => $this->rpp, 'sortBy' => $this->sortBy, 'sortOrder' => $this->sortOrder, 'hasFilter' => $this->hasFilter])
            ->with(compact('future_events'))
            ->with(compact('cdate'));
    }

    /**
     * Display a listing of events by venue
     *
     * @param Request $request
     * @param $slug
     * @return View
     */
    public function indexVenues (Request $request, $slug)
    {
        // updates sort, rpp from request
        $this->updatePaging($request);

        // get filters from session
        $filters = $this->getFilters($request);

        $this->hasFilter = count($filters);


        $future_events = Event::getByVenue(strtolower($slug))
            ->future()
            ->where(function ($query) {
                $query->visible($this->user);
            })
            ->orderBy('start_at', 'ASC')
            ->orderBy('name', 'ASC')
            ->paginate($this->rpp);

        $past_events = Event::getByVenue(strtolower($slug))
            ->past()
            ->where(function ($query) {
                $query->visible($this->user);
            })
            ->orderBy('start_at', 'ASC')
            ->orderBy('name', 'ASC')
            ->paginate($this->rpp);


        return view('events.index')
            ->with(['rpp' => $this->rpp, 'sortBy' => $this->sortBy, 'sortOrder' => $this->sortOrder, 'hasFilter' => $this->hasFilter])
            ->with(compact('future_events'))
            ->with(compact('past_events'))
            ->with(compact('slug'));
    }

    /**
     * Display a listing of events by type
     *
     * @param Request $request
     * @param $slug
     * @return View
     */
    public function indexTypes (Request $request, $slug)
    {
        // updates sort, rpp from request
        $this->updatePaging($request);

        // get filters from session
        $filters = $this->getFilters($request);

        $this->hasFilter = count($filters);


        $slug = urldecode($slug);

        $future_events = Event::getByType($slug)
            ->future()
            ->where(function ($query) {
                $query->visible($this->user);
            })
//					->orderBy('start_at', 'ASC')
            ->paginate($this->rpp);

        $past_events = Event::getByType($slug)
            ->past()
            ->where(function ($query) {
                $query->visible($this->user);
            })
            //				->orderBy('start_at', 'ASC')
            ->paginate($this->rpp);


        return view('events.index')
            ->with(['rpp' => $this->rpp, 'sortBy' => $this->sortBy, 'sortOrder' => $this->sortOrder, 'hasFilter' => $this->hasFilter])
            ->with(compact('future_events'))
            ->with(compact('past_events'))
            ->with(compact('slug'));
    }

    /**
     * Display a listing of events by series
     *
     * @param Request $request
     * @param $slug
     * @return View
     */
    public function indexSeries (Request $request, $slug)
    {
        // updates sort, rpp from request
        $this->updatePaging($request);

        // get filters from session
        $filters = $this->getFilters($request);

        $this->hasFilter = count($filters);

        $slug = urldecode($slug);

        $future_events = Event::getBySeries(strtolower($slug))
            ->future()
            ->where(function ($query) {
                $query->visible($this->user);
            })
            //->orderBy('start_at', 'ASC')
            ->paginate($this->rpp);

        $past_events = Event::getBySeries(strtolower($slug))
            ->past()
            ->where(function ($query) {
                $query->visible($this->user);
            })
            //	->orderBy('start_at', 'ASC')
            ->paginate($this->rpp);


        return view('events.index')
            ->with(['rpp' => $this->rpp, 'sortBy' => $this->sortBy, 'sortOrder' => $this->sortOrder, 'hasFilter' => $this->hasFilter])
            ->with(compact('future_events'))
            ->with(compact('past_events'))
            ->with(compact('slug'));
    }

    /**
     * Display a listing of events in a week view
     *
     * @param Request $request
     * @return View
     */
    public function indexWeek (Request $request)
    {
        $this->rpp = 7;

        $events = Event::future()->get();
        $events->filter(function ($e) {
            return (($e->visibility->name == 'Public') || ($this->user && $e->created_by == $this->user->id));
        });

        return view('events.indexWeek', compact('events'));
    }

    /**
     * Add a photo to an event
     *
     * @param  int $id
     * @param Request $request
     * @return void
     */
    public function addPhoto ($id, Request $request)
    {
        $this->validate($request, [
            'file' => 'required|mimes:jpg,jpeg,png,gif'
        ]);

        // get the event
        $event = Event::find($id);

        // make the photo object from the file in the request
        $photo = $this->makePhoto($request->file('file'));

        // count existing photos, and if zero, make this primary
        if (count($event->photos) === 0) {
            $photo->is_primary = 1;
        };

        $photo->save();

        // attach to event
        $event->addPhoto($photo);
    }

    /**
     * Delete a photo
     *
     * @param  int $id
     * @param Request $request
     * @return void
     */
    public function deletePhoto ($id, Request $request)
    {
        $this->validate($request, [
            'file' => 'required|mimes:jpg,jpeg,png,gif'
        ]);

        $photo = $this->deletePhoto($request->file('file'));
        $photo->save();

    }

    /**
     * Mark user as following the event
     *
     * @param $id
     * @param Request $request
     * @return Response
     */
    public function follow ($id, Request $request)
    {
        // check if there is a logged in user
        if (!$this->user) {
            flash()->error('Error', 'No user is logged in.');
            return back();
        };

        if (!$event = Event::find($id)) {
            flash()->error('Error', 'No such event');
            return back();
        };

        // add the following response
        $follow = new Follow;
        $follow->object_id = $id;
        $follow->user_id = $this->user->id;
        $follow->object_type = 'event'; //
        $follow->save();

        Log::info('User ' . $id . ' is following ' . $event->name);

        flash()->success('Success', 'You are now following the event - ' . $event->name);

        return back();

    }

    /**
     * Mark user as unfollowing the event.
     *
     * @param $id
     * @param Request $request
     * @return Response
     */
    public function unfollow ($id, Request $request)
    {
        // check if there is a logged in user
        if (!$this->user) {
            flash()->error('Error', 'No user is logged in.');
            return back();
        };

        if (!$event = Event::find($id)) {
            flash()->error('Error', 'No such event');
            return back();
        };

        // delete the follow
        $response = Follow::where('object_id', '=', $id)->where('user_id', '=', $this->user->id)->where('object_type', '=', 'event')->first();
        $response->delete();

        flash()->success('Success', 'You are no longer following the event.');

        return back();
    }

    /**
     * @param Request $request
     * @return $this
     */
    public function createSeries (Request $request)
    {
        // create a series from a single event

        $event = Event::find($request->id);

        // get a list of venues
        $venues = ['' => ''] + Entity::getVenues()->pluck('name', 'id')->all();

        // get a list of promoters
        $promoters = ['' => ''] + Entity::whereHas('roles', function ($q) {
                $q->where('name', '=', 'Promoter');
            })->orderBy('name', 'ASC')->pluck('name', 'id')->all();

        $eventTypes = ['' => ''] + EventType::orderBy('name', 'ASC')->pluck('name', 'id')->all();

        $visibilities = ['' => ''] + Visibility::orderBy('name', 'ASC')->pluck('name', 'id')->all();

        $tags = Tag::orderBy('name', 'ASC')->pluck('name', 'id')->all();
        $entities = Entity::orderBy('name', 'ASC')->pluck('name', 'id')->all();

        $occurrenceTypes = ['' => ''] + OccurrenceType::pluck('name', 'id')->all();
        $days = ['' => ''] + OccurrenceDay::pluck('name', 'id')->all();
        $weeks = ['' => ''] + OccurrenceWeek::pluck('name', 'id')->all();

        // initialize the form object with the values from the template
        $series = new \App\Series(['name' => $event->name,
            'slug' => $event->slug,
            'short' => $event->short,
            'venue_id' => $event->venue_id,
            'description' => $event->description,
            'event_type_id' => $event->event_type_id,
            'promoter_id' => $event->promoter_id,
            'soundcheck_at' => $event->soundcheck_at,
            'door_at' => $event->door_at,
            'founded_at' => $event->start_at,
            'start_at' => $event->start_at,
            'end_at' => $event->end_at,
            'presale_price' => $event->presale_price,
            'door_price' => $event->door_price,
            'min_age' => $event->min_age,
            'visibility_id' => $event->visibility_id,
        ]);

        return view('events.createSeries', compact('series', 'venues', 'occurrenceTypes', 'days', 'weeks', 'eventTypes', 'visibilities', 'tags', 'entities', 'promoters'))->with(['event' => $event]);
    }

    /**
     * @param Request $request
     * @return $this
     */
    public function createThread (Request $request)
    {
        // create a thread from a single event

        $event = Event::find($request->id);

        // initialize the form object with the values from the template
        $thread = new \App\Thread([
            'forum_id' => 1,
            'name' => $event->name,
            'slug' => $event->slug,
            'description' => $event->short,
            'body' => $event->short,
            'thread_category_id' => NULL,
            'visibility_id' => $event->visibility_id,
            'event_id' => $event->id,
            'likes' => 0,
        ]);

        $thread->save();

        $th = Thread::where('event_id', '=', $event->id)->get();

        return view('events.show', compact('event'))->with(['thread' => $th->first()]);

    }

    public function rss (RssFeed $feed)
    {
        $rss = $feed->getRSS();

        return response($rss)
            ->header('Content-type', 'application/rss+xml');
    }

    /**
     * @param RssFeed $feed
     * @param $tag
     * @return mixed
     */
    public function rssTags (RssFeed $feed, $tag)
    {

        $rss = $feed->getTagRSS(ucfirst($tag));

        return response($rss)
            ->header('Content-type', 'application/rss+xml');
    }

    /**
     * Returns true if the user has any filters outside of the default
     *
     * @param Request $request
     * @return Boolean
     */
    protected function getIsFiltered (Request $request)
    {
        if (($filters = $this->getFilters($request)) == $this->getDefaultFilters()) {
            return false;
        }
        return (bool)count($filters);
    }


}
