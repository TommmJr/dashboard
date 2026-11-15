@extends('layouts.app')

@section('title', 'Dashboard Builder')
@section('page_heading', 'Dashboard Builder')

@section('content')
<div class="-mt-2 sm:-mt-3">
    <x-widgets.layout-editor-placeholder :widget="['label' => 'Editor Tata Letak Dashboard']" :data="$builderData" />
</div>
@endsection
