<?php
/**
 * Plugin Name: SAL Core
 * Plugin URI:  https://hashtagsal.com.br
 * Description: Funcionalidades customizadas para o site #SAL: YouTube, Instagram, newsletter, integração com Apoia.se e endpoint de sinalização (SignalK).
 * Version:     0.4
 * Author:      HashtagSal
 * License:     GPL2
 */

// Não permita acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * Definições básicas do plugin. As constantes tornam fácil mudar
 * diretórios ou a versão sem ter que alterar múltiplos pontos de código.
 */
define( 'SAL_CORE_VERSION', '0.4' );
define( 'SAL_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAL_CORE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Função de ativação: cria a tabela de rastreamento para armazenar dados
 * de navegação enviados pelo barco (via SignalK).
 */
function sal_core_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name      = $wpdb->prefix . 'sal_track';
    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ts DATETIME NOT NULL,
        lat DOUBLE NULL,
        lon DOUBLE NULL,
        sog FLOAT NULL,
        cog FLOAT NULL,
        awa FLOAT NULL,
        aws FLOAT NULL,
        waterspeed FLOAT NULL,
        heading FLOAT NULL,
        batt FLOAT NULL,
        ais LONGTEXT NULL,
        depth FLOAT NULL,
        src VARCHAR(32) NULL,
        PRIMARY KEY  (id),
        KEY ts (ts)
    ) {$charset_collate};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
register_activation_hook( __FILE__, 'sal_core_activate' );

/**
 * Regista rotas REST personalizadas para ingestão de dados e para
 * disponibilizar o último ponto (usado pelo dashboard em tempo real).
 *
 * O endpoint /sk (ingestão) exige autenticação via header X-SAL-API-Key.
 * O endpoint /last permanece público (consumido pelo dashboard front-end).
 */
function sal_core_register_rest() {
    register_rest_route( 'sal/v1', '/sk', array(
        'methods'  => 'POST',
        'callback' => 'sal_core_handle_sk_data',
        'permission_callback' => 'sal_core_sk_permission_check',
    ) );
    register_rest_route( 'sal/v1', '/last', array(
        'methods'  => 'GET',
        'callback' => 'sal_core_get_last_point',
        'permission_callback' => '__return_true',
    ) );
}
add_action( 'rest_api_init', 'sal_core_register_rest' );

/**
 * Verifica a API key no header X-SAL-API-Key contra a chave salva nas opções.
 * Comparação em tempo constante via hash_equals para evitar timing attacks.
 *
 * @param WP_REST_Request $request
 * @return true|WP_Error
 */
function sal_core_sk_permission_check( WP_REST_Request $request ) {
    $expected = trim( (string) get_option( 'sal_core_sk_api_key', '' ) );
    if ( $expected === '' ) {
        return new WP_Error(
            'sal_no_key_configured',
            'API key não configurada. Acesse Configurações → SAL Core para gerar uma.',
            array( 'status' => 503 )
        );
    }
    $provided = $request->get_header( 'x_sal_api_key' );
    if ( ! $provided ) {
        $provided = $request->get_header( 'X-SAL-API-Key' );
    }
    if ( ! $provided || ! is_string( $provided ) ) {
        return new WP_Error( 'sal_auth_missing', 'Header X-SAL-API-Key ausente.', array( 'status' => 401 ) );
    }
    if ( ! hash_equals( $expected, trim( $provided ) ) ) {
        return new WP_Error( 'sal_auth_invalid', 'API key inválida.', array( 'status' => 403 ) );
    }
    return true;
}

/**
 * Helper: valida campo numérico. Retorna float se válido, null se ausente,
 * false se presente mas inválido (tipo errado, NaN/inf, fora do intervalo).
 */
function sal_core_valid_num( $v, $min = null, $max = null ) {
    if ( $v === null || $v === '' ) {
        return null;
    }
    if ( ! is_numeric( $v ) ) {
        return false;
    }
    $f = floatval( $v );
    if ( ! is_finite( $f ) ) {
        return false;
    }
    if ( $min !== null && $f < $min ) {
        return false;
    }
    if ( $max !== null && $f > $max ) {
        return false;
    }
    return $f;
}

