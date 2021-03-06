@extends('app')

@section('title','Add Comment')

@section('content')

	<P><B>{{ ucfirst($type) }}</B> > {!! link_to_route(str_plural($type).'.show', $object->name, [$object->id]) !!}</P>

	<h3>Add a New Comment</h3>

	{!! Form::open(['route' => [str_plural($type).'.comments.store', $object->getRouteKey()], 'method' => 'POST']) !!}

		@include('comments.form')

	{!! Form::close() !!}

@stop
