<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom">
    <div class="container">
        <a class="navbar-brand" href="{{ url('/') }}">
            {{ config('app.name', 'Laravel') }}
        </a>

        <button class="navbar-toggler" type="button"
            data-bs-toggle="collapse"
            data-bs-target="#navbarSupportedContent"
            aria-controls="navbarSupportedContent"
            aria-expanded="false"
            aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <!-- Left Side -->
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="{{ url('/') }}">Home</a>
                </li>

                @php
                    $crudResources = config('crud-pack.resources', []);
                @endphp

                @if(!empty($crudResources))
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle"
                           href="#"
                           id="crudDropdown"
                           role="button"
                           data-bs-toggle="dropdown"
                           aria-expanded="false">
                            CRUD
                        </a>

                        <ul class="dropdown-menu" aria-labelledby="crudDropdown">
                            @foreach($crudResources as $res)
                                @php
                                    $label = $res['label'] ?? 'Resource';
                                    $base  = $res['route'] ?? null;
                                    $soft  = (bool)($res['soft_deletes'] ?? false);
                                @endphp

                                @continue(!$base)

                                <li>
                                    <h6 class="dropdown-header">{{ $label }}</h6>
                                </li>

                                <li>
                                    <a class="dropdown-item"
                                       href="{{ route($base . '.index') }}">
                                        List
                                    </a>
                                </li>

                                <li>
                                    <a class="dropdown-item"
                                       href="{{ route($base . '.create') }}">
                                        Create
                                    </a>
                                </li>

                                @if($soft)
                                    <li>
                                        <a class="dropdown-item text-danger"
                                           href="{{ route($base . '.trash') }}">
                                            Trash
                                        </a>
                                    </li>
                                @endif

                                @if(!$loop->last)
                                    <li><hr class="dropdown-divider"></li>
                                @endif
                            @endforeach
                        </ul>
                    </li>
                @endif

                {{-- Auth example --}}
                {{--
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('dashboard') }}">Dashboard</a>
                </li>
                --}}
            </ul>

            <!-- Right Side -->
            <ul class="navbar-nav ms-auto">
                {{-- Auth links --}}
                {{--
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('login') }}">Login</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="{{ route('register') }}">Register</a>
                </li>
                --}}
            </ul>
        </div>
    </div>
</nav>
