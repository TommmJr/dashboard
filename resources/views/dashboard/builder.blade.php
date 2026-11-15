@extends('layouts.app')

@section('title', 'Dashboard Builder')
@section('page_heading', 'Dashboard Builder')

@section('content')
<div class="-mx-5 sm:-mx-8 px-4">
    <x-widgets.layout-editor-placeholder :widget="['label' => 'Editor Tata Letak Dashboard']" :data="$builderData" />
</div>
@endsection