/**
 * Processa os dados JSON enviados do barco. Cada campo numérico é validado
 * dentro de um intervalo plausível; valores fora do intervalo fazem o request
 * falhar com 400 para evitar lixo na base.
 *
 * @param WP_REST_Request $request
 * @return array|WP_REST_Response
 */
function sal_core_handle_sk_data( WP_REST_Request $request ) {
    $data = $request->get_json_params();
    if ( empty( $data ) || ! is_array( $data ) ) {
        return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Payload vazio' ), 400 );
    }

    // Validação por campo (cada um pode ser null se ausente).
    $fields = array(
        'lat'        => array( -90,    90 ),
        'lon'        => array( -180,   180 ),
        'sog'        => array( 0,      100 ),   // nós
        'cog'        => array( 0,      360 ),   // graus
        'awa'        => array( -180,   180 ),   // graus
        'aws'        => array( 0,      200 ),   // nós
        'waterspeed' => array( 0,      100 ),
        'heading'    => array( 0,      360 ),
        'batt'       => array( 0,      200 ),   // volts (margem ampla)
        'depth'      => array( 0,      12000 ), // metros
    );
    $clean = array();
    foreach ( $fields as $name => $range ) {
        $v = sal_core_valid_num( isset( $data[ $name ] ) ? $data[ $name ] : null, $range[0], $range[1] );
        if ( $v === false ) {
            return new WP_REST_Response( array( 'status' => 'error', 'message' => "Campo {$name} fora do intervalo válido" ), 400 );
        }
        $clean[ $name ] = $v;
    }

    // Timestamp.
    if ( isset( $data['ts'] ) ) {
        $ts_unix = strtotime( (string) $data['ts'] );
        if ( ! $ts_unix ) {
            return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Campo ts inválido' ), 400 );
        }
        $ts = gmdate( 'Y-m-d H:i:s', $ts_unix );
    } else {
        $ts = current_time( 'mysql', 1 );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'sal_track';
    $row = array(
        'ts'         => $ts,
        'lat'        => $clean['lat'],
        'lon'        => $clean['lon'],
        'sog'        => $clean['sog'],
        'cog'        => $clean['cog'],
        'awa'        => $clean['awa'],
        'aws'        => $clean['aws'],
        'waterspeed' => $clean['waterspeed'],
        'heading'    => $clean['heading'],
        'batt'       => $clean['batt'],
        'ais'        => isset( $data['ais'] ) ? wp_json_encode( $data['ais'] ) : null,
        'depth'      => $clean['depth'],
        'src'        => isset( $data['src'] ) ? sanitize_text_field( (string) $data['src'] ) : null,
    );
    $wpdb->insert( $table_name, $row );
    return array( 'ok' => true, 'id' => $wpdb->insert_id );
}

/**
 * Devolve o último ponto armazenado na tabela sal_track. Se não houver
 * dados, retorna 404.
 *
 * @return array|
 */
function sal_core_get_last_point() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sal_track';
    $row = $wpdb->get_row( "SELECT * FROM {$table_name} ORDER BY ts DESC, id DESC LIMIT 1", ARRAY_A );
    if ( ! $row ) {
        return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Sem dados' ), 404 );
    }
    return $row;
}

/**
 * Enfileira estilos e scripts usados pelos shortcodes. O CSS próprio do
 * plugin e os JS de Leaflet/Chart.js são carregados apenas no front-end.
 */
