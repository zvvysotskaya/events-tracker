@extends('app')

@section('content')

<h1>Event Series 
	@include('series.crumbs', ['slug' => $series->slug])
</h1>
<P>
@if ($user && (Auth::user()->id == $series->user->id || $user->id == Config::get('app.superuser')  ) )
	<a href="{!! route('series.edit', ['id' => $series->id]) !!}" class="btn btn-primary">Edit Series</a>
	<a href="{!! route('series.createOccurrence', ['id' => $series->id]) !!}" class="btn btn-primary">Add Occurrence</a>
@endif
	<a href="{!! URL::route('series.index') !!}" class="btn btn-info">Return to list</a>
</P>

<div class="row">
<div class="col-md-4">
	<h2>{{ $series->name }}</h2>
	<b>{{ $series->occurrenceType->name }}  {{ $series->occurrenceRepeat() }}</b>

	<p>
	Founded {!! $series->founded_at ? $series->founded_at->format('l F jS Y') : 'unknown'!!}<br>
	@if ($series->cancelled_at != NULL)
	Cancelled {!! $series->cancelled_at ? $series->cancelled_at->format('l F jS Y') : 'unknown'!!}<br>
	@endif

	@if ($series->occurrenceType->name != 'No Schedule')
	Starts {!! $series->start_at ? $series->start_at->format('h:i A') : 'unknown';  !!} - Ends {!! $series->end_at ? $series->end_at->format('h:i A') : 'unknown';  !!} ({{ $series->length() }} hours)<br>
		@if ($nextEvent = $series->nextEvent() )
			Next is {{ $nextEvent->start_at->format('l F jS Y')}}<br>
		@elseif ($series->cancelled_at == NULL)
			Next is {{ $series->cycleFromFoundedAt() ? $series->founded_at->format('l F jS Y'). ' (not yet created)' : ' unkown'}}<br>
		@endif
	@endif
	</p>

	@if ($series->description)
	<description class="body">
		{!! nl2br($series->description) !!}
	</description>
	@endif

	<p>	{{ $series->eventType->name or ''}} at {{ $series->venue->name or 'No venue specified' }}</p>



	<P>
	@unless ($series->entities->isEmpty())
	Related Entities:
		@foreach ($series->entities as $entity)
		<span class="label label-tag"><a href="/series/relatedto/{{ $entity->slug }}">{{ $entity->name }}</a></span>
		@endforeach
	@endunless
	</P>

	@unless ($series->tags->isEmpty())
	<P>Tags:
	@foreach ($series->tags as $tag)
		<span class="label label-tag"><a href="/series/tag/{{ $tag->name }}">{{ $tag->name }}</a></span>
		@endforeach
	@endunless
	</P>

	<p>	<i>Added by {{ $series->user->name or '' }}</i></p>


	<div class="row">
		<div class="col-sm-12">
			<div class="bs-component">
				<div class="panel panel-info">

					<div class="panel-heading">
						<h3 class="panel-title">Events</h3>
					</div>
					<div class="panel-body">
							<div class="panel-body">
							@include('events.list', ['events' => $series->events])
							{!! $events->render() !!}
							</div>
					</div>
				</div>
			</div>
		</div>	
	</div>

</div>

<div class="col-md-8">
@if ($user && (Auth::user()->id == $series->user->id || $user->id == Config::get('app.superuser') ) )
<form action="/series/{{ $series->id }}/photos" class="dropzone" method="POST">
	<input type="hidden" name="_token" value="{{ csrf_token() }}">
</form>
@endif

<br style="clear: left;"/>

@foreach ($series->photos->chunk(4) as $set)
<div class="row">
@foreach ($set as $photo)
	<div class="col-md-2">
	<img src="/{{ $photo->thumbnail }}" alt="{{$photo->name}}"  style="max-width: 100%;">
	@if ($user && (Auth::user()->id == $series->user->id || $user->id == Config::get('app.superuser') ))	

			{!! link_form('Delete', $photo, 'DELETE') !!}
			@if ($photo->is_primary)
			<button class="btn btn-success">Primary</button>
			{!! link_form('Unset Primary', '/photos/'.$photo->id.'/unsetPrimary', 'POST') !!}
			@else
			{!! link_form('Make Primary', '/photos/'.$photo->id.'/setPrimary', 'POST') !!}
			@endif

	@endif
	</div>
@endforeach
</div>
@endforeach
</div>


</div>
@stop


@section('scripts.footer')
<script src="//cdnjs.cloudflare.com/ajax/libs/dropzone/4.2.0/dropzone.js"></script>
<script>
Dropzone.options.addPhotosForm = {
	maxFilesize: 3,
	accept: ['.jpg','.png','.gif']
}
</script>
@stop
