@extends('app')

@section('title','Tag Edit')

@section('content')


	<h2>Tag . EDIT : {{ $tag->name }}</h2>
	<br> 	<a href="{!! route('tags.show', ['id' => $tag->id]) !!}" class="btn btn-primary">Show Tag</a> <a href="{!! URL::route('tags.index') !!}" class="btn btn-info">Return to list</a>


	{!! Form::model($tag, ['route' => ['tags.update', $tag->id], 'method' => 'PATCH']) !!}

		@include('tags.form', ['action' => 'update'])

	{!! Form::close() !!}

	{!! delete_form(['tags.destroy', $tag->id]) !!}

@stop
