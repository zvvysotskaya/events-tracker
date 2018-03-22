@extends('app')

@section('title','Entity Location Edit')

@section('content')

	<P><B>Entity</B> > {!! link_to_route('entities.show', $entity->name, [$entity->slug], ['class' => 'text-'.$entity->entityStatus->getDisplayClass()]) !!}</P>

	<h1>Edit Location: <i>{{ $location->name }}</i> </h1> 

	{!! Form::model($location, ['route' => ['entities.locations.update', $entity->slug, $location->id], 'method' => 'PATCH']) !!}

		@include('locations.form', ['action' => 'update'])

	{!! Form::close() !!}

	<div class="col-md-3">
	<P>{!! delete_form(['entities.locations.destroy', $entity->slug,  $location->id]) !!}</P>
	</div>

@stop
