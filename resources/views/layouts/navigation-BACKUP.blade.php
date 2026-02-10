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