function sal_core_enqueue_assets() {
    // Inclui a folha de estilos do plugin.
    wp_enqueue_style( 'sal-core-css', SAL_CORE_URL . 'css/sal-core.css', array(), SAL_CORE_VERSION );
    // Prepare scripts para balanço; eles serão enfileirados apenas quando o shortcode é usado.
    wp_register_style( 'sal-core-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
    wp_register_script( 'sal-core-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );
    wp_register_script( 'sal-core-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4', true );
    wp_register_script( 'sal-core-balance', SAL_CORE_URL . 'js/balance.js', array( 'jquery', 'sal-core-leaflet', 'sal-core-chartjs' ), SAL_CORE_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'sal_core_enqueue_assets' );

/**
 * Regista as opções do plugin. Isso permite que o usuário configure
 * chaves de API, lista de posts do Instagram e Mailchimp action.
 */
function sal_core_register_settings() {
    register_setting( 'sal_core_settings', 'sal_core_youtube_api_key' );
    register_setting( 'sal_core_settings', 'sal_core_youtube_channel_id' );
    register_setting( 'sal_core_settings', 'sal_core_instagram_posts' );
    register_setting( 'sal_core_settings', 'sal_core_mailchimp_action' );
    register_setting( 'sal_core_settings', 'sal_core_apoia_campaign' );
    register_setting( 'sal_core_settings', 'sal_core_apoia_key' );
    register_setting( 'sal_core_settings', 'sal_core_apoia_secret' );
    register_setting( 'sal_core_settings', 'sal_core_sk_api_key' );
}
add_action( 'admin_init', 'sal_core_register_settings' );

/**
 * Gera automaticamente uma API key para o endpoint de ingestão /sk se ainda
 * não existir uma configurada. Rodado apenas no admin para não atrasar o
 * front-end.
 */
function sal_core_ensure_sk_api_key() {
    $key = get_option( 'sal_core_sk_api_key' );
    if ( empty( $key ) ) {
        $key = wp_generate_password( 40, false, false );
        update_option( 'sal_core_sk_api_key', $key, false );
    }
}
add_action( 'admin_init', 'sal_core_ensure_sk_api_key' );

/**
 * Trata o botão "Gerar nova chave" via admin-post. Sobrescreve a chave atual
 * por uma nova aleatória. Exige permissão manage_options e nonce válido.
 */
function sal_core_handle_regenerate_sk_key() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Sem permissão.' );
    }
    check_admin_referer( 'sal_core_regenerate_sk_key' );
    $new_key = wp_generate_password( 40, false, false );
    update_option( 'sal_core_sk_api_key', $new_key, false );
    wp_safe_redirect( add_query_arg( array( 'page' => 'sal_core_settings', 'sal_sk_regenerated' => 1 ), admin_url( 'options-general.php' ) ) );
    exit;
}
add_action( 'admin_post_sal_core_regenerate_sk_key', 'sal_core_handle_regenerate_sk_key' );

/**
 * Adiciona a página de configurações ao menu de opções do WordPress.
 */
function sal_core_add_settings_page() {
    add_options_page( 'SAL Core', 'SAL Core', 'manage_options', 'sal_core_settings', 'sal_core_render_settings_page' );
}
add_action( 'admin_menu', 'sal_core_add_settings_page' );

/**
 * Renderiza a página de configurações. Utiliza campos simples para todas as
 * opções do plugin.
 */
function sal_core_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Configurações do SAL Core</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'sal_core_settings' ); ?>
            <h2>YouTube</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="sal_core_youtube_api_key">API Key</label></th>
                    <td><input type="text" id="sal_core_youtube_api_key" name="sal_core_youtube_api_key" value="<?php echo esc_attr( get_option( 'sal_core_youtube_api_key' ) ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="sal_core_youtube_channel_id">Channel ID</label></th>
                    <td><input type="text" id="sal_core_youtube_channel_id" name="sal_core_youtube_channel_id" value="<?php echo esc_attr( get_option( 'sal_core_youtube_channel_id' ) ); ?>" class="regular-text" /></td>
                </tr>
            </table>
            <h2>Instagram</h2>
            <p>Insira as URLs completas das publicações, separadas por vírgula.</p>
            <textarea name="sal_core_instagram_posts" rows="4" class="large-text"><?php echo esc_textarea( get_option( 'sal_core_instagram_posts' ) ); ?></textarea>
            <h2>Newsletter (Mailchimp)</h2>
            <p>Cole a URL de ação do seu formulário embed (atributo action).</p>
            <input type="text" name="sal_core_mailchimp_action" value="<?php echo esc_attr( get_option( 'sal_core_mailchimp_action' ) ); ?>" class="regular-text code" size="80" />
            <h2>Apoia.se</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="sal_core_apoia_campaign">Campanha</label></th>
                    <td><input type="text" id="sal_core_apoia_campaign" name="sal_core_apoia_campaign" value="<?php echo esc_attr( get_option( 'sal_core_apoia_campaign' ) ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="sal_core_apoia_key">API Key</label></th>
                    <td><input type="text" id="sal_core_apoia_key" name="sal_core_apoia_key" value="<?php echo esc_attr( get_option( 'sal_core_apoia_key' ) ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="sal_core_apoia_secret">Secret (JWT)</label></th>
                    <td><input type="text" id="sal_core_apoia_secret" name="sal_core_apoia_secret" value="<?php echo esc_attr( get_option( 'sal_core_apoia_secret' ) ); ?>" class="regular-text code" size="80" /></td>
                </tr>
            </table>

            <h2>Telemetria SignalK</h2>
            <?php if ( isset( $_GET['sal_sk_regenerated'] ) ) : ?>
                <div class="notice notice-success"><p>Nova API key gerada. Atualize o cliente que envia telemetria do barco.</p></div>
            <?php endif; ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="sal_core_sk_api_key">API key (header <code>X-SAL-API-Key</code>)</label></th>
                    <td>
                        <input type="text" id="sal_core_sk_api_key" name="sal_core_sk_api_key" value="<?php echo esc_attr( get_option( 'sal_core_sk_api_key' ) ); ?>" class="regular-text code" size="80" readonly />
                        <p class="description">
                            Use esta chave no header <code>X-SAL-API-Key</code> ao fazer <code>POST /wp-json/sal/v1/sk</code>.
                            Sem header válido o endpoint responde 401/403.
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;" onsubmit="return confirm('Gerar nova API key vai invalidar a atual. Confirmar?');">
            <input type="hidden" name="action" value="sal_core_regenerate_sk_key" />
            <?php wp_nonce_field( 'sal_core_regenerate_sk_key' ); ?>
            <?php submit_button( 'Gerar nova API key', 'secondary', 'submit', false ); ?>
        </form>
    </div>
    <?php
}

