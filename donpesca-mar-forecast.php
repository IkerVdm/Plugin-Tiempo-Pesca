<?php
/**
 * Plugin Name: DonPesca Mar Forecast
 * Plugin URI: https://donpesca.com
 * Description: Informe marino visual para DonPesca con acceso configurable, puertos del País Vasco y lectura de pesca para el Cantábrico.
 * Version: 0.2.0
 * Author: Codex para DonPesca
 * Text Domain: donpesca-mar-forecast
 */

if (!defined('ABSPATH')) {
    exit;
}

final class DonPesca_Mar_Forecast {
    private const OPTION_ALLOWED_EMAILS = 'donpesca_mar_allowed_emails';
    private const OPTION_CACHE_MINUTES = 'donpesca_mar_cache_minutes';
    private const OPTION_PUBLIC_ACCESS = 'donpesca_mar_public_access';
    private const AJAX_ACTION = 'donpesca_mar_forecast';
    private const NONCE_ACTION = 'donpesca_mar_nonce';
    private const TIMEZONE = 'Europe/Madrid';
    private const OPEN_METEO_GFS = 'https://api.open-meteo.com/v1/gfs';
    private const OPEN_METEO_ECMWF = 'https://api.open-meteo.com/v1/ecmwf';
    private const OPEN_METEO_METEOFRANCE = 'https://api.open-meteo.com/v1/meteofrance';
    private const OPEN_METEO_MARINE = 'https://marine-api.open-meteo.com/v1/marine';
    private const MET_SUN_URL = 'https://api.met.no/weatherapi/sunrise/3.0/sun';
    private const MET_MOON_URL = 'https://api.met.no/weatherapi/sunrise/3.0/moon';
    private const USER_AGENT = 'DonPescaMarForecast/0.2 (+https://donpesca.com)';
    private const MAX_PUBLIC_DAYS = 7;

    private static ?DonPesca_Mar_Forecast $instance = null;

    public static function instance(): DonPesca_Mar_Forecast {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handle_ajax_forecast']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [$this, 'handle_ajax_forecast']);
    }

    public function register_shortcode(): void {
        add_shortcode('donpesca_mar_report', [$this, 'render_shortcode']);
    }

    public function register_assets(): void {
        $base_url = plugin_dir_url(__FILE__) . 'assets/';

        wp_register_style(
            'donpesca-mar-forecast',
            $base_url . 'style.css',
            [],
            '0.2.0'
        );

        wp_register_script(
            'donpesca-mar-forecast',
            $base_url . 'app.js',
            [],
            '0.2.0',
            true
        );
    }

    public function register_admin_page(): void {
        add_options_page(
            'DonPesca Mar Forecast',
            'DonPesca Mar Forecast',
            'manage_options',
            'donpesca-mar-forecast',
            [$this, 'render_admin_page']
        );
    }

    public function register_settings(): void {
        register_setting(
            'donpesca_mar_settings',
            self::OPTION_ALLOWED_EMAILS,
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_allowed_emails'],
                'default' => '',
            ]
        );

        register_setting(
            'donpesca_mar_settings',
            self::OPTION_CACHE_MINUTES,
            [
                'type' => 'integer',
                'sanitize_callback' => static function ($value): int {
                    $minutes = absint($value);
                    return $minutes > 0 ? $minutes : 20;
                },
                'default' => 20,
            ]
        );

