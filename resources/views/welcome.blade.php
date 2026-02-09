@extends('layouts.app')

@section('content')
<div class="container">
    <div class="card">
        <div class="card-body">
            <h1 class="card-title">Welcome</h1>
            <p class="card-text">
                Fresh Laravel v{{ app()->version() }} install with Bootstrap 5 + Font Awesome 7.0.1 🚀
            </p>
        </div>
    </div>
</div>
@endsection
