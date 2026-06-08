<?php
/**
 * Plugin Name: DonPesca Mar Forecast
 * Plugin URI: https://donpesca.com
 * Description: Informe marino visual para DonPesca con acceso restringido por email, puertos del País Vasco y análisis de ventanas de pesca.
 * Version: 0.1.0
 * Author: Codex para DonPesca
 * Text Domain: donpesca-mar-forecast
 */

if (!defined('ABSPATH')) {
    exit;
}

final class DonPesca_Mar_Forecast {
    private const OPTION_ALLOWED_EMAILS = 'donpesca_mar_allowed_emails';
    private const OPTION_CACHE_MINUTES = 'donpesca_mar_cache_minutes';
    private const AJAX_ACTION = 'donpesca_mar_forecast';
    private const NONCE_ACTION = 'donpesca_mar_nonce';
    private const TIMEZONE = 'Europe/Madrid';
    private const OPEN_METEO_FORECAST = 'https://api.open-meteo.com/v1/forecast';
    private const OPEN_METEO_GFS = 'https://api.open-meteo.com/v1/gfs';
    private const OPEN_METEO_ECMWF = 'https://api.open-meteo.com/v1/ecmwf';
    private const OPEN_METEO_METEOFRANCE = 'https://api.open-meteo.com/v1/meteofrance';
    private const OPEN_METEO_MARINE = 'https://marine-api.open-meteo.com/v1/marine';
    private const MET_SUN_URL = 'https://api.met.no/weatherapi/sunrise/3.0/sun';
    private const MET_MOON_URL = 'https://api.met.no/weatherapi/sunrise/3.0/moon';
    private const USER_AGENT = 'DonPescaMarForecast/0.1 (+https://donpesca.com)';

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
            '0.1.0'
        );

        wp_register_script(
            'donpesca-mar-forecast',
            $base_url . 'app.js',
            [],
            '0.1.0',
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

        $emails = array_values(array_unique($emails));

        return implode("\n", $emails);
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $ports = self::ports_catalog();
        ?>
        <div class="wrap">
            <h1>DonPesca Mar Forecast</h1>
            <p>Configura qué usuarios pueden ver el parte y el tiempo de caché de las consultas externas.</p>
            <form method="post" action="options.php">
                <?php settings_fields('donpesca_mar_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="donpesca_mar_allowed_emails">Emails autorizados</label></th>
                        <td>
                            <textarea id="donpesca_mar_allowed_emails" name="<?php echo esc_attr(self::OPTION_ALLOWED_EMAILS); ?>" rows="10" cols="50" class="large-text code"><?php echo esc_textarea((string) get_option(self::OPTION_ALLOWED_EMAILS, '')); ?></textarea>
                            <p class="description">Un email por línea. Solo usuarios logados con uno de estos correos verán el informe.</p>
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
            <p>Inserta esto en la página privada de donpesca.com:</p>
            <code>[donpesca_mar_report]</code>

            <h2>Puertos incluidos</h2>
            <p><?php echo esc_html(implode(', ', array_keys($ports))); ?></p>
        </div>
        <?php
    }

    public function render_shortcode(): string {
        if (!$this->user_is_allowed()) {
            return $this->render_gate_message();
        }

        wp_enqueue_style('donpesca-mar-forecast');
        wp_enqueue_script('donpesca-mar-forecast');

        $ports = self::ports_catalog();
        $default_port = isset($ports['Zumaia']) ? 'Zumaia' : array_key_first($ports);
        $default_when = wp_date('Y-m-d\TH:i', time(), wp_timezone());

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
                        <p>Análisis visual de mar, viento, mareas, luna y ventanas de pesca con criterio conservador y lectura de consenso entre modelos.</p>
                    </div>
                    <div class="donpesca-mar__badge-stack">
                        <span class="donpesca-mar__badge">Acceso privado</span>
                        <span class="donpesca-mar__badge donpesca-mar__badge--ghost">48 h de foco útil</span>
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
                        <p>Si rellenas coordenadas, tendrán prioridad sobre el puerto.</p>
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
        return '<section class="donpesca-mar donpesca-mar--locked"><div class="donpesca-mar__lock"><h3>Acceso restringido</h3><p>Este informe solo está disponible para usuarios autorizados por email dentro de donpesca.com.</p></div></section>';
    }

    public function handle_ajax_forecast(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!$this->user_is_allowed()) {
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
            wp_send_json_error(
                [
                    'message' => $exception->getMessage(),
                ],
                500
            );
        }
    }

    private function user_is_allowed(): bool {
        if (!is_user_logged_in()) {
            return false;
        }

        $current_user = wp_get_current_user();
        $email = strtolower((string) $current_user->user_email);
        $allowed = $this->allowed_emails();

        return $email !== '' && in_array($email, $allowed, true);
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
        if ($raw === '') {
            return new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
        }

        $timezone = new DateTimeZone(self::TIMEZONE);
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, $timezone);

        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException('La fecha de referencia no tiene un formato válido.');
        }

        return $date;
    }

    private function build_forecast_payload(array $location, DateTimeImmutable $reference): array {
        $weather_models = $this->fetch_weather_models($location, $reference);
        $marine = $this->fetch_marine_forecast($location, $reference);
        $astronomy = $this->fetch_astronomy($location, $reference);
        $windows = $this->build_windows($weather_models, $marine, $astronomy, $reference);

        if ($windows === []) {
            throw new RuntimeException('No se han encontrado datos suficientes para construir ventanas útiles.');
        }

        $best_window = $windows[0];
        $tide_turns = $this->find_tide_turns($marine['times'], $marine['sea_level']);
        $tide_context = $this->tide_context($reference, $tide_turns);
        $fishing_fit = $this->classify_fishing_fit($best_window);
        $summary = $this->build_executive_summary($best_window, $fishing_fit);

        return [
            'generatedAt' => wp_date('c', time(), wp_timezone()),
            'reference' => $reference->format(DateTimeInterface::ATOM),
            'location' => $location,
            'summary' => $summary,
            'bestWindow' => $best_window,
            'windows' => array_slice($windows, 0, 6),
            'astronomy' => $astronomy,
            'tides' => [
                'currentLevel' => $this->current_series_value($reference, $marine['times'], $marine['sea_level']),
                'previousTurn' => $tide_context['previous'],
                'nextTurn' => $tide_context['next'],
                'turns' => array_slice($tide_turns, 0, 8),
                'disclaimer' => 'La marea es una estimación modelizada de Open-Meteo. Úsala como apoyo, no como sustituto del anuario ni del parte portuario.',
            ],
            'consensus' => [
                'windModels' => $weather_models['meta'],
                'confidenceFormula' => 'La confianza baja cuando divergen AROME/ARPEGE, ECMWF y GFS o cuando el horizonte temporal se aleja.',
            ],
            'notes' => [
                'Se aplica una lectura prudente: para decidir salida se pondera el escenario más duro de viento y rachas.',
                'La ventana operativa principal es 24-48 horas; más allá debe interpretarse como tendencia.',
                'El mar con periodo largo y dirección 310-320° penaliza la seguridad en costa aunque la altura no parezca extrema.',
            ],
        ];
    }

    private function fetch_weather_models(array $location, DateTimeImmutable $reference): array {
        $hourly = ['wind_speed_10m', 'wind_gusts_10m', 'wind_direction_10m'];
        $forecast_days = 4;
        $params = [
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'hourly' => implode(',', $hourly),
            'wind_speed_unit' => 'kn',
            'timezone' => self::TIMEZONE,
            'forecast_days' => $forecast_days,
        ];

        $endpoints = [
            'Meteo-France' => self::OPEN_METEO_METEOFRANCE,
            'ECMWF' => self::OPEN_METEO_ECMWF,
            'GFS' => self::OPEN_METEO_GFS,
        ];

        $series = [];
        $meta = [];

        foreach ($endpoints as $label => $endpoint) {
            $data = $this->cached_json_request($endpoint, $params, 'weather');
            $hourly_data = $data['hourly'] ?? [];
            $times = $hourly_data['time'] ?? [];

            $series[$label] = [
                'time' => $times,
                'wind' => $hourly_data['wind_speed_10m'] ?? [],
                'gust' => $hourly_data['wind_gusts_10m'] ?? [],
                'direction' => $hourly_data['wind_direction_10m'] ?? [],
            ];

            $meta[] = [
                'name' => $label,
                'forecastDays' => $forecast_days,
                'samples' => is_array($times) ? count($times) : 0,
            ];
        }

        return [
            'series' => $series,
            'meta' => $meta,
            'reference' => $reference->format(DateTimeInterface::ATOM),
        ];
    }

    private function fetch_marine_forecast(array $location, DateTimeImmutable $reference): array {
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
            'forecast_days' => 4,
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
            'reference' => $reference->format(DateTimeInterface::ATOM),
        ];
    }

    private function fetch_astronomy(array $location, DateTimeImmutable $reference): array {
        $days = [];

        for ($offset = 0; $offset < 3; $offset++) {
            $day = $reference->setTime(12, 0)->modify('+' . $offset . ' day');
            $date = $day->format('Y-m-d');
            $offset_string = $day->format('P');
            $base_params = [
                'lat' => $location['latitude'],
                'lon' => $location['longitude'],
                'date' => $date,
                'offset' => $offset_string,
            ];

            $sun = $this->cached_json_request(self::MET_SUN_URL, $base_params, 'sun');
            $moon = $this->cached_json_request(self::MET_MOON_URL, $base_params, 'moon');
            $sun_props = $sun['properties'] ?? [];
            $moon_props = $moon['properties'] ?? [];
            $moon_phase = $moon_props['moonphase'] ?? null;
            $moon_value = is_array($moon_phase) ? ($moon_phase['value'] ?? null) : $moon_phase;

            $days[] = [
                'date' => $date,
                'sunrise' => $this->extract_iso_time($sun_props['sunrise']['time'] ?? null),
                'sunset' => $this->extract_iso_time($sun_props['sunset']['time'] ?? null),
                'moonrise' => $this->extract_iso_time($moon_props['moonrise']['time'] ?? null),
                'moonset' => $this->extract_iso_time($moon_props['moonset']['time'] ?? null),
                'moonPhaseValue' => $moon_value,
                'moonPhaseLabel' => $this->moon_phase_label($moon_value),
                'moonFishingNote' => $this->moon_fishing_note($moon_value),
            ];
        }

        return $days;
    }

    private function build_windows(array $weather_models, array $marine, array $astronomy, DateTimeImmutable $reference): array {
        $times = $marine['times'];
        $windows = [];
        $tide_turns = $this->find_tide_turns($marine['times'], $marine['sea_level']);

        foreach ($times as $index => $iso_time) {
            $time = new DateTimeImmutable($iso_time, new DateTimeZone(self::TIMEZONE));
            $hours_ahead = ($time->getTimestamp() - $reference->getTimestamp()) / 3600;

            if ($hours_ahead < 0 || $hours_ahead > 48) {
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
            $danger_dir = $this->direction_penalty($wave_direction);
            $worst_wind = max($wind_samples);
            $worst_gust = max($gust_samples);
            $avg_wind = $this->average($wind_samples);
            $avg_gust = $this->average($gust_samples);
            $avg_direction = $this->average($direction_samples);
            $tide_context = $this->tide_context($time, $tide_turns);
            $fishing_score = $this->fishing_window_score(
                $time,
                $worst_wind,
                $worst_gust,
                $wave_height,
                $wave_period,
                $energy,
                $consensus,
                $astronomy_day,
                $tide_context
            );
            $sea_state = $this->classify_sea_state($worst_wind, $worst_gust, $wave_height, $energy, $danger_dir, $consensus);

            $windows[] = [
                'time' => $time->format(DateTimeInterface::ATOM),
                'timeLabel' => wp_date('D j M H:i', $time->getTimestamp(), wp_timezone()),
                'hoursAhead' => round($hours_ahead, 1),
                'status' => $sea_state['status'],
                'headline' => $sea_state['headline'],
                'reason' => $sea_state['reason'],
                'confidence' => $consensus,
                'fishingScore' => $fishing_score,
                'windWorst' => round($worst_wind, 1),
                'windAvg' => round($avg_wind, 1),
                'gustWorst' => round($worst_gust, 1),
                'gustAvg' => round($avg_gust, 1),
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
            $windows,
            static function (array $a, array $b): int {
                return ($b['fishingScore'] <=> $a['fishingScore']) ?: ($b['confidence'] <=> $a['confidence']);
            }
        );

        return $this->deduplicate_windows($windows);
    }

    private function build_executive_summary(array $window, array $fishing_fit): array {
        $texts = [];
        $texts[] = $window['status'] === 'VERDE'
            ? 'Ventana razonable para salir si el parte real de última hora confirma.'
            : ($window['status'] === 'AMARILLO'
                ? 'Hay opción, pero con margen corto y necesidad de validar in situ.'
                : 'No es una ventana limpia; pesa más el criterio de seguridad que el de pesca.');

        if ($window['confidence'] < 60) {
            $texts[] = 'Los modelos no convergen bien, así que la probabilidad de acierto baja.';
        } else {
            $texts[] = 'La coincidencia entre modelos es aceptable para trabajar esta ventana.';
        }

        if ($window['waveEnergy'] !== null && $window['waveEnergy'] >= 22) {
            $texts[] = 'Aunque la ola no parezca enorme, el periodo le mete energía y endurece el mar.';
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
        $label = 'Flojo';
        $reason = 'La combinación entre mar, viento y momento biológico no destaca.';

        if ($score >= 78) {
            $label = 'Muy buena';
            $reason = 'Cuadra bastante bien seguridad, actividad potencial y confianza del parte.';
        } elseif ($score >= 62) {
            $label = 'Interesante';
            $reason = 'Puede encajar para pescar si ajustas zona y táctica.';
        } elseif ($score >= 45) {
            $label = 'Justa';
            $reason = 'Solo compensa si buscas una salida corta y muy controlada.';
        }

        return [
            'score' => round($score, 1),
            'label' => $label,
            'reason' => $reason,
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

        return [
            'previous' => $previous,
            'next' => $next,
        ];
    }

    private function fishing_window_score(
        DateTimeImmutable $time,
        float $wind,
        float $gust,
        ?float $wave_height,
        ?float $wave_period,
        ?float $energy,
        int $confidence,
        array $astronomy_day,
        array $tide_context
    ): float {
        $score = 100.0;

        $score -= max(0.0, $wind - 8.0) * 3.6;
        $score -= max(0.0, $gust - 14.0) * 2.1;
        $score -= max(0.0, ($wave_height ?? 0.0) - 0.8) * 28.0;
        $score -= max(0.0, ($energy ?? 0.0) - 12.0) * 1.1;
        $score += max(0, $confidence - 50) * 0.4;

        if ($wave_period !== null && $wave_period >= 11.0) {
            $score -= 8.0;
        }

        foreach (['sunrise', 'sunset', 'moonrise', 'moonset'] as $event_key) {
            if (empty($astronomy_day[$event_key])) {
                continue;
            }
            $event = new DateTimeImmutable($astronomy_day[$event_key]);
            $delta = abs($event->getTimestamp() - $time->getTimestamp()) / 60;
            if ($delta <= 90) {
                $score += 10.0;
                break;
            }
        }

        foreach (['previous', 'next'] as $turn_key) {
            if (empty($tide_context[$turn_key]['time'])) {
                continue;
            }
            $turn = new DateTimeImmutable($tide_context[$turn_key]['time']);
            $delta = abs($turn->getTimestamp() - $time->getTimestamp()) / 60;
            if ($delta <= 90) {
                $score += 12.0;
                break;
            }
        }

        $moon_value = $astronomy_day['moonPhaseValue'] ?? null;
        if (is_numeric($moon_value)) {
            $moon = (float) $moon_value;
            if ($moon <= 25 || $moon >= 335 || ($moon >= 155 && $moon <= 205)) {
                $score += 6.0;
            }
        }

        return max(0.0, min(100.0, round($score, 1)));
    }

    private function classify_sea_state(
        float $wind,
        float $gust,
        ?float $wave_height,
        ?float $energy,
        int $direction_penalty,
        int $confidence
    ): array {
        $status = 'VERDE';
        $headline = 'Cuadra bien para revisar salida';
        $reasons = [];

        if ($wave_height !== null && $wave_height > 1.5) {
            $status = 'AMARILLO';
            $reasons[] = 'ola ya cerca del límite operativo';
        }
        if ($wind > 15.0 || $gust > 22.0) {
            $status = 'AMARILLO';
            $reasons[] = 'viento o racha por encima del confort';
        }
        if (($wave_height !== null && $wave_height > 2.0) || $wind > 19.0 || $gust > 28.0) {
            $status = 'ROJO';
            $headline = 'No es ventana limpia para mar';
            $reasons[] = 'el peor escenario del viento o mar ya aprieta demasiado';
        }
        if ($energy !== null && $energy >= 24.0) {
            $status = $status === 'ROJO' ? 'ROJO' : 'AMARILLO';
            $reasons[] = 'el periodo mete energía y endurece el impacto';
        }
        if ($direction_penalty >= 2) {
            $status = $status === 'VERDE' ? 'AMARILLO' : $status;
            $reasons[] = 'dirección de mar muy incidente para esta costa';
        }
        if ($confidence < 55) {
            $status = $status === 'VERDE' ? 'AMARILLO' : $status;
            $reasons[] = 'los modelos no coinciden bien';
        }

        if ($status === 'VERDE') {
            $headline = 'Ventana razonable para pescar';
            $reasons[] = 'mar y viento contenidos con coincidencia aceptable';
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
        $score = 92;
        $score -= $hours_ahead * 0.65;
        $score -= $spread_wind * 3.2;
        $score -= $spread_gust * 1.6;
        $score -= (3 - count($wind_samples)) * 8;

        return (int) max(25, min(95, round($score)));
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
            return 'Luna nueva: noches oscuras y mareas vivas; suele ayudar si coincide con cambio de marea.';
        }
        if ($phase >= 155 && $phase <= 205) {
            return 'Luna llena: más luz nocturna y mareas vivas; puede mover actividad en amanecer, anochecer y luna.';
        }
        if (($phase >= 70 && $phase <= 110) || ($phase >= 250 && $phase <= 290)) {
            return 'Cuarto lunar: mareas algo más suaves y actividad menos explosiva.';
        }

        return 'Fase intermedia: gana peso cuando encaja con cambio de luz y marea.';
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