        register_setting(
            'donpesca_mar_settings',
            self::OPTION_PUBLIC_ACCESS,
            [
                'type' => 'boolean',
                'sanitize_callback' => static function ($value): int {
                    return !empty($value) ? 1 : 0;
                },
                'default' => 0,
            ]
        );
    }

    public function sanitize_allowed_emails($value): string {
        $lines = preg_split('/[\r\n,;]+/', (string) $value) ?: [];
        $emails = [];

        foreach ($lines as $line) {
            $email = sanitize_email(trim($line));
            if ($email !== '') {
                $emails[] = strtolower($email);
            }
        }

        return implode("\n", array_values(array_unique($emails)));
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $ports = self::ports_catalog();
        ?>
        <div class="wrap">
            <h1>DonPesca Mar Forecast</h1>
            <p>Configura acceso, caché y uso del shortcode en donpesca.com.</p>
            <form method="post" action="options.php">
                <?php settings_fields('donpesca_mar_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Acceso público</th>
                        <td>
                            <label for="donpesca_mar_public_access">
                                <input id="donpesca_mar_public_access" type="checkbox" name="<?php echo esc_attr(self::OPTION_PUBLIC_ACCESS); ?>" value="1" <?php checked((bool) get_option(self::OPTION_PUBLIC_ACCESS, 0)); ?>>
                                Abrir el informe a cualquier visitante, incluso sin login.
                            </label>
                            <p class="description">Si no está marcado, solo podrán entrar usuarios logados cuyo email figure en la lista blanca.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="donpesca_mar_allowed_emails">Emails autorizados</label></th>
                        <td>
                            <textarea id="donpesca_mar_allowed_emails" name="<?php echo esc_attr(self::OPTION_ALLOWED_EMAILS); ?>" rows="10" cols="50" class="large-text code"><?php echo esc_textarea((string) get_option(self::OPTION_ALLOWED_EMAILS, '')); ?></textarea>
                            <p class="description">Un email por línea. Solo se usa cuando el acceso público está desactivado.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="donpesca_mar_cache_minutes">Caché (minutos)</label></th>
                        <td>
                            <input id="donpesca_mar_cache_minutes" type="number" min="5" step="1" name="<?php echo esc_attr(self::OPTION_CACHE_MINUTES); ?>" value="<?php echo esc_attr((string) get_option(self::OPTION_CACHE_MINUTES, 20)); ?>">
                            <p class="description">Reduce llamadas a APIs externas. Recomendado: 15-30 minutos.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Shortcode</h2>
            <code>[donpesca_mar_report]</code>

            <h2>Puertos incluidos</h2>
            <p><?php echo esc_html(implode(', ', array_keys($ports))); ?></p>
        </div>
        <?php
    }

    public function render_shortcode(): string {
        if (!$this->visitor_is_allowed()) {
            return $this->render_gate_message();
        }

        wp_enqueue_style('donpesca-mar-forecast');
        wp_enqueue_script('donpesca-mar-forecast');

        $ports = self::ports_catalog();
        $default_port = isset($ports['Zumaia']) ? 'Zumaia' : array_key_first($ports);
        $default_when = wp_date('Y-m-d\TH:i', time(), wp_timezone());
        $access_mode = $this->public_access_enabled() ? 'publico' : 'privado';

        wp_localize_script(
            'donpesca-mar-forecast',
            'DonPescaMarForecast',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(self::NONCE_ACTION),
                'action' => self::AJAX_ACTION,
                'defaultPort' => $default_port,
                'strings' => [
                    'loading' => 'Preparando parte marino...',
                    'error' => 'No se ha podido generar el informe ahora mismo.',
                ],
            ]
        );

        ob_start();
        ?>
        <section class="donpesca-mar" data-default-port="<?php echo esc_attr($default_port); ?>">
            <div class="donpesca-mar__shell">
                <header class="donpesca-mar__hero">
                    <div>
                        <span class="donpesca-mar__eyebrow">DonPesca Marine Desk</span>
                        <h2>Parte de mar y pesca para tu zona</h2>
                        <p>Análisis visual de mar, viento, mareas, luna y ventanas de pesca con criterio prudente y lectura específica del Cantábrico.</p>
                    </div>
                    <div class="donpesca-mar__badge-stack">
                        <span class="donpesca-mar__badge"><?php echo $access_mode === 'publico' ? 'Acceso abierto' : 'Acceso privado'; ?></span>
                        <span class="donpesca-mar__badge donpesca-mar__badge--ghost">Consulta hasta 7 días</span>
                    </div>
                </header>

                <form class="donpesca-mar__form" data-donpesca-form>
                    <div class="donpesca-mar__field">
                        <label for="donpesca-port">Puerto</label>
                        <select id="donpesca-port" name="port">
                            <?php foreach ($ports as $port_name => $port): ?>
                                <option value="<?php echo esc_attr($port_name); ?>" <?php selected($port_name, $default_port); ?>><?php echo esc_html($port_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="donpesca-mar__field donpesca-mar__field--coords">
                        <label for="donpesca-latitude">Latitud</label>
                        <input id="donpesca-latitude" type="number" step="0.0001" name="latitude" placeholder="43.2960">
                    </div>
                    <div class="donpesca-mar__field donpesca-mar__field--coords">
                        <label for="donpesca-longitude">Longitud</label>
                        <input id="donpesca-longitude" type="number" step="0.0001" name="longitude" placeholder="-2.2570">
                    </div>
                    <div class="donpesca-mar__field">
                        <label for="donpesca-when">Referencia</label>
                        <input id="donpesca-when" type="datetime-local" name="when" value="<?php echo esc_attr($default_when); ?>">
                    </div>
                    <div class="donpesca-mar__actions">
                        <button type="submit">Generar informe</button>
                        <p>Si rellenas coordenadas, tendrán prioridad sobre el puerto. A una semana la lectura se muestra como tendencia, no como parte fino.</p>
                    </div>
                </form>

                <div class="donpesca-mar__status" data-donpesca-status aria-live="polite"></div>
                <div class="donpesca-mar__results" data-donpesca-results></div>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    private function render_gate_message(): string {
        return '<section class="donpesca-mar donpesca-mar--locked"><div class="donpesca-mar__lock"><h3>Acceso restringido</h3><p>Este informe solo está disponible para usuarios autorizados o, si activas la opción, para todo el público.</p></div></section>';
    }

    public function handle_ajax_forecast(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!$this->visitor_is_allowed()) {
            wp_send_json_error(['message' => 'No autorizado.'], 403);
        }

        $port = sanitize_text_field((string) ($_POST['port'] ?? ''));
        $latitude = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float) $_POST['latitude'] : null;
        $longitude = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float) $_POST['longitude'] : null;
        $when_raw = sanitize_text_field((string) ($_POST['when'] ?? ''));

        try {
            $location = $this->resolve_location($port, $latitude, $longitude);
            $reference = $this->parse_reference_datetime($when_raw);
            $payload = $this->build_forecast_payload($location, $reference);
            wp_send_json_success($payload);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()], 500);
        }
    }

    private function visitor_is_allowed(): bool {
        if ($this->public_access_enabled()) {
            return true;
        }

        if (!is_user_logged_in()) {
            return false;
        }

        $current_user = wp_get_current_user();
        $email = strtolower((string) $current_user->user_email);
        return $email !== '' && in_array($email, $this->allowed_emails(), true);
    }

    private function public_access_enabled(): bool {
        return (bool) get_option(self::OPTION_PUBLIC_ACCESS, 0);
    }

    private function allowed_emails(): array {
        $raw = (string) get_option(self::OPTION_ALLOWED_EMAILS, '');
        $lines = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $emails = [];

        foreach ($lines as $line) {
            $email = sanitize_email(trim($line));
            if ($email !== '') {
                $emails[] = strtolower($email);
            }
        }

        return array_values(array_unique($emails));
    }

    private function resolve_location(string $port, ?float $latitude, ?float $longitude): array {
        if ($latitude !== null && $longitude !== null) {
            if ($latitude < 42.0 || $latitude > 44.5 || $longitude < -3.8 || $longitude > -1.0) {
                throw new RuntimeException('Las coordenadas deben estar en un rango razonable para la costa vasca.');
            }

            return [
                'name' => 'Coordenadas personalizadas',
                'region' => 'Personalizado',
                'latitude' => round($latitude, 4),
                'longitude' => round($longitude, 4),
            ];
        }

        $ports = self::ports_catalog();
        if (!isset($ports[$port])) {
            throw new RuntimeException('Puerto no reconocido.');
        }

        return $ports[$port];
    }

    private function parse_reference_datetime(string $raw): DateTimeImmutable {
        $timezone = new DateTimeZone(self::TIMEZONE);

        if ($raw === '') {
            return new DateTimeImmutable('now', $timezone);
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, $timezone);
        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException('La fecha de referencia no tiene un formato válido.');
        }

        return $date;
    }

    private function build_forecast_payload(array $location, DateTimeImmutable $reference): array {
        $now = new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
        $requested_span_days = $this->days_to_cover($now, $reference);
        $weather_models = $this->fetch_weather_models($location, $requested_span_days);
        $marine = $this->fetch_marine_forecast($location, $requested_span_days);
        $astronomy = $this->fetch_astronomy($location, $now, $requested_span_days);
        $windows = $this->build_windows($weather_models, $marine, $astronomy, $now, $reference);

        if ($windows === []) {
            throw new RuntimeException('No hay datos útiles para esa fecha. El límite práctico actual es 7 días vista.');
        }

        $best_window = $windows[0];
        $best_day_slot = $this->best_day_slot($windows, $reference);
        $tide_turns = $this->find_tide_turns($marine['times'], $marine['sea_level']);
        $tide_context = $this->tide_context($reference, $tide_turns);
        $fishing_fit = $this->classify_fishing_fit($best_window);
        $summary = $this->build_executive_summary($best_window, $fishing_fit, $reference, $now);

        return [
            'generatedAt' => wp_date('c', time(), wp_timezone()),
            'reference' => $reference->format(DateTimeInterface::ATOM),
            'location' => $location,
            'summary' => $summary,
            'bestWindow' => $best_window,
            'bestDaySlot' => $best_day_slot,
            'windows' => array_slice($windows, 0, 8),
            'astronomy' => $astronomy,
            'tides' => [
                'currentLevel' => $this->current_series_value($reference, $marine['times'], $marine['sea_level']),
                'previousTurn' => $tide_context['previous'],
                'nextTurn' => $tide_context['next'],
                'turns' => array_slice($tide_turns, 0, 12),
                'disclaimer' => 'La marea es una estimación modelizada de Open-Meteo Marine. Es apoyo visual, no sustituto del anuario ni del parte del puerto.',
            ],
            'consensus' => [
                'windModels' => $weather_models['meta'],
                'confidenceFormula' => 'La probabilidad de acierto cae cuando discrepan ECMWF/GFS/Météo-France y cae todavía más al alejarse hacia 5-7 días.',
            ],
            'notes' => [
                'El score de pesca ya no premia casi todo: exige más encaje entre mar, momento de marea, fase lunar y tipo de especie.',
                'A 24-48 horas el parte sirve para decidir con más rigor. A una semana se interpreta como tendencia.',
                'Las reglas de especies están adaptadas al Cantábrico: lubina, espáridos, pelágicos, fondo y cefalópodos no buscan la misma mar.',
            ],
        ];
    }

    private function days_to_cover(DateTimeImmutable $now, DateTimeImmutable $reference): int {
        $hours = max(0.0, ($reference->getTimestamp() - $now->getTimestamp()) / HOUR_IN_SECONDS);
        $days = (int) ceil($hours / 24) + 2;
        return max(3, min(self::MAX_PUBLIC_DAYS, $days));
    }

    private function fetch_weather_models(array $location, int $requested_days): array {
        $hourly = ['wind_speed_10m', 'wind_gusts_10m', 'wind_direction_10m'];
        $common = [
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'hourly' => implode(',', $hourly),
            'wind_speed_unit' => 'kn',
            'timezone' => self::TIMEZONE,
            'cell_selection' => 'sea',
        ];

        $endpoints = [
            'Meteo-France' => [
                'url' => self::OPEN_METEO_METEOFRANCE,
                'forecast_days' => min(4, $requested_days),
            ],
            'ECMWF' => [
                'url' => self::OPEN_METEO_ECMWF,
                'forecast_days' => $requested_days,
            ],
            'GFS' => [
                'url' => self::OPEN_METEO_GFS,
                'forecast_days' => $requested_days,
            ],
        ];

        $series = [];
        $meta = [];

        foreach ($endpoints as $label => $config) {
            $params = $common;
            $params['forecast_days'] = $config['forecast_days'];
            $data = $this->cached_json_request($config['url'], $params, 'weather');
            $hourly_data = $data['hourly'] ?? [];

            $series[$label] = [
                'time' => $hourly_data['time'] ?? [],
                'wind' => $hourly_data['wind_speed_10m'] ?? [],
                'gust' => $hourly_data['wind_gusts_10m'] ?? [],
                'direction' => $hourly_data['wind_direction_10m'] ?? [],
            ];

            $meta[] = [
                'name' => $label,
                'forecastDays' => $config['forecast_days'],
                'samples' => is_array($hourly_data['time'] ?? null) ? count($hourly_data['time']) : 0,
            ];
        }

        return [
            'series' => $series,
            'meta' => $meta,
        ];
    }

    private function fetch_marine_forecast(array $location, int $requested_days): array {
        $params = [
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'hourly' => implode(',', [
                'wave_height',
                'wave_direction',
                'wave_period',
                'swell_wave_height',
                'wind_wave_height',
                'sea_level_height_msl',
            ]),
            'timezone' => self::TIMEZONE,
            'forecast_days' => min(8, max(4, $requested_days + 1)),
            'cell_selection' => 'sea',
        ];

        $data = $this->cached_json_request(self::OPEN_METEO_MARINE, $params, 'marine');
        $hourly = $data['hourly'] ?? [];

        return [
            'times' => $hourly['time'] ?? [],
            'wave_height' => $hourly['wave_height'] ?? [],
            'wave_direction' => $hourly['wave_direction'] ?? [],
            'wave_period' => $hourly['wave_period'] ?? [],
            'swell_wave_height' => $hourly['swell_wave_height'] ?? [],
            'wind_wave_height' => $hourly['wind_wave_height'] ?? [],
            'sea_level' => $hourly['sea_level_height_msl'] ?? [],
        ];
    }

    private function fetch_astronomy(array $location, DateTimeImmutable $start, int $days): array {
        $items = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $day = $start->setTime(12, 0)->modify('+' . $offset . ' day');
            $date = $day->format('Y-m-d');
            $params = [
                'lat' => $location['latitude'],
                'lon' => $location['longitude'],
                'date' => $date,
                'offset' => $day->format('P'),
            ];

            $sun = $this->cached_json_request(self::MET_SUN_URL, $params, 'sun');
            $moon = $this->cached_json_request(self::MET_MOON_URL, $params, 'moon');
            $sun_props = $sun['properties'] ?? [];
            $moon_props = $moon['properties'] ?? [];
            $moon_phase = $moon_props['moonphase'] ?? null;
            $moon_value = is_array($moon_phase) ? ($moon_phase['value'] ?? null) : $moon_phase;

            $items[] = [
                'date' => $date,
                'sunrise' => $this->extract_iso_time($sun_props['sunrise']['time'] ?? null),
                'sunset' => $this->extract_iso_time($sun_props['sunset']['time'] ?? null),
                'moonrise' => $this->extract_iso_time($moon_props['moonrise']['time'] ?? null),
                'moonset' => $this->extract_iso_time($moon_props['moonset']['time'] ?? null),
                'moonPhaseValue' => is_numeric($moon_value) ? (float) $moon_value : null,
                'moonPhaseLabel' => $this->moon_phase_label($moon_value),
                'moonFishingNote' => $this->moon_fishing_note($moon_value),
            ];
        }

        return $items;
    }

    private function build_windows(array $weather_models, array $marine, array $astronomy, DateTimeImmutable $now, DateTimeImmutable $reference): array {
        $times = $marine['times'];
        $tide_turns = $this->find_tide_turns($marine['times'], $marine['sea_level']);
        $window_start = $reference->setTime((int) $reference->format('H'), 0)->modify('-12 hours');
        $window_end = $reference->modify('+36 hours');
        $results = [];

        foreach ($times as $index => $iso_time) {
            $time = new DateTimeImmutable($iso_time, new DateTimeZone(self::TIMEZONE));
            if ($time < $window_start || $time > $window_end) {
                continue;
            }

            $hours_ahead = ($time->getTimestamp() - $now->getTimestamp()) / HOUR_IN_SECONDS;
            if ($hours_ahead < -3 || $hours_ahead > self::MAX_PUBLIC_DAYS * 24) {
                continue;
            }

            $wind_samples = $this->collect_model_values($weather_models['series'], $iso_time, 'wind');
            $gust_samples = $this->collect_model_values($weather_models['series'], $iso_time, 'gust');
            $direction_samples = $this->collect_model_values($weather_models['series'], $iso_time, 'direction');
            if ($wind_samples === [] || $gust_samples === []) {
                continue;
            }

            $wave_height = $this->float_or_null($marine['wave_height'][$index] ?? null);
            $wave_period = $this->float_or_null($marine['wave_period'][$index] ?? null);
            $wave_direction = $this->float_or_null($marine['wave_direction'][$index] ?? null);
            $sea_level = $this->float_or_null($marine['sea_level'][$index] ?? null);
            $astronomy_day = $this->astronomy_for_day($astronomy, $time->format('Y-m-d'));
            $consensus = $this->confidence_score($wind_samples, $gust_samples, $hours_ahead);
            $energy = $this->wave_energy($wave_height, $wave_period);
            $tide_context = $this->tide_context($time, $tide_turns);
            $tide_state = $this->tide_state($time, $tide_context);
            $coeff_type = $this->tidal_coefficient_type($astronomy_day['moonPhaseValue'] ?? null);
            $target = $this->best_target_family(
                $time,
                $wave_height,
                $wave_period,
                $energy,
                $consensus,
                $astronomy_day,
                $tide_context,
                $tide_state,
                $coeff_type
            );
            $worst_wind = max($wind_samples);
            $worst_gust = max($gust_samples);
            $avg_direction = $this->average($direction_samples);
            $direction_penalty = $this->direction_penalty($wave_direction);
            $fishing_score = $this->fishing_window_score(
                $target['score'],
                $consensus,
                $hours_ahead,
                $worst_wind,
                $worst_gust,
                $wave_height,
                $energy,
                $tide_state
            );
            $sea_state = $this->classify_sea_state(
                $worst_wind,
                $worst_gust,
                $wave_height,
                $energy,
                $direction_penalty,
                $consensus,
                $hours_ahead
            );

            $results[] = [
                'time' => $time->format(DateTimeInterface::ATOM),
                'timeLabel' => wp_date('D j M H:i', $time->getTimestamp(), wp_timezone()),
                'hoursAhead' => round($hours_ahead, 1),
                'status' => $sea_state['status'],
                'headline' => $sea_state['headline'],
                'reason' => $sea_state['reason'],
                'confidence' => $consensus,
                'fishingScore' => $fishing_score,
                'recommendationLabel' => $target['label'],
                'recommendationFamily' => $target['family'],
                'recommendationReason' => $target['reason'],
                'tideState' => $tide_state,
                'coefficientType' => $coeff_type,
                'windWorst' => round($worst_wind, 1),
                'gustWorst' => round($worst_gust, 1),
                'windDirection' => $avg_direction !== null ? round($avg_direction) : null,
                'waveHeight' => $wave_height,
                'wavePeriod' => $wave_period,
                'waveDirection' => $wave_direction,
                'waveEnergy' => $energy,
                'seaLevel' => $sea_level,
                'moonPhase' => $astronomy_day['moonPhaseLabel'] ?? null,
                'moonFishingNote' => $astronomy_day['moonFishingNote'] ?? null,
                'sunrise' => $astronomy_day['sunrise'] ?? null,
                'sunset' => $astronomy_day['sunset'] ?? null,
                'moonrise' => $astronomy_day['moonrise'] ?? null,
                'moonset' => $astronomy_day['moonset'] ?? null,
                'tidePrevious' => $tide_context['previous'],
                'tideNext' => $tide_context['next'],
                'models' => [
                    'wind' => $this->format_model_samples($weather_models['series'], $iso_time, 'wind'),
                    'gust' => $this->format_model_samples($weather_models['series'], $iso_time, 'gust'),
                ],
            ];
        }

        usort(
            $results,
            static function (array $a, array $b): int {
                return ($b['fishingScore'] <=> $a['fishingScore']) ?: ($b['confidence'] <=> $a['confidence']);
            }
        );

        return $this->deduplicate_windows($results);
    }

    private function build_executive_summary(array $window, array $fishing_fit, DateTimeImmutable $reference, DateTimeImmutable $now): array {
        $days_ahead = max(0, floor(($reference->getTimestamp() - $now->getTimestamp()) / DAY_IN_SECONDS));
        $texts = [];

        $texts[] = $window['status'] === 'VERDE'
            ? 'Ventana razonable para revisar salida si el parte corto plazo no empeora.'
            : ($window['status'] === 'AMARILLO'
                ? 'Hay opción, pero con varios matices y necesidad de confirmar sobre la marcha.'
                : 'No es una ventana limpia para forzar una salida.');

        $texts[] = $window['recommendationReason'];

        if ($days_ahead >= 5) {
            $texts[] = 'Esta consulta entra en zona de tendencia. Sirve para orientarte, no para tomarla como parte definitivo.';
        } elseif ($window['confidence'] < 60) {
            $texts[] = 'La coincidencia entre modelos es floja y la probabilidad de acierto cae.';
        } else {
            $texts[] = 'La coincidencia entre modelos es aceptable para trabajar esta ventana.';
        }

        return [
            'headline' => $window['headline'],
            'status' => $window['status'],
            'confidence' => $window['confidence'],
            'fishingFit' => $fishing_fit,
            'texts' => $texts,
        ];
    }

    private function classify_fishing_fit(array $window): array {
        $score = (float) $window['fishingScore'];
        $label = 'Baja';
        $reason = 'No hay suficiente encaje entre mar, viento, momento de marea y lectura lunar.';

        if ($score >= 74) {
            $label = 'Alta';
            $reason = 'La ventana reúne bastantes factores a favor y no depende tanto de forzar la situación.';
        } elseif ($score >= 60) {
            $label = 'Media-alta';
            $reason = 'Tiene buen aspecto, pero exige elegir bien zona, táctica y duración de la salida.';
        } elseif ($score >= 46) {
            $label = 'Media';
            $reason = 'Solo compensa si buscas una oportunidad concreta y muy controlada.';
        }

        return [
            'score' => round($score, 1),
            'label' => $label,
            'reason' => $reason,
        ];
    }

    private function best_day_slot(array $windows, DateTimeImmutable $reference): ?array {
        $target_date = $reference->format('Y-m-d');
        $same_day = array_values(array_filter(
            $windows,
            static function (array $window) use ($target_date): bool {
                return str_starts_with((string) ($window['time'] ?? ''), $target_date);
            }
        ));

        if ($same_day === []) {
            return null;
        }

        usort(
            $same_day,
            static function (array $a, array $b): int {
                return ($b['fishingScore'] <=> $a['fishingScore']) ?: ($b['confidence'] <=> $a['confidence']);
            }
        );

        $slot = $same_day[0];
        $center = new DateTimeImmutable($slot['time']);
        $start = $center->modify('-90 minutes');
        $end = $center->modify('+90 minutes');

        return [
            'start' => $start->format(DateTimeInterface::ATOM),
            'end' => $end->format(DateTimeInterface::ATOM),
            'label' => wp_date('H:i', $start->getTimestamp(), wp_timezone()) . ' - ' . wp_date('H:i', $end->getTimestamp(), wp_timezone()),
            'reason' => 'Es la franja del día con mejor equilibrio entre actividad potencial, marea útil, mar y confianza del parte.',
            'status' => $slot['status'],
            'confidence' => $slot['confidence'],
            'fishingScore' => $slot['fishingScore'],
            'windWorst' => $slot['windWorst'],
            'gustWorst' => $slot['gustWorst'],
            'waveHeight' => $slot['waveHeight'],
            'wavePeriod' => $slot['wavePeriod'],
            'waveDirection' => $slot['waveDirection'],
            'waveEnergy' => $slot['waveEnergy'],
            'tideState' => $slot['tideState'],
            'coefficientType' => $slot['coefficientType'],
            'moonPhase' => $slot['moonPhase'],
            'moonFishingNote' => $slot['moonFishingNote'],
            'tidePrevious' => $slot['tidePrevious'],
            'tideNext' => $slot['tideNext'],
            'reasonDetail' => $slot['reason'],
        ];
    }

    private function find_tide_turns(array $times, array $sea_levels): array {
        $turns = [];

        for ($i = 1, $count = count($sea_levels) - 1; $i < $count; $i++) {
            $prev = $this->float_or_null($sea_levels[$i - 1] ?? null);
            $curr = $this->float_or_null($sea_levels[$i] ?? null);
            $next = $this->float_or_null($sea_levels[$i + 1] ?? null);

            if ($prev === null || $curr === null || $next === null) {
                continue;
            }

            $kind = null;
            if ($curr > $prev && $curr > $next) {
                $kind = 'pleamar';
            } elseif ($curr < $prev && $curr < $next) {
                $kind = 'bajamar';
            }

            if ($kind !== null) {
                $turns[] = [
                    'kind' => $kind,
                    'time' => (new DateTimeImmutable($times[$i], new DateTimeZone(self::TIMEZONE)))->format(DateTimeInterface::ATOM),
                    'height' => round($curr, 2),
                ];
            }
        }

        return $turns;
    }

    private function tide_context(DateTimeImmutable $reference, array $turns): array {
        $previous = null;
        $next = null;

        foreach ($turns as $turn) {
            $turn_time = new DateTimeImmutable($turn['time']);
            if ($turn_time <= $reference) {
                $previous = $turn;
                continue;
            }

            $next = $turn;
            break;
        }

        return ['previous' => $previous, 'next' => $next];
    }

    private function tide_state(DateTimeImmutable $time, array $tide_context): string {
        $previous = $tide_context['previous'] ?? null;
        $next = $tide_context['next'] ?? null;

        if (!$previous || !$next) {
            return 'indefinida';
        }

        $previous_time = new DateTimeImmutable($previous['time']);
        $next_time = new DateTimeImmutable($next['time']);
        $minutes_to_prev = abs($time->getTimestamp() - $previous_time->getTimestamp()) / 60;
        $minutes_to_next = abs($time->getTimestamp() - $next_time->getTimestamp()) / 60;

        if ($minutes_to_prev <= 60 || $minutes_to_next <= 60) {
            return 'rebase';
        }

        if (($previous['kind'] ?? '') === 'bajamar' && ($next['kind'] ?? '') === 'pleamar') {
            return 'subiendo';
        }

        if (($previous['kind'] ?? '') === 'pleamar' && ($next['kind'] ?? '') === 'bajamar') {
            return 'bajando';
        }

        return 'indefinida';
    }

    private function tidal_coefficient_type(?float $moon_phase): string {
        if ($moon_phase === null) {
            return 'medio';
        }

        if ($moon_phase <= 25 || $moon_phase >= 335 || ($moon_phase >= 155 && $moon_phase <= 205)) {
            return 'vivas';
        }

        if (($moon_phase >= 70 && $moon_phase <= 110) || ($moon_phase >= 250 && $moon_phase <= 290)) {
            return 'muertas';
        }

        return 'medio';
    }

    private function best_target_family(
        DateTimeImmutable $time,
        ?float $wave_height,
        ?float $wave_period,
        ?float $energy,
        int $confidence,
        array $astronomy_day,
        array $tide_context,
        string $tide_state,
        string $coeff_type
    ): array {
        $moon_label = $astronomy_day['moonPhaseLabel'] ?? '';
        $hour = (int) $time->format('H');
        $is_evening = $hour >= 18 || $hour <= 1;
        $near_pleamar = (($tide_context['previous']['kind'] ?? '') === 'pleamar' && $tide_state === 'rebase')
            || (($tide_context['next']['kind'] ?? '') === 'pleamar' && $tide_state === 'rebase');
        $near_bajamar = (($tide_context['previous']['kind'] ?? '') === 'bajamar' && $tide_state === 'rebase')
            || (($tide_context['next']['kind'] ?? '') === 'bajamar' && $tide_state === 'rebase');

        $rules = [];

        $lubina = 34.0;
        if ($wave_height !== null && $wave_height >= 0.8 && $wave_height <= 2.2) {
            $lubina += 12;
        }
        if ($energy !== null && $energy >= 10 && $energy <= 28) {
            $lubina += 7;
        }
        if ($tide_state === 'subiendo') {
            $lubina += 11;
        } elseif ($tide_state === 'bajando') {
            $lubina += 6;
        }
        if ($coeff_type === 'vivas') {
            $lubina += 8;
        }
        if ($moon_label === 'Luna nueva' || $moon_label === 'Luna llena') {
            $lubina += 6;
        }
        $rules[] = [
            'family' => 'Morónidos',
            'label' => 'Lubina',
            'score' => $lubina,
            'reason' => 'La lubina encaja mejor con mar vivo, espuma útil y marea en subida o primer tramo de bajada.',
        ];

        $sparids = 30.0;
        if ($coeff_type === 'vivas' && $wave_height !== null && $wave_height >= 0.9) {
            $sparids += 10;
        }
        if ($coeff_type === 'medio' && $near_pleamar) {
            $sparids += 8;
        }
        if ($tide_state === 'subiendo') {
            $sparids += 7;
        }
        if ($coeff_type === 'muertas') {
            $sparids += 4;
        }
        if (in_array($moon_label, ['Creciente', 'Menguante', 'Gibosa menguante', 'Gibosa creciente'], true)) {
            $sparids += 4;
        }
        $rules[] = [
            'family' => 'Espáridos',
            'label' => $coeff_type === 'medio' && $near_pleamar ? 'Dorada' : 'Sargo',
            'score' => $sparids,
            'reason' => $coeff_type === 'medio' && $near_pleamar
                ? 'La dorada suele encajar mejor en pleamar y con aguas más ordenadas.'
                : 'El sargo gana interés con mar batido y marea que va ganando piedra.',
        ];

        $pelagics = 26.0;
        if ($tide_state === 'rebase') {
            $pelagics += 16;
        }
        if ($coeff_type === 'medio' || $coeff_type === 'vivas') {
            $pelagics += 4;
        }
        if ($moon_label === 'Luna nueva') {
            $pelagics += 7;
        } elseif ($moon_label === 'Luna llena') {
            $pelagics -= 4;
        }
        $rules[] = [
            'family' => 'Scombridos y carángidos',
            'label' => 'Verdel, chicharro o bonito',
            'score' => $pelagics,
            'reason' => 'Los pelágicos suelen activarse mejor cuando el cambio de marea concentra pasto en puntas y bocanas.',
        ];

        $bottom = 28.0;
        if ($coeff_type === 'muertas') {
            $bottom += 14;
        }
        if ($near_bajamar || $tide_state === 'bajando') {
            $bottom += 9;
        }
        if ($wave_height !== null && $wave_height <= 1.0) {
            $bottom += 8;
        }
        if ($wave_period !== null && $wave_period <= 10.0) {
            $bottom += 4;
        }
        $rules[] = [
            'family' => 'Gádidos y fondo',
            'label' => 'Faneca, congrio o aligote',
            'score' => $bottom,
            'reason' => 'El fondo mejora con menos corriente, agua más limpia y bajamar o marea muerta.',
        ];

        $cephalopods = 25.0;
        if ($near_pleamar) {
            $cephalopods += 12;
        }
        if ($is_evening) {
            $cephalopods += 10;
        }
        if ($wave_height !== null && $wave_height <= 1.1) {
            $cephalopods += 8;
        }
        if ($moon_label === 'Luna llena') {
            $cephalopods += 10;
        } elseif ($moon_label === 'Luna nueva') {
            $cephalopods += 5;
        }
        $rules[] = [
            'family' => 'Cefalópodos',
            'label' => 'Chipirón o calamar',
            'score' => $cephalopods,
            'reason' => 'El chipirón suele casar mejor con pleamar, tarde-noche y aguas relativamente limpias.',
        ];

        foreach ($rules as &$rule) {
            $rule['score'] += ($confidence - 55) * 0.12;
            $rule['score'] = max(18.0, min(82.0, round($rule['score'], 1)));
        }
        unset($rule);

        usort(
            $rules,
            static function (array $a, array $b): int {
                return $b['score'] <=> $a['score'];
            }
        );

        return $rules[0];
    }

    private function fishing_window_score(
        float $target_score,
        int $confidence,
        float $hours_ahead,
        float $wind,
        float $gust,
        ?float $wave_height,
        ?float $energy,
        string $tide_state
    ): float {
        $score = $target_score;
        $score += max(0, $confidence - 50) * 0.15;
        $score -= max(0.0, $wind - 10.0) * 2.4;
        $score -= max(0.0, $gust - 16.0) * 1.2;
        $score -= max(0.0, ($wave_height ?? 0.0) - 1.3) * 14.0;
        $score -= max(0.0, ($energy ?? 0.0) - 22.0) * 0.7;
        $score -= max(0.0, $hours_ahead - 48.0) * 0.12;

        if ($tide_state === 'indefinida') {
            $score -= 8.0;
        } elseif ($tide_state === 'rebase') {
            $score += 2.0;
        }

        return max(18.0, min(86.0, round($score, 1)));
    }

    private function classify_sea_state(
        float $wind,
        float $gust,
        ?float $wave_height,
        ?float $energy,
        int $direction_penalty,
        int $confidence,
        float $hours_ahead
    ): array {
        $status = 'VERDE';
        $headline = 'Ventana razonable para revisar salida';
        $reasons = [];

        if ($wave_height !== null && $wave_height > 1.5) {
            $status = 'AMARILLO';
            $reasons[] = 'ola cerca del límite operativo';
        }
        if ($wind > 15.0 || $gust > 22.0) {
            $status = 'AMARILLO';
            $reasons[] = 'viento o rachas por encima del confort';
        }
        if (($wave_height !== null && $wave_height > 2.0) || $wind > 19.0 || $gust > 28.0) {
            $status = 'ROJO';
            $headline = 'No es ventana limpia para mar';
            $reasons[] = 'el peor escenario ya aprieta demasiado';
        }
        if ($energy !== null && $energy >= 24.0) {
            $status = $status === 'ROJO' ? 'ROJO' : 'AMARILLO';
            $reasons[] = 'el periodo mete demasiada energía';
        }
        if ($direction_penalty >= 2) {
            $status = $status === 'VERDE' ? 'AMARILLO' : $status;
            $reasons[] = 'dirección de mar muy incidente para esta costa';
        }
        if ($confidence < 55) {
            $status = $status === 'VERDE' ? 'AMARILLO' : $status;
            $reasons[] = 'los modelos no convergen bien';
        }
        if ($hours_ahead > 96) {
            $status = $status === 'VERDE' ? 'AMARILLO' : $status;
            $reasons[] = 'es una ventana lejana y se lee como tendencia';
        }

        if ($status === 'VERDE') {
            $headline = 'Ventana razonable para pescar';
            $reasons[] = 'mar y viento contenidos con criterio prudente';
        } elseif ($status === 'AMARILLO') {
            $headline = 'Ventana con matices';
        }

        return [
            'status' => $status,
            'headline' => $headline,
            'reason' => ucfirst(implode('; ', array_slice($reasons, 0, 3))) . '.',
        ];
    }

    private function confidence_score(array $wind_samples, array $gust_samples, float $hours_ahead): int {
        $spread_wind = max($wind_samples) - min($wind_samples);
        $spread_gust = max($gust_samples) - min($gust_samples);
        $score = 88;
        $score -= max(0.0, $hours_ahead) * 0.42;
        $score -= $spread_wind * 2.8;
        $score -= $spread_gust * 1.4;
        $score -= (3 - count($wind_samples)) * 9;

        return (int) max(22, min(92, round($score)));
    }

    private function collect_model_values(array $series, string $iso_time, string $field): array {
        $values = [];

        foreach ($series as $model) {
            $index = array_search($iso_time, $model['time'], true);
            if ($index === false) {
                continue;
            }

            $value = $this->float_or_null($model[$field][$index] ?? null);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function format_model_samples(array $series, string $iso_time, string $field): array {
        $rows = [];

        foreach ($series as $name => $model) {
            $index = array_search($iso_time, $model['time'], true);
            if ($index === false) {
                continue;
            }

            $value = $this->float_or_null($model[$field][$index] ?? null);
            if ($value !== null) {
                $rows[] = [
                    'name' => $name,
                    'value' => round($value, 1),
                ];
            }
        }

        return $rows;
    }

    private function astronomy_for_day(array $astronomy, string $date): array {
        foreach ($astronomy as $day) {
            if (($day['date'] ?? '') === $date) {
                return $day;
            }
        }

        return [];
    }

    private function deduplicate_windows(array $windows): array {
        $result = [];
        $kept_times = [];

        foreach ($windows as $window) {
            $time = new DateTimeImmutable($window['time']);
            $keep = true;

            foreach ($kept_times as $kept_time) {
                if (abs($time->getTimestamp() - $kept_time->getTimestamp()) < 3 * HOUR_IN_SECONDS) {
                    $keep = false;
                    break;
                }
            }

            if ($keep) {
                $result[] = $window;
                $kept_times[] = $time;
            }
        }

        return $result;
    }

    private function current_series_value(DateTimeImmutable $reference, array $times, array $values): ?float {
        $closest = null;
        $closest_delta = PHP_INT_MAX;

        foreach ($times as $index => $time_string) {
            $time = new DateTimeImmutable($time_string, new DateTimeZone(self::TIMEZONE));
            $delta = abs($time->getTimestamp() - $reference->getTimestamp());
            if ($delta < $closest_delta) {
                $closest_delta = $delta;
                $closest = $this->float_or_null($values[$index] ?? null);
            }
        }

        return $closest;
    }

    private function cached_json_request(string $base_url, array $params, string $group): array {
        $url = add_query_arg($params, $base_url);
        $cache_key = 'donpesca_' . md5($group . '|' . $url);
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 20,
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                ],
            ]
        );

        if (is_wp_error($response)) {
            throw new RuntimeException('No se pudo consultar el servicio externo: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code >= 400 || !is_array($data)) {
            throw new RuntimeException('La API externa devolvió una respuesta inválida.');
        }

        set_transient($cache_key, $data, $this->cache_minutes() * MINUTE_IN_SECONDS);

        return $data;
    }

    private function cache_minutes(): int {
        $minutes = absint(get_option(self::OPTION_CACHE_MINUTES, 20));
        return $minutes > 0 ? $minutes : 20;
    }

    private function extract_iso_time(?string $raw): ?string {
        if (!$raw) {
            return null;
        }

        try {
            return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone(self::TIMEZONE))->format(DateTimeInterface::ATOM);
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function float_or_null($value): ?float {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function average(array $values): ?float {
        if ($values === []) {
            return null;
        }

        return array_sum($values) / count($values);
    }

    private function wave_energy(?float $wave_height, ?float $wave_period): ?float {
        if ($wave_height === null || $wave_period === null) {
            return null;
        }

        return round($wave_height * $wave_height * $wave_period, 1);
    }

    private function direction_penalty(?float $direction): int {
        if ($direction === null) {
            return 0;
        }

        if ($direction >= 310 && $direction <= 320) {
            return 3;
        }

        if ($direction >= 290 && $direction < 310) {
            return 2;
        }

        if ($direction > 320 && $direction <= 330) {
            return 1;
        }

        return 0;
    }

    private function moon_phase_label($value): ?string {
        if (!is_numeric($value)) {
            return null;
        }

        $phase = (float) $value;
        if ($phase <= 10 || $phase >= 350) {
            return 'Luna nueva';
        }
        if ($phase < 90) {
            return 'Creciente';
        }
        if ($phase < 170) {
            return 'Gibosa creciente';
        }
        if ($phase <= 190) {
            return 'Luna llena';
        }
        if ($phase < 270) {
            return 'Gibosa menguante';
        }

        return 'Menguante';
    }

    private function moon_fishing_note($value): ?string {
        if (!is_numeric($value)) {
            return null;
        }

        $phase = (float) $value;
        if ($phase <= 25 || $phase >= 335) {
            return 'Luna nueva: mareas vivas, noches oscuras y más interés para lubina, pelágicos en luces y fondo nocturno.';
        }
        if ($phase >= 155 && $phase <= 205) {
            return 'Luna llena: mareas vivas y más luz nocturna; suele ayudar mucho en chipirón y calamar.';
        }
        if (($phase >= 70 && $phase <= 110) || ($phase >= 250 && $phase <= 290)) {
            return 'Cuartos: corrientes más suaves y aguas más limpias, mejor contexto para dorada o pesca de fondo.';
        }

        return 'Fase intermedia: gana valor si coincide con buen cambio de luz o de marea.';
    }

    private static function ports_catalog(): array {
        return [
            'Hondarribia' => ['name' => 'Hondarribia', 'region' => 'Gipuzkoa', 'latitude' => 43.3682, 'longitude' => -1.7920],
            'Pasaia' => ['name' => 'Pasaia', 'region' => 'Gipuzkoa', 'latitude' => 43.3258, 'longitude' => -1.9276],
            'Donostia' => ['name' => 'Donostia', 'region' => 'Gipuzkoa', 'latitude' => 43.3223, 'longitude' => -1.9840],
            'Orio' => ['name' => 'Orio', 'region' => 'Gipuzkoa', 'latitude' => 43.2782, 'longitude' => -2.1268],
            'Getaria' => ['name' => 'Getaria', 'region' => 'Gipuzkoa', 'latitude' => 43.3036, 'longitude' => -2.2045],
            'Zumaia' => ['name' => 'Zumaia', 'region' => 'Gipuzkoa', 'latitude' => 43.2960, 'longitude' => -2.2570],
            'Deba' => ['name' => 'Deba', 'region' => 'Gipuzkoa', 'latitude' => 43.2954, 'longitude' => -2.3525],
            'Mutriku' => ['name' => 'Mutriku', 'region' => 'Gipuzkoa', 'latitude' => 43.3074, 'longitude' => -2.3853],
            'Ondarroa' => ['name' => 'Ondarroa', 'region' => 'Bizkaia', 'latitude' => 43.3201, 'longitude' => -2.4185],
            'Lekeitio' => ['name' => 'Lekeitio', 'region' => 'Bizkaia', 'latitude' => 43.3641, 'longitude' => -2.5038],
            'Ea' => ['name' => 'Ea', 'region' => 'Bizkaia', 'latitude' => 43.3815, 'longitude' => -2.5853],
            'Elantxobe' => ['name' => 'Elantxobe', 'region' => 'Bizkaia', 'latitude' => 43.4038, 'longitude' => -2.6396],
            'Mundaka' => ['name' => 'Mundaka', 'region' => 'Bizkaia', 'latitude' => 43.4074, 'longitude' => -2.6982],
            'Bermeo' => ['name' => 'Bermeo', 'region' => 'Bizkaia', 'latitude' => 43.4203, 'longitude' => -2.7216],
            'Armintza' => ['name' => 'Armintza', 'region' => 'Bizkaia', 'latitude' => 43.4384, 'longitude' => -2.9058],
            'Plentzia' => ['name' => 'Plentzia', 'region' => 'Bizkaia', 'latitude' => 43.4044, 'longitude' => -2.9479],
            'Santurtzi' => ['name' => 'Santurtzi', 'region' => 'Bizkaia', 'latitude' => 43.3270, 'longitude' => -3.0309],
            'Hendaia' => ['name' => 'Hendaia', 'region' => 'País Vasco francés', 'latitude' => 43.3729, 'longitude' => -1.7756],
            'San Juan de Luz' => ['name' => 'San Juan de Luz', 'region' => 'País Vasco francés', 'latitude' => 43.3894, 'longitude' => -1.6657],
            'Ciboure' => ['name' => 'Ciboure', 'region' => 'País Vasco francés', 'latitude' => 43.3842, 'longitude' => -1.6709],
            'Guethary' => ['name' => 'Guethary', 'region' => 'País Vasco francés', 'latitude' => 43.4227, 'longitude' => -1.6087],
            'Bidart' => ['name' => 'Bidart', 'region' => 'País Vasco francés', 'latitude' => 43.4378, 'longitude' => -1.5920],
            'Biarritz' => ['name' => 'Biarritz', 'region' => 'País Vasco francés', 'latitude' => 43.4832, 'longitude' => -1.5586],
            'Anglet' => ['name' => 'Anglet', 'region' => 'País Vasco francés', 'latitude' => 43.5197, 'longitude' => -1.5312],
            'Baiona' => ['name' => 'Baiona', 'region' => 'País Vasco francés', 'latitude' => 43.4929, 'longitude' => -1.4751],
        ];
    }
}

DonPesca_Mar_Forecast::instance();
