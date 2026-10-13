{{--
    The page an employee sees while an update is being put in.

    There is a React page for 503 as well, and it is the better one — but it is
    drawn by the application, so it needs `vendor` and the built assets on disk.
    The update deletes both for a few minutes, and in exactly that window there
    is nothing left to draw the page explaining the window.

    So this one is pre-rendered: `artisan down --render="maintenance"` runs it
    through Blade once, before anything is deleted, and keeps the HTML inside
    `storage/framework/down`. `public/index.php` serves it from
    `storage/framework/maintenance.php` before Composer's autoloader is even
    required. Nothing of the application is needed at that point, and nothing of
    it may be reached from here: no stylesheet, no script, no font and no image.
    Everything this page uses is in this file.

    It is not `errors/503.blade.php` on purpose. The framework picks that name up
    by itself, and with `APP_DEBUG` on it would quietly replace the trace that
    debugging is for.

    The colours are the tokens of `resources/css/app.css`, the wording is the
    wording of `resources/js/pages/errors/503.tsx`, and the shell around it is
    the one `components/error-page.tsx` puts a signed-out visitor in.
--}}
<!DOCTYPE html>
<html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="robots" content="noindex">

        <title>Идёт обновление системы</title>

        <style>
            :root {
                color-scheme: light;

                --background: hsl(0, 0%, 100%);
                --foreground: #2a2d31;
                --muted: hsl(0, 0%, 96.1%);
                --muted-foreground: #44474c;
                --border: #e4e4e7;
                --ring: #7c9a2a;
                --brand: #a8cf45;
                --brand-ink: #161a0b;

                /* The number is the one thing tinted per theme, as on the React page. */
                --code: #4a6410;

                /* The mark is drawn in the brand green either way; the lettering beside it turns white on black. */
                --logo-tagline: #37454c;
                --logo-wordmark: #212121;
            }

            /*
             * Nothing was chosen, so the system decides — which is where the
             * application also starts from. The `.light` escape is for the
             * employee who chose the light theme on a machine set to dark.
             */
            @media (prefers-color-scheme: dark) {
                html:not(.light) {
                    color-scheme: dark;

                    --background: hsl(0, 0%, 3.9%);
                    --foreground: hsl(0, 0%, 98%);
                    --muted: hsl(0, 0%, 6.9%);
                    --muted-foreground: hsl(0, 0%, 63.9%);
                    --border: hsl(0, 0%, 14.9%);
                    --ring: #a8cf45;
                    --code: #c5e27a;
                    --logo-tagline: #ffffff;
                    --logo-wordmark: #ffffff;
                }
            }

            html.dark {
                color-scheme: dark;

                --background: hsl(0, 0%, 3.9%);
                --foreground: hsl(0, 0%, 98%);
                --muted: hsl(0, 0%, 6.9%);
                --muted-foreground: hsl(0, 0%, 63.9%);
                --border: hsl(0, 0%, 14.9%);
                --ring: #a8cf45;
                --code: #c5e27a;
                --logo-tagline: #ffffff;
                --logo-wordmark: #ffffff;
            }

            * {
                box-sizing: border-box;
            }

            body {
                /*
                 * The application's own face is Geist, which arrives with the
                 * build and is therefore gone. What is left is the rest of the
                 * same stack, which every machine already has.
                 */
                font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif, 'Segoe UI Emoji';
                -webkit-font-smoothing: antialiased;
                background-color: var(--background);
                color: var(--foreground);
                display: flex;
                flex-direction: column;
                gap: 1rem;
                margin: 0;
                min-height: 100dvh;
                padding: 1.5rem 1rem;
            }

            .brand {
                align-items: center;
                align-self: center;
                display: flex;
                gap: 0.625rem;
            }

            .brand svg {
                display: block;
                height: 2rem;
                width: 8rem;
            }

            .brand span {
                background-color: var(--background);
                border: 1px solid var(--border);
                border-radius: 0.375rem;
                color: var(--muted-foreground);
                font-size: 11px;
                font-weight: 600;
                letter-spacing: 0.05em;
                padding: 0.125rem 0.375rem;
            }

            main {
                align-items: center;
                display: flex;
                flex: 1;
                justify-content: center;
            }

            .notice {
                align-items: center;
                display: flex;
                flex-direction: column;
                gap: 1.25rem;
                max-width: 28rem;
                text-align: center;
                width: 100%;
            }

            .emblem {
                align-items: center;
                background-color: var(--muted);
                border-radius: 1rem;
                color: var(--muted-foreground);
                display: flex;
                height: 4rem;
                justify-content: center;
                width: 4rem;
            }

            .emblem svg {
                height: 2rem;
                width: 2rem;
            }

            .said {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }

            /* The number is the whole explanation, so it is allowed to be large. */
            .code {
                color: var(--code);
                font-size: 3rem;
                font-variant-numeric: tabular-nums;
                font-weight: 700;
                letter-spacing: -0.025em;
                line-height: 1.1;
            }

            h1 {
                font-size: 1.25rem;
                font-weight: 600;
                letter-spacing: -0.025em;
                margin: 0;
            }

            p {
                color: var(--muted-foreground);
                font-size: 0.875rem;
                line-height: 1.625;
                margin: 0;
                text-wrap: balance;
            }

            button {
                align-items: center;
                background-color: var(--brand);
                border: none;
                border-radius: 0.375rem;
                color: var(--brand-ink);
                cursor: pointer;
                display: inline-flex;
                font: inherit;
                font-size: 0.875rem;
                font-weight: 500;
                gap: 0.5rem;
                height: 2.5rem;
                justify-content: center;
                padding: 0 1rem;
                transition: background-color 150ms;
            }

            button:hover {
                background-color: color-mix(in oklab, var(--brand) 90%, var(--background));
            }

            button:focus-visible {
                outline: 2px solid var(--ring);
                outline-offset: 2px;
            }

            button svg {
                height: 1rem;
                width: 1rem;
            }

            footer {
                color: var(--muted-foreground);
                font-size: 0.75rem;
                text-align: center;
            }

            /* On a phone the button is as wide as the text it sits under, and tall enough for a thumb. */
            @media (max-width: 767px) {
                button {
                    font-size: 15px;
                    height: 2.75rem;
                    max-width: 20rem;
                    width: 100%;
                }
            }

            @media (min-width: 640px) {
                body {
                    padding: 2.5rem;
                }

                .notice {
                    gap: 1.5rem;
                }
            }
        </style>

        <script>
            /*
             * The application keeps the chosen theme in `localStorage` under
             * `appearance` and starts from `system`; reading the same key means
             * this page comes up in the theme the last page was in. Where the
             * read is refused — a private window, blocked site data — the media
             * query above still has the system setting to go on.
             */
            try {
                var appearance = localStorage.getItem('appearance');

                if (appearance === 'dark' || appearance === 'light') {
                    document.documentElement.className = appearance;
                }
            } catch (error) {}
        </script>
    </head>
    <body>
        <div class="brand">
            <svg viewBox="0 0 120 30" fill="none" role="img" aria-label="Evolet">
                <path fill="var(--logo-tagline)" d="M54.2539 0.901675H56.4527V1.40348H54.8112V2.27242H56.2524V2.74671H54.8112V4.22H54.2539V0.901675ZM57.5545 2.89999H58.5267L58.0493 1.47359H58.0362L57.5545 2.89999ZM57.751 0.901675H58.3441L59.5706 4.22H58.9728L58.6736 3.34184H57.4026L57.1042 4.22H56.529L57.7509 0.901675H57.751ZM59.9761 0.901828H60.5331V4.22031H59.9761V0.901828ZM61.0148 0.901675H63.5927V1.40348H62.5799V4.22H62.0227V1.40348H61.0148V0.901675ZM64.0518 0.901675H64.6094V2.22184H66.0591V0.901675H66.6169V4.22H66.0591V2.72365H64.6094V4.22H64.0518V0.901675ZM68.771 0.901828H69.3281V4.22031H68.771V0.901828ZM70.1264 0.901675H70.715L72.1686 3.3463H72.178V0.901675H72.7091V4.22H72.12L70.6708 1.77984H70.6572V4.22H70.1264V0.901675ZM75.5403 2.89999H76.5126L76.0353 1.47359H76.0224L75.5405 2.89999H75.5403ZM75.737 0.901675H76.3296L77.5563 4.22H76.9583L76.6596 3.34184H75.3884L75.0899 4.22H74.5147L75.7368 0.901675H75.737ZM79.3667 0.901675H79.9239V2.22184H81.3743V0.901675H81.9314V4.22H81.3743V2.72365H79.9239V4.22H79.3667V0.901675ZM82.7423 0.901675H85.0352V1.40348H83.2999V2.27242H84.9054V2.74671H83.2999V3.71819H85.0662V4.22H82.7423V0.901675ZM86.3513 2.89999H87.3228L86.8455 1.47359H86.8331L86.3514 2.89999H86.3513ZM86.5472 0.901675H87.1402L88.3671 4.22H87.7689L87.4698 3.34184H86.199L85.9007 4.22H85.3254L86.547 0.901675H86.5472ZM88.8128 0.901675H89.3698V3.71819H90.9892V4.22H88.8128V0.901675ZM91.2119 0.901675H93.7894V1.40348H92.7773V4.22H92.2198V1.40348H91.2119V0.901675ZM94.2888 0.901675H94.8463V2.22184H96.2964V0.901675H96.8539V4.22H96.2964V2.72365H94.8463V4.22H94.2888V0.901675ZM98.4946 2.91844L97.2992 0.901675H97.9463L98.7889 2.38896L99.618 0.901675H100.243L99.0522 2.91844V4.22H98.4946V2.91844ZM102.08 0.901675H104.279V1.40348H102.638V2.27242H104.078V2.74671H102.638V4.22H102.08V0.901675ZM104.832 0.901675H105.389V2.83526C105.389 2.94673 105.394 3.06003 105.405 3.17441C105.413 3.28275 105.444 3.38798 105.494 3.48297C105.543 3.57445 105.618 3.64963 105.717 3.70835C105.817 3.76785 105.957 3.79691 106.138 3.79691C106.32 3.79691 106.46 3.76785 106.56 3.70835C106.66 3.64963 106.734 3.57445 106.783 3.48297C106.833 3.38793 106.864 3.28274 106.872 3.17441C106.883 3.06169 106.888 2.94851 106.888 2.83526V0.901675H107.445V3.02559C107.445 3.23929 107.414 3.42516 107.352 3.58336C107.293 3.73488 107.202 3.87066 107.086 3.98063C106.972 4.08763 106.834 4.16727 106.674 4.22C106.513 4.27273 106.335 4.29902 106.138 4.29902C105.943 4.29902 105.765 4.27289 105.603 4.22C105.443 4.16727 105.305 4.08763 105.191 3.98063C105.077 3.87363 104.988 3.74125 104.926 3.58336C104.863 3.42501 104.832 3.23914 104.832 3.02559V0.901675H104.832ZM107.927 0.901675H110.504V1.40348H109.492V4.22H108.935V1.40348H107.927V0.901675ZM110.982 0.901675H111.539V2.83526C111.539 2.94673 111.544 3.06003 111.555 3.17441C111.563 3.28279 111.594 3.38806 111.645 3.48297C111.694 3.57445 111.768 3.64963 111.867 3.70835C111.967 3.76785 112.107 3.79691 112.289 3.79691C112.47 3.79691 112.611 3.76785 112.71 3.70835C112.811 3.64963 112.884 3.57445 112.933 3.48297C112.984 3.38791 113.014 3.28271 113.022 3.17441C113.033 3.06003 113.038 2.94673 113.038 2.83526V0.901675H113.596V3.02559C113.596 3.23929 113.564 3.42516 113.502 3.58336C113.439 3.7411 113.351 3.87347 113.237 3.98063C113.123 4.08763 112.985 4.16727 112.824 4.22C112.664 4.27273 112.485 4.29902 112.289 4.29902C112.094 4.29902 111.915 4.27289 111.754 4.22C111.593 4.16727 111.455 4.08763 111.341 3.98063C111.225 3.87091 111.134 3.73505 111.076 3.58336C111.014 3.42501 110.982 3.23914 110.982 3.02559V0.901675ZM114.938 2.41186H115.852C116.03 2.41186 116.166 2.36805 116.258 2.27949C116.35 2.19094 116.396 2.05857 116.396 1.88238C116.396 1.77661 116.38 1.69113 116.351 1.62425C116.324 1.56199 116.282 1.50813 116.229 1.46805C116.175 1.43089 116.115 1.40557 116.051 1.39364C115.982 1.38152 115.913 1.37535 115.843 1.37519H114.938V2.41186ZM114.38 0.901367H115.902C116.252 0.901367 116.515 0.980236 116.69 1.13843C116.866 1.29617 116.954 1.51617 116.954 1.79813C116.954 1.95664 116.932 2.08763 116.886 2.19094C116.842 2.29502 116.79 2.37912 116.731 2.4423C116.671 2.50549 116.613 2.55115 116.555 2.57929C116.513 2.60016 116.471 2.61874 116.428 2.63494V2.64386C116.475 2.65062 116.526 2.66615 116.581 2.69075C116.64 2.71849 116.692 2.75906 116.735 2.80974C116.782 2.86324 116.822 2.93397 116.853 3.02098C116.885 3.10815 116.9 3.21577 116.9 3.34614C116.9 3.54185 116.914 3.71865 116.943 3.8787C116.971 4.03859 117.014 4.15143 117.074 4.22H116.476C116.434 4.14787 116.409 4.06662 116.402 3.98263C116.395 3.89638 116.391 3.81244 116.391 3.73203C116.391 3.58029 116.383 3.4493 116.365 3.33938C116.347 3.2293 116.314 3.13782 116.266 3.0648C116.219 2.99201 116.152 2.9362 116.073 2.90506C115.991 2.87078 115.886 2.85356 115.758 2.85356H114.938V4.21985H114.38V0.901367ZM117.662 0.901675H119.956V1.40348H118.22V2.27242H119.826V2.74671H118.22V3.71819H119.987V4.22H117.662V0.901675Z"></path>
                <path fill="var(--logo-wordmark)" d="M1.57125 16.3559H11.6356L10.7283 17.9932H1.57125V24.4482H12.1832L11.2756 26.0853H0V8.83647H12.1428L11.2367 10.4738H1.57125V16.3559ZM71.5732 24.4482H82.5695L81.6621 26.0853H70.0021V8.83678H71.5732V24.4482ZM58.8593 24.841C59.5255 24.8224 60.228 24.7561 60.8715 24.5707C61.3981 24.4196 61.8765 24.1624 62.2511 23.7434C63.1908 22.6903 63.2716 20.6703 63.2716 19.3096V15.671C63.2716 13.5525 63.1448 11.1032 60.8647 10.4201C59.1595 9.90942 56.2138 10.134 54.3944 10.134C53.6052 10.134 52.7503 10.19 51.9883 10.4094C51.468 10.5593 50.992 10.8133 50.6247 11.2324C49.6962 12.291 49.6108 14.3115 49.6108 15.6711V19.3097C49.6108 23.3454 50.496 24.8467 54.3944 24.8467C55.8776 24.8467 57.3805 24.8711 58.8591 24.841H58.8593ZM54.6138 26.4765C49.7596 26.4765 48.0221 24.3304 48.0221 19.1377V15.8384C48.0221 12.7925 48.4155 9.97107 51.4871 8.89489C52.4749 8.54836 53.5736 8.44597 54.611 8.44597C55.9558 8.44597 57.3367 8.4143 58.6771 8.45228C61.5572 8.53253 63.6711 9.43452 64.4819 12.6469C64.7424 13.6767 64.8255 14.7765 64.8255 15.8386V19.1378C64.8255 20.1876 64.7454 21.2728 64.4944 22.2929C64.0125 24.2474 63.2126 25.3949 61.3868 26.0294C60.5106 26.333 59.5569 26.4473 58.638 26.4715C57.3105 26.5064 55.9459 26.4766 54.6138 26.4766V26.4765ZM105.127 8.83678H120L119.093 10.474H112.855V26.0853H111.285V10.474H104.22L105.127 8.83678ZM89.2306 16.3559H99.2948L98.388 17.9932H89.2306V24.4482H99.842L98.9352 26.0853H87.6594V8.83647H99.8026L98.8962 10.4738H89.2306V16.3559Z"></path>
                <path fill="#A8CF45" d="M32.156 18.9654L41.5283 2.04659H17.0391L18.1713 0.0015374C27.0906 0.0015374 36.0106 0 44.9281 0L33.2888 21.0106L32.156 18.9655V18.9654Z"></path>
                <path fill="#A8CF45" d="M23.9139 8.85357L33.3626 25.9093L45.6075 3.80566L46.7406 5.8501C42.2812 13.9004 37.8221 21.9519 33.3632 30.0004L21.6484 8.85357H23.9139Z"></path>
                <path fill="#A8CF45" d="M36.4136 6.46482H17.5676L29.8132 28.5686L27.5474 28.568C23.0874 20.5184 18.6269 12.4685 14.168 4.41992H37.5453L36.4134 6.46467L36.4136 6.46482Z"></path>
            </svg>
            <span>HRM</span>
        </div>

        <main>
            <div class="notice">
                <span class="emblem">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>
                    </svg>
                </span>

                <div class="said">
                    <span class="code">503</span>
                    <h1>Идёт обновление системы</h1>
                    <p>Система ненадолго закрыта на обслуживание. Все данные в сохранности — зайдите через несколько минут.</p>
                </div>

                {{-- Every address answers the same way until the update is done, so the one thing that can help is asking again. --}}
                <button type="button" id="reload">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"></path>
                        <path d="M21 3v5h-5"></path>
                    </svg>
                    Обновить страницу
                </button>
            </div>
        </main>

        {{-- The year is fixed when the page is rendered, which is minutes before it is read. --}}
        <footer>© {{ date('Y') }} Evolet</footer>

        <script>
            document.getElementById('reload').addEventListener('click', function () {
                window.location.reload();
            });
        </script>
    </body>
</html>