/**
 * Adiciona um link rápido de "Configurações" na listagem de plugins.
 */
function sal_core_plugin_action_links( $links ) {
    $url = admin_url( 'options-general.php?page=sal_core_settings' );
    $links[] = '<a href="' . esc_url( $url ) . '">' . __( 'Configurações', 'sal-core' ) . '</a>';
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'sal_core_plugin_action_links' );

/**
 * Shortcode para o dashboard de balanço. Carrega Leaflet e Chart.js,
 * localiza a URL do endpoint /last e insere contêineres para mapa e gráfico.
 */
function sal_core_balance_shortcode( $atts ) {
    // Garante que os scripts necessários são enfileirados.
    wp_enqueue_style( 'sal-core-leaflet' );
    wp_enqueue_script( 'sal-core-leaflet' );
    wp_enqueue_script( 'sal-core-chartjs' );
    wp_enqueue_script( 'sal-core-balance' );
    // Passa a URL do endpoint via localize.
    wp_localize_script( 'sal-core-balance', 'salCore', array( 'restLast' => esc_url_raw( rest_url( 'sal/v1/last' ) ) ) );
    ob_start();
    ?>
    <div id="sal-balance-map" style="height:360px;margin:1rem 0;"></div>
    <canvas id="sal-balance-speed" style="width:100%;height:200px;"></canvas>
    <?php
    return ob_get_clean();
}
add_shortcode( 'sal_balance', 'sal_core_balance_shortcode' );

