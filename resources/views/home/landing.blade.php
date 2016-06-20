{{--
    Copyright 2015 ppy Pty. Ltd.

    This file is part of osu!web. osu!web is distributed with the hope of
    attracting more community contributions to the core ecosystem of osu!.

    osu!web is free software: you can redistribute it and/or modify
    it under the terms of the Affero GNU General Public License version 3
    as published by the Free Software Foundation.

    osu!web is distributed WITHOUT ANY WARRANTY; without even the implied
    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
    See the GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with osu!web.  If not, see <http://www.gnu.org/licenses/>.
    --}}

    @extends("master", [
    'title' => 'osu!',
    'blank' => 'true',
    'body_additional_classes' => 'osu-layout--body-landing'
    ])

    @section("content")
    <nav class="osu-layout__row osu-layout__row--landing">
        <!-- Mobile Navigation -->
        @include('objects.mobile-header', ['navLinks' => landing_nav_links(), 'subLinks' => false])

        <!-- Desktop Navigation -->
        <div class="landing-nav hidden-xs">
            <div class="landing-nav__section landing-nav__section--left">
                @foreach (landing_nav_links() as $section => $links)
                <a href="{{ array_values($links)[0] }}" class="landing-nav__section__link {{ ($section == "home") ? "landing-nav__section__link--bold" : "" }}">{{ trans("layout.menu.$section._") }}</a>
                @endforeach
            </div>
            <a href="#" class="landing-nav__logo">
                <h1>osu!</h1>
                <span class="landing-nav__logo--overlay"></span>
                <span class="landing-nav__logo--glow"></span>
                <span class="landing-nav__logo--timing"></span>
                <span class="landing-nav__logo--bounce"></span>
            </a>
            <div class="landing-nav__section landing-nav__section--right js-nav-avatar">
                <a href="#" class="landing-nav__section__link" title="{{ trans("users.anonymous.login_link") }}" data-toggle="modal" data-target="#user-dropdown-modal">{{ trans("users.login._") }}</a>
                <a href="{{ route("users.register") }}" class="landing-nav__section__link">{{ trans("users.signup._") }}</a>
            </div>
        </div>
        <div id="user-dropdown-modal" class="modal fade" tabindex="-1">
            <div class="modal-dialog modal__dialog js-user-dropdown-modal__dialog">
                @if (Auth::check())
                <div class="js-react--user-card"></div>
                @else
                <div class="modal-content modal-content--no-shadow">
                    <div class="modal-header modal-header--login"><h1 class="modal-header__title">{{ trans("users.login._") }}</h1></div>
                    <div class="modal-body modal-body--user-dropdown modal-body--no-rounding">
                        <h2 class="modal-body__title modal-body__title">{{ trans("users.login.title") }}</h2>

                        {!! Form::open(["url" => route("users.login"), "id" => "login-form", "class" => "modal-body__form form", "data-remote" => true]) !!}
                        <div class="form__input-group form-group form-group--compact">
                            <input class="modal-af form-group__control form-control form-group__control--compact" name="username" type="text" placeholder="{{ trans("users.login.username") }}" required>
                            <input class="form-group__control form-control form-group__control--compact" name="password" type="password" placeholder="{{ trans("users.login.password") }}" required>
                        </div>

                        <button class="btn-osu btn-osu-default form__button" type="submit"><i class="fa fa-sign-in"></i></button>
                        {!! Form::close() !!}

                        <p class="modal-body__paragraph"><a href="{{ route("users.forgot-password") }}" target="_blank">{{ trans("users.login.forgot") }}</a></p>
                        <p class="modal-body__paragraph"><a href="{{ route("users.register") }}" target="_blank">{{ trans("users.login.register") }}</a></p>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </nav>
    <div id="popup-container">
        <div class="alert alert-dismissable popup-clone col-md-6 col-md-offset-3 text-center" style="display: none">
            <button type="button" data-dismiss="alert" class="close"><i class="fa fa-close"></i></button>
            <span class="popup-text"></span>
        </div>
    </div>
    <header class="osu-layout__row osu-layout__row--landing">
        <div class="landing-hero">
            <div class="js-landing-hero-slider landing-hero-slider">
                @for($i = 1; $i <= 2; $i++)
                <a href="#" class="landing-slide">
                    <span class="landing-slide__bg">
                        <img class="landing-slide__bg--image" src="/images/layout/landing-page/home-slider-{{$i}}.jpg" alt="pippi">
                    </span>
                    <span class="landing-slide__cta">
                        <span class="landing-slide__cta__content">{!! trans("home.landing.slogans.$i") !!}</span>
                    </span>
                </a>
                @endfor
            </div>
            <div class="landing-hero-download">
                <div class="landing-hero-download__inner">
                    <a href="http://m1.ppy.sh/r/osu!install.exe" class="landing-download-button shadow-hover">
                        <span class="fa fa-cloud-download landing-download-button__icon"></span>
                        <span class="landing-download-button__content">
                            <span class="landing-download-button__content--top">{{ trans("home.landing.download._") }}</span>
                            <span class="landing-download-button__content--bottom js-download-platform"></span>
                        </span>
                    </a>
                    <a href="{{ route('download') }}" class="landing-download-other js-download-other"></a>
                </div>
            </div>
            <div class="js-landing-graph landing-graph">
                <div class="landing-graph__info">
                    <b>{{ number_format($totalUsers, 0) }}</b> registered players, <b>{{ number_format($currentOnline, 0) }}</b> online players now
                </div>
            </div>        
        </div>
    </header>
    <main class="osu-layout__row osu-layout__row--landing">
        <div class="osu-layout__col-container">
            <div class="landing-middle-buttons">
                <a href="#" class="osu-layout__col osu-layout__col--sm-4 osu-layout__col--lg-4">
                    <img class="middle-button-image shadow-hover" src="/images/layout/landing-page/middle-button-1.jpg" alt="Placeholder text!">
                </a>
                <a href="{{ action('StoreController@getListing') }}" class="osu-layout__col osu-layout__col--sm-4 osu-layout__col--lg-4">
                    <img class="middle-button-image shadow-hover" src="/images/layout/landing-page/middle-button-2.jpg" alt="osu!store">
                </a>
                <a href="//next.ppy.sh/" class="osu-layout__col osu-layout__col--sm-4 osu-layout__col--lg-4">
                    <img class="middle-button-image shadow-hover" src="/images/layout/landing-page/middle-button-3.jpg" alt="osu!next">
                </a>
            </div>
        </div>
    </main>
    <footer class="osu-layout__section landing-footer">
        <div class="osu-layout__row landing-sitemap">
            <div class="osu-layout__col-container landing-sitemap__container">
                <div class="osu-layout__col osu-layout__col--sm-6 osu-layout__col--lg-3">
                    <ul class="landing-sitemap-list">
                        <li class="landing-sitemap-list__item">
                            <div class="landing-sitemap-list__item--bold">General</div>
                        </li>
                        <li class="landing-sitemap-list__item"><a href="{{ route('landing') }}" class="landing-sitemap-list__item--link">Home</a></li>
                        <li class="landing-sitemap-list__item"><a href="{{ route('changelog') }}" class="landing-sitemap-list__item--link">Changelog</a></li>
                        <li class="landing-sitemap-list__item"><a href="{{ action('BeatmapsetsController@index') }}" class="landing-sitemap-list__item--link">Beatmap Listing</a></li>
                        <li class="landing-sitemap-list__item"><a href="{{ route('download') }}" class="landing-sitemap-list__item--link">Download osu!</a></li>
                        <li class="landing-sitemap-list__item"><a href="{{ route('wiki') }}" class="landing-sitemap-list__item--link">Wiki</a></li>
                    </ul>
                </div>
                <div class="osu-layout__col osu-layout__col--sm-6 osu-layout__col--lg-3">
                    <ul class="landing-sitemap-list">
                        <li class="landing-sitemap-list__item">
                            <div class="landing-sitemap-list__item--bold">Help &amp; Community</div>
                        </li>
                        <li class="landing-sitemap-list__item"><a href="{{ route('faq') }}" class="landing-sitemap-list__item--link">Frequently Asked Questions</a></li>
                        <li class="landing-sitemap-list__item"><a href="{{ route('forum.forums.index') }}" class="landing-sitemap-list__item--link">Community Forums</a></li>
                        <li class="landing-sitemap-list__item"><a href="#" class="landing-sitemap-list__item--link">Live Streams</a></li>
                        <li class="landing-sitemap-list__item"><a href="#" class="landing-sitemap-list__item--link">Report an Issue</a></li>
                    </ul>
                </div>
                <div class="osu-layout__col osu-layout__col--sm-6 osu-layout__col--lg-3">
                    <ul class="landing-sitemap-list">
                        <li class="landing-sitemap-list__item">
                            <div class="landing-sitemap-list__item--bold">Support osu!</div>
                        </li>
                        <li class="landing-sitemap-list__item"><a href="{{ route('support-the-game') }}" class="landing-sitemap-list__item--link">Supporter Tags</a></li>
                        <li class="landing-sitemap-list__item"><a href="{{ action('StoreController@getListing') }}" class="landing-sitemap-list__item--link">Merchandise</a></li>
                    </ul>
                </div>
                <div class="osu-layout__col osu-layout__col--sm-6 osu-layout__col--lg-3">
                    <ul class="landing-sitemap-list">
                        <li class="landing-sitemap-list__item">
                            <div class="landing-sitemap-list__item--bold">Legal &amp; Status</div>
                        </li>
                        <li class="landing-sitemap-list__item"><a href="{{ config("osu.urls.legal.tos") }}" class="landing-sitemap-list__item--link">Terms of Service</a></li>
                        <li class="landing-sitemap-list__item"><a href="{{ config("osu.urls.legal.dmca") }}" class="landing-sitemap-list__item--link">Copyright (DMCA)</a></li>
                        <li class="landing-sitemap-list__item"><a href="{{ config("osu.urls.legal.server") }}" class="landing-sitemap-list__item--link">Server Status</a></li>
                        <li class="landing-sitemap-list__item"><a href="{{ config("osu.urls.legal.osustatus") }}" class="landing-sitemap-list__item--link">@osustatus</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="landing-footer-bottom">
            <div class="landing-footer-bottom__social">
                <a href="{{ route('support-the-game') }}" class="fa fa-heart landing-footer-bottom__social--icon"></a>
                <a href="{{ config("osu.urls.social.twitter") }}" class="fa fa-twitter landing-footer-bottom__social--icon"></a>
                <a href="{{ config("osu.urls.social.facebook") }}" class="fa fa-facebook-official landing-footer-bottom__social--icon"></a>
            </div>
            <div class="landing-footer-bottom__links">
                <a href="{{ config("osu.urls.legal.tos") }}" class="landing-footer-bottom__links--link">terms of service</a>
                <a href="{{ config("osu.urls.legal.dmca") }}" class="landing-footer-bottom__links--link">copyright (DMCA)</a>
                <a href="{{ config("osu.urls.legal.server") }}" class="landing-footer-bottom__links--link">server status</a>
                <a href="{{ config("osu.urls.legal.osustatus") }}" class="landing-footer-bottom__links--link">@osustatus</a>
            </div>
            <div class="landing-footer-bottom__copyright">ppy powered 2007-2016</div>
        </div>
    </footer>
    @endsection

    @section ("script")
    @parent

    <script id="json-stats" type="application/json">
        {!! json_encode($stats) !!}
    </script>

    <script src="{{ elixir("js/react/landing-page.js") }}" data-turbolinks-track></script>
    @endsection
