@extends('layouts.app')

@section('title', $server->name . ' · Clockwork')

@section('content')
    @include('dashboard.server.header')
    @include('dashboard.server.tab-nav')
    @include('dashboard.server.tab-' . $tab)
@endsection