/**
 * Shortcode para exibir vídeos mais recentes do YouTube. Usa a API oficial
 * quando configurada; caso contrário, recorre ao feed RSS do canal.
 */
function sal_core_youtube_shortcode( $atts ) {
    $args = shortcode_atts( array( 'count' => 6 ), $atts );
    $count = max( 1, intval( $args['count'] ) );
    $api_key    = trim( get_option( 'sal_core_youtube_api_key' ) );
    $channel_id = trim( get_option( 'sal_core_youtube_channel_id' ) );
    $videos = array();
    if ( $api_key && $channel_id ) {
        $url = add_query_arg( array(
            'part'       => 'snippet',
            'channelId'  => $channel_id,
            'order'      => 'date',
            'maxResults' => $count,
            'type'       => 'video',
            'key'        => $api_key,
        ), 'https://www.googleapis.com/youtube/v3/search' );
        $response = wp_remote_get( $url, array( 'timeout' => 10 ) );
        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );
            if ( ! empty( $data['items'] ) && is_array( $data['items'] ) ) {
                foreach ( $data['items'] as $item ) {
                    if ( isset( $item['id']['videoId'] ) ) {
                        $id    = $item['id']['videoId'];
                        $title = $item['snippet']['title'];
                        $videos[] = array(
                            'id'    => $id,
                            'title' => $title,
                        );
                        if ( count( $videos ) >= $count ) {
                            break;
                        }
                    }
                }
            }
        }
    }
    // Fallback via RSS se API indisponível ou sem chave
    if ( ! $videos ) {
        $channel = $channel_id ?: 'UCZ9bK5YKp6-sRPY1lgdPb5w';
        $feed_url = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . urlencode( $channel );
        $response = wp_remote_get( $feed_url, array( 'timeout' => 10 ) );
        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            $xml = simplexml_load_string( wp_remote_retrieve_body( $response ) );
            if ( $xml && isset( $xml->entry ) ) {
                foreach ( $xml->entry as $entry ) {
                    $ns   = $entry->children( 'http://www.youtube.com/xml/schemas/2015' );
                    $id   = (string) $ns->videoId;
                    $title = (string) $entry->title;
                    $videos[] = array( 'id' => $id, 'title' => $title );
                    if ( count( $videos ) >= $count ) {
                        break;
                    }
                }
            }
        }
    }
    if ( ! $videos ) {
        return '<p>Sem vídeos disponíveis.</p>';
    }
    ob_start();
    echo '<div class="sal-youtube-grid">';
    foreach ( $videos as $v ) {
        $id = esc_attr( $v['id'] );
        echo '<div class="sal-video"><iframe loading="lazy" src="https://www.youtube.com/embed/' . $id . '" title="YouTube video" frameborder="0" allowfullscreen></iframe></div>';
    }
    echo '</div>';
    return ob_get_clean();
}
add_shortcode( 'sal_youtube', 'sal_core_youtube_shortcode' );

/**
 * Shortcode para uma única publicação do Instagram via oEmbed.
 * Uso: [sal_instagram url="..."]
 */
function sal_core_instagram_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'url' => '' ), $atts );
    $url  = trim( $atts['url'] );
    if ( ! $url ) {
        return '';
    }
    $html = wp_oembed_get( $url );
    if ( ! $html ) {
        $html = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Ver no Instagram</a>';
    }
    return '<div class="sal-instagram-embed">' . $html . '</div>';
}
add_shortcode( 'sal_instagram', 'sal_core_instagram_shortcode' );

/**
 * Shortcode do formulário de newsletter. Requer que o campo de ação do
 * Mailchimp seja configurado nas opções.
 */
