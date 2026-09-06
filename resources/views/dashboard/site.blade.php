@extends('layouts.app')

@section('title', $site->domain . ' · Clockwork')

@section('content')
    @include('dashboard.site.header')
    @include('dashboard.site.tab-nav')
    @include('dashboard.site.tab-' . $tab)
@endsection
