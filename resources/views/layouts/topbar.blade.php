<header>
    <nav class="navbar navbar-expand navbar-light" style="background-color: white;">
        <div class="container-fluid">

            <div class="col-6 row d-flex align-items-center">
                <div class="col-1 me-3 me-md-2">
                    <a href="#" class="burger-btn d-block"><i class="bi bi-justify fs-3"></i></a>

                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>

                @if(config('app.demo_mode'))
                    <div class="col-2">
                        <span class="badge alert-info primary-background-color">Demo Mode</span>
                    </div>
                @endif
            </div>
            <div class="col-6 justify-content-end d-flex">
                <div class="collapse navbar-collapse" id="navbarSupportedContent">

                    <div class="dropdown">
    <a href="#" id="topbarUserDropdown" class="user-dropdown d-flex align-items-center dropend dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
        <div class="avatar avatar-md2">
            <button class="dropdown-btn">
                @if ($currentLanguage)
                    <img src="{{ $currentLanguage->image }}" alt="{{ $currentLanguage->name }}" id="current-flag" class="flag">
                    <span id="current-language">{{ $currentLanguage->code }}</span>
                @else
                    <img src="{{ $defaultLanguage->image }}" alt="{{ $defaultLanguage->name }}" id="current-flag" class="flag">
                    <span id="current-language">{{ $defaultLanguage->code }}</span>
                @endif
                <span class="arrow">&#9662;</span>
            </button>
        </div>
        <div class="text"></div>
    </a>
   <ul class="dropdown-menu dropdown-menu-end topbarUserDropdown" aria-labelledby="topbarUserDropdown">
    @php
        $defaultLang = $settings['default_language'] ?? null; // fetch current default
    @endphp
    @foreach ($languages as $language)
        <li class="d-flex justify-content-between align-items-center px-2 py-1">
            <a class="dropdown-item d-flex align-items-center flex-grow-1" href="{{ route('language.set-current', $language->code) }}">
                <img src="{{ $language->image }}" alt="{{ $language->name }}" class="flag me-2">
                {{ $language->name }}
            </a>
                <form action="{{ route('settings.set-default-language') }}" method="POST" class="ms-2 m-0 p-0">
                    @csrf
                    <input type="hidden" name="default_language" value="{{ $language->code }}">
                    <button type="submit" class="btn btn-sm btn-primary py-0 px-2"
                        @if($defaultLang == $language->code) disabled @endif>
                        {{ $defaultLang == $language->code ? __('Default') : __('Set Default') }}
                    </button>
                </form>
        </li>
    @endforeach
</ul>

</div>

                    &nbsp;&nbsp;
                    <div class="dropdown">
                        <a href="#" id="topbarUserDropdown"
                           class="user-dropdown d-flex align-items-center dropend dropdown-toggle"
                           data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="avatar avatar-md2">
                                <img src="{{ Auth::user()->profile == '' ? url('assets/images/faces/2.jpg') : Auth::user()->profile }} " alt="">
                            </div>
                            <div class="text">
                                <h6 class="user-dropdown-name">{{ Auth::user()->name }}</h6>
                                <p class="user-dropdown-status text-sm text-muted"></p>
                            </div>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end topbarUserDropdown" aria-labelledby="topbarUserDropdown">
                            <li><a class="dropdown-item" href="{{ route('change-password.index') }}"><i class="icon-mid bi bi-gear me-2"></i>{{__("Change Password")}}</a></li>
                            <li><a class="dropdown-item" href="{{ route('change-profile.index') }}"><i class="icon-mid bi bi-person me-2"></i>{{__("Change Profile")}}</a></li>
                            <li><a class="dropdown-item" href="{{ route('logout') }} " onclick="event.preventDefault(); document.getElementById('logout-form').submit();"><i class="icon-mid bi bi-box-arrow-left me-2"></i> {{__("Logout")}}</a></li>
                            <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                                {{ csrf_field() }}
                            </form>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>
</header>