function sal_core_newsletter_shortcode() {
    $action = trim( get_option( 'sal_core_mailchimp_action' ) );
    if ( ! $action ) {
        return '<p>Configure o formulário de newsletter em Configurações → SAL Core.</p>';
    }
    ob_start();
    ?>
    <form action="<?php echo esc_url( $action ); ?>" method="post" target="_blank" class="sal-newsletter" novalidate>
        <input type="email" name="EMAIL" placeholder="Seu e-mail" required style="padding:10px;width:100%;max-width:420px;margin-right:8px;" />
        <button type="submit" style="padding:10px 16px;cursor:pointer;">Assinar</button>
        <div style="position:absolute; left:-5000px;" aria-hidden="true"><input type="text" name="b_<?php echo wp_rand(); ?>" tabindex="-1" value="" /></div>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode( 'sal_newsletter', 'sal_core_newsletter_shortcode' );

/**
 * Shortcode para a lista automática de posts do Instagram. Recebe opcionalmente
 * parâmetros de quantidade e colunas. Ex.: [sal_instagram_list count="6" columns="3"]
 */
function sal_core_instagram_list_shortcode( $atts ) {
    $args = shortcode_atts( array( 'count' => 6, 'columns' => 3 ), $atts );
    $list = trim( get_option( 'sal_core_instagram_posts' ) );
    if ( ! $list ) {
        return '<p>Nenhum post do Instagram configurado nas opções do SAL Core.</p>';
    }
    // Divide por vírgula e remove espaços vazios.
    $urls = array_filter( array_map( 'trim', explode( ',', $list ) ) );
    if ( empty( $urls ) ) {
        return '<p>Nenhum post do Instagram configurado.</p>';
    }
    $count  = max( 1, intval( $args['count'] ) );
    $cols   = max( 1, intval( $args['columns'] ) );
    $urls   = array_slice( $urls, 0, $count );
    ob_start();
    echo '<div class="sal-ig-grid" style="--sal-ig-cols:' . esc_attr( $cols ) . ';">';
    foreach ( $urls as $u ) {
        $key  = 'sal_ig_' . md5( $u );
        $html = get_transient( $key );
        if ( false === $html ) {
            $html = wp_oembed_get( $u );
            if ( ! $html ) {
                $html = '<a class="sal-ig-fallback" href="' . esc_url( $u ) . '" target="_blank" rel="noopener">Ver no Instagram</a>';
            }
            set_transient( $key, $html, 12 * HOUR_IN_SECONDS );
        }
        echo '<div class="sal-ig-item">' . $html . '</div>';
    }
    echo '</div>';
    return ob_get_clean();
}
add_shortcode( 'sal_instagram_list', 'sal_core_instagram_list_shortcode' );

/**
 * Shortcode para a página do Clube de Vantagens (Apoia.se). Permite
 * verificação de apoiadores através da API Apoia.se.
 */
function sal_core_club_shortcode() {
    ob_start();
    ?>
    <form method="get" action="" class="sal-club-form" style="margin-bottom:1rem;">
        <label for="sal-club-email">E-mail do apoiador:</label>
        <input type="email" id="sal-club-email" name="email" required />
        <button type="submit">Verificar</button>
    </form>
    <?php
    if ( isset( $_GET['email'] ) && is_email( $_GET['email'] ) ) {
        $email = sanitize_email( $_GET['email'] );
        $result = sal_core_check_supporter_status( $email );
        if ( is_wp_error( $result ) ) {
            echo '<p>Erro: ' . esc_html( $result->get_error_message() ) . '</p>';
        } else {
            if ( empty( $result ) ) {
                echo '<p>Não encontrado ou apoio inativo.</p>';
            } else {
                echo '<h3>Dados do apoiador</h3><ul>';
                echo '<li>Nome: ' . esc_html( $result['name'] ) . '</li>';
                echo '<li>E-mail: ' . esc_html( $result['email'] ) . '</li>';
                echo '<li>Status: ' . esc_html( $result['status'] ) . '</li>';
                echo '<li>Nível: ' . esc_html( $result['tier'] ) . '</li>';
                echo '<li>Desde: ' . esc_html( $result['since'] ) . '</li>';
                echo '</ul>';
            }
        }
    }
    return ob_get_clean();
}
add_shortcode( 'sal_club', 'sal_core_club_shortcode' );

/**
 * Função auxiliar que consulta a API do Apoia.se para verificar o status
 * de um apoiador. Retorna um array com os dados ou WP_Error em caso de erro.
 *
 * @param string $email
 * @return array|WP_Error
 */
function sal_core_check_supporter_status( $email ) {
    $campaign = trim( get_option( 'sal_core_apoia_campaign' ) );
    $api_key  = trim( get_option( 'sal_core_apoia_key' ) );
    $secret   = trim( get_option( 'sal_core_apoia_secret' ) );
    if ( ! $campaign || ! $api_key || ! $secret ) {
        return new WP_Error( 'missing_keys', 'API do Apoia.se não configurada.' );
    }
    $url = 'https://api.apoia.se/backers/charges/' . urlencode( $email );
    $args = array(
        'headers' => array(
            'x-api-key'     => $api_key,
            'Authorization' => 'Bearer ' . $secret,
            'Accept'        => '*/*',
        ),
        'timeout' => 20,
    );
    $response = wp_remote_get( $url, $args );
    if ( is_wp_error( $response ) ) {
        return $response;
    }
    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    if ( 200 !== $code ) {
        return new WP_Error( 'api_error', 'Erro na API: ' . $code );
    }
    $data = json_decode( $body, true );
    if ( ! $data || empty( $data['data'] ) ) {
        return array();
    }
    $charge = $data['data'][0];
    return array(
        'name'  => isset( $charge['backer']['name'] ) ? $charge['backer']['name'] : '',
        'email' => $email,
        'status' => isset( $charge['status'] ) ? $charge['status'] : '',
        'tier'  => isset( $charge['reward_title'] ) ? $charge['reward_title'] : '',
        'since' => isset( $charge['created_at'] ) ? $charge['created_at'] : '',
    );
}

/**
 * Página de Setup: cria páginas padrão, define a Home e monta o menu.
 */
function sal_core_add_management_page() {
    add_management_page( 'Setup #SAL', 'Setup #SAL', 'manage_options', 'sal_core_setup', 'sal_core_setup_screen' );
}
add_action( 'admin_menu', 'sal_core_add_management_page' );

/**
 * Renderiza a tela de setup com um botão para gerar a estrutura.
 */
function sal_core_setup_screen() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( isset( $_GET['done'] ) ) {
        echo '<div class="notice notice-success"><p>Estrutura criada/atualizada.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Setup #SAL</h1>
        <p>Crie páginas base, defina a Home e monte o menu principal.</p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'sal_core_do_setup' ); ?>
            <input type="hidden" name="action" value="sal_core_do_setup" />
            <button class="button button-primary button-hero">Criar/Atualizar estrutura</button>
        </form>
    </div>
    <?php
}

/**
 * Processa o formulário de setup. Cria páginas padrão (Home, Quem Somos, Clube,
 * Acompanhe o Balanço, Vídeos, Contato, Política de Privacidade), define a
 * página inicial estática e constrói um menu principal.
 */
function sal_core_do_setup() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Sem permissão.' );
    }
    check_admin_referer( 'sal_core_do_setup' );
    // Helper: cria ou obtém o ID de uma página pelo slug.
    $make_page = function( $slug, $title, $content = '' ) {
        $page = get_page_by_path( $slug );
        if ( $page ) {
            // Atualiza conteúdo se já existir.
            wp_update_post( array( 'ID' => $page->ID, 'post_title' => $title, 'post_content' => $content ) );
            return $page->ID;
        }
        return wp_insert_post( array(
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_name'    => $slug,
            'post_title'   => $title,
            'post_content' => $content,
        ) );
    };
    // Conteúdos padrão.
    $grafana_url = 'https://plotzki.grafana.net/public-dashboards/1c2b821b965a4ba8b6cd7c5c13b6c266';
    $home_id = $make_page( 'home', 'Home', "<h2>Últimos vídeos</h2>\n[sal_youtube count=\"6\"]\n\n<h2>Instagram</h2>\n[sal_instagram_list count=\"6\" columns=\"3\"]\n\n<h2>Assine a newsletter</h2>\n[sal_newsletter]" );
    $qs_id  = $make_page( 'quem-somos', 'Quem Somos', 'Somos o #SAL — vida no mar, conhecimento e comunidade.' );
    $club_id = $make_page( 'clube-sal', 'Clube de Vantagens #SAL', 'Em breve novidades para os apoiadores do #SAL' );
    $bal_id = $make_page( 'balanco', 'Acompanhe o Balanço', "<p>Dados em tempo quase real:</p>\n[sal_balance]\n\n<iframe src=\"$grafana_url\" width=\"100%\" height=\"720\" style=\"border:0;border-radius:12px;\" loading=\"lazy\"></iframe>" );
    $vid_id = $make_page( 'videos', 'Vídeos', '[sal_youtube count="12"]' );
    $ctt_id = $make_page( 'contato', 'Contato', '[sal_newsletter]' );
    $pp_id  = $make_page( 'privacidade', 'Política de Privacidade', 'Esta é a nossa política de privacidade.' );
    // Define Home estática.
    update_option( 'show_on_front', 'page' );
    update_option( 'page_on_front', $home_id );
    // Menu principal.
    $menu_name = 'Principal';
    $menu_obj  = wp_get_nav_menu_object( $menu_name );
    if ( ! $menu_obj ) {
        $menu_id = wp_create_nav_menu( $menu_name );
    } else {
        $menu_id = $menu_obj->term_id;
    }
    // Lista de páginas para adicionar ao menu.
    $pages = array( $home_id, $qs_id, $club_id, $bal_id, $vid_id, $ctt_id );
    foreach ( $pages as $pid ) {
        $exists = false;
        $items  = wp_get_nav_menu_items( $menu_id );
        if ( $items ) {
            foreach ( $items as $item ) {
                if ( intval( $item->object_id ) === intval( $pid ) ) {
                    $exists = true;
                    break;
                }
            }
        }
        if ( ! $exists ) {
            wp_update_nav_menu_item( $menu_id, 0, array(
                'menu-item-object-id' => $pid,
                'menu-item-object'    => 'page',
                'menu-item-type'      => 'post_type',
                'menu-item-status'    => 'publish',
            ) );
        }
    }
    // Vincula ao local 'primary' se existir.
    $locations = get_theme_mod( 'nav_menu_locations' );
    if ( is_array( $locations ) ) {
        $locations['primary'] = $menu_id;
        set_theme_mod( 'nav_menu_locations', $locations );
    }
    wp_safe_redirect( add_query_arg( 'done', '1', admin_url( 'tools.php?page=sal_core_setup' ) ) );
    exit;
}
add_action( 'admin_post_sal_core_do_setup', 'sal_core_do_setup' );

/**
 * Opção de redirecionamento temporário: se definido, permite redirecionar
 * automaticamente visitantes da raiz para uma subpasta (por exemplo, /teste).
 * Só é aplicado a visitantes não autenticados e não interfere no admin.
 */
function sal_core_temp_redirect() {
    if ( is_admin() || is_user_logged_in() ) {
        return;
    }
    $uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = trim( parse_url( $uri, PHP_URL_PATH ), '/' );
    if ( '' === $path ) {
        $dest = defined( 'SAL_CORE_REDIRECT_PATH' ) ? SAL_CORE_REDIRECT_PATH : '/teste';
        $dest = '/' . ltrim( trim( $dest ), '/' );
        if ( '/' === $dest || $path === trim( $dest, '/' ) ) {
            return;
        }
        wp_redirect( home_url( $dest ), 302 );
        exit;
    }
}
add_action( 'template_redirect', 'sal_core_temp_redirect', 1 );

// Fim do arquivo