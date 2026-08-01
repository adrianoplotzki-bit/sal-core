<?php
/**
 * Plugin Name: SAL Core
 * Plugin URI:  https://hashtagsal.com.br
 * Description: Funcionalidades customizadas para o site #SAL: YouTube, Instagram, newsletter, integração com Apoia.se e telemetria do barco (SignalK).
 * Version:     0.15.1
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
define( 'SAL_CORE_VERSION', '0.15.1' );
// Versão do schema da wp_sal_track. Subir isto dispara a migração (ver
// sal_core_maybe_upgrade) no primeiro carregamento após o deploy.
define( 'SAL_CORE_DB_VERSION', '5' );
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

    // src é NOT NULL de propósito: em MySQL, dois NULL são considerados
    // distintos, então UNIQUE (ts, src) não barraria nada se src pudesse ser
    // nulo — e a idempotência do reenvio em lote depende dessa chave.
    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ts DATETIME NOT NULL,
        lat DOUBLE NULL,
        lon DOUBLE NULL,
        sog FLOAT NULL,
        cog FLOAT NULL,
        awa FLOAT NULL,
        aws FLOAT NULL,
        aws_min FLOAT NULL,
        aws_max FLOAT NULL,
        waterspeed FLOAT NULL,
        heading FLOAT NULL,
        batt FLOAT NULL,
        soc FLOAT NULL,
        ais LONGTEXT NULL,
        depth FLOAT NULL,
        depth_min FLOAT NULL,
        depth_max FLOAT NULL,
        water_temp FLOAT NULL,
        estado VARCHAR(24) NULL,
        nome VARCHAR(120) NULL,
        tipo VARCHAR(16) NOT NULL DEFAULT 'rota',
        visivel TINYINT(1) NOT NULL DEFAULT 1,
        src VARCHAR(32) NOT NULL DEFAULT 'desconhecido',
        PRIMARY KEY  (id),
        UNIQUE KEY ts_src (ts,src),
        KEY ts (ts),
        KEY tipo (tipo)
    ) {$charset_collate};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    update_option( 'sal_core_db_version', SAL_CORE_DB_VERSION );
}
register_activation_hook( __FILE__, 'sal_core_activate' );

/**
 * Migração de schema sem depender de reativar o plugin.
 *
 * O deploy é por SFTP: o arquivo troca, mas nada reativa o plugin, então o
 * register_activation_hook NUNCA dispara em produção. Sem isto, colunas novas
 * simplesmente não apareceriam e a gravação falharia em silêncio.
 */
function sal_core_maybe_upgrade() {
    if ( version_compare( (string) get_option( 'sal_core_db_version', '0' ), SAL_CORE_DB_VERSION, '>=' ) ) {
        return;
    }
    sal_core_activate();
}
add_action( 'plugins_loaded', 'sal_core_maybe_upgrade' );

/**
 * Regista as rotas REST: ingestão de dados do barco e as duas leituras
 * públicas do painel.
 *
 * /sk (ingestão) exige autenticação via header X-SAL-API-Key. /agora e /rota
 * são públicas mas passam pela regra da bolha — nenhuma delas devolve a
 * posição atual do barco. Ver o bloco "POSIÇÃO PÚBLICA" mais abaixo.
 */
function sal_core_register_rest() {
    register_rest_route( 'sal/v1', '/sk', array(
        'methods'  => 'POST',
        'callback' => 'sal_core_handle_sk_data',
        'permission_callback' => 'sal_core_sk_permission_check',
    ) );
    // Posição vaga do momento + vitais que não localizam.
    register_rest_route( 'sal/v1', '/agora', array(
        'methods'  => 'GET',
        'callback' => 'sal_core_get_agora',
        'permission_callback' => '__return_true',
    ) );
    // Rota já percorrida, precisa, fora da bolha.
    register_rest_route( 'sal/v1', '/rota', array(
        'methods'  => 'GET',
        'callback' => 'sal_core_get_rota',
        'permission_callback' => '__return_true',
    ) );
    // Histórico dos sinais vitais, agregado. Aceita ?janela=24h|7d|30d|tudo.
    register_rest_route( 'sal/v1', '/series', array(
        'methods'  => 'GET',
        'callback' => 'sal_core_get_series',
        'permission_callback' => '__return_true',
    ) );
    // Aposentado — ver sal_core_get_last_point().
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

// Teto de pontos por requisição. Um barco offline por dias sobe o buffer
// inteiro de uma vez; o teto evita que uma requisição só derrube o PHP-FPM.
if ( ! defined( 'SAL_CORE_LOTE_MAX' ) ) {
    define( 'SAL_CORE_LOTE_MAX', 5000 );
}

/**
 * Valida e normaliza UM ponto. Devolve a linha pronta ou uma string de erro.
 */
function sal_core_valida_ponto( $data ) {
    if ( ! is_array( $data ) ) {
        return 'ponto não é um objeto';
    }

    $fields = array(
        'lat'        => array( -90,    90 ),
        'lon'        => array( -180,   180 ),
        'sog'        => array( 0,      100 ),   // nós
        'cog'        => array( 0,      360 ),   // graus
        'awa'        => array( -180,   180 ),   // graus
        'aws'        => array( 0,      200 ),   // nós (média do intervalo)
        'aws_min'    => array( 0,      200 ),
        'aws_max'    => array( 0,      200 ),
        'waterspeed' => array( 0,      100 ),
        'heading'    => array( 0,      360 ),
        'batt'       => array( 0,      200 ),   // volts (margem ampla)
        'soc'        => array( 0,      100 ),   // % de carga — Signal K manda 0..1, converta antes
        'depth'      => array( 0,      12000 ), // metros (média do intervalo)
        'depth_min'  => array( 0,      12000 ),
        'depth_max'  => array( 0,      12000 ),
        'water_temp' => array( -5,     60 ),    // °C — Signal K manda kelvin, converta antes
    );
    $clean = array();
    foreach ( $fields as $name => $range ) {
        $v = sal_core_valid_num( isset( $data[ $name ] ) ? $data[ $name ] : null, $range[0], $range[1] );
        if ( $v === false ) {
            return "campo {$name} fora do intervalo válido";
        }
        $clean[ $name ] = $v;
    }

    if ( isset( $data['ts'] ) ) {
        $ts_unix = strtotime( (string) $data['ts'] );
        if ( ! $ts_unix ) {
            return 'campo ts inválido';
        }
        $ts = gmdate( 'Y-m-d H:i:s', $ts_unix );
    } else {
        $ts = current_time( 'mysql', 1 );
    }

    $tipo = isset( $data['tipo'] ) && 'lugar' === $data['tipo'] ? 'lugar' : 'rota';

    return array(
        'ts'         => $ts,
        'lat'        => $clean['lat'],
        'lon'        => $clean['lon'],
        'sog'        => $clean['sog'],
        'cog'        => $clean['cog'],
        'awa'        => $clean['awa'],
        'aws'        => $clean['aws'],
        'aws_min'    => $clean['aws_min'],
        'aws_max'    => $clean['aws_max'],
        'waterspeed' => $clean['waterspeed'],
        'heading'    => $clean['heading'],
        'batt'       => $clean['batt'],
        'soc'        => $clean['soc'],
        'ais'        => isset( $data['ais'] ) ? wp_json_encode( $data['ais'] ) : null,
        'depth'      => $clean['depth'],
        'depth_min'  => $clean['depth_min'],
        'depth_max'  => $clean['depth_max'],
        'water_temp' => $clean['water_temp'],
        'estado'     => isset( $data['estado'] ) ? sanitize_text_field( (string) $data['estado'] ) : null,
        'nome'       => isset( $data['nome'] ) ? sanitize_text_field( (string) $data['nome'] ) : null,
        'tipo'       => $tipo,
        'visivel'    => isset( $data['visivel'] ) && ! $data['visivel'] ? 0 : 1,
        'src'        => isset( $data['src'] ) ? sanitize_text_field( (string) $data['src'] ) : 'desconhecido',
    );
}

/**
 * POST /sal/v1/sk — ingestão. Aceita um ponto ou um lote.
 *
 * Formatos aceitos (o primeiro é o legado, mantido para não quebrar nada):
 *   {"lat":..,"lon":..}            → um ponto
 *   [{...},{...}]                  → lote
 *   {"pontos":[{...},{...}]}       → lote
 *
 * Reenviar um lote é seguro: a UNIQUE (ts, src) descarta o que já entrou. Isso
 * importa porque o caminho normal de falha do barco é "gravou, mas a resposta
 * se perdeu" — sem idempotência, cada timeout duplicaria a travessia inteira.
 */
function sal_core_handle_sk_data( WP_REST_Request $request ) {
    $data = $request->get_json_params();
    if ( empty( $data ) || ! is_array( $data ) ) {
        return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Payload vazio' ), 400 );
    }

    if ( isset( $data['pontos'] ) && is_array( $data['pontos'] ) ) {
        $lote = $data['pontos'];
    } elseif ( isset( $data[0] ) && is_array( $data[0] ) ) {
        $lote = $data;
    } else {
        $lote = array( $data );
    }

    if ( count( $lote ) > SAL_CORE_LOTE_MAX ) {
        return new WP_REST_Response(
            array( 'status' => 'error', 'message' => 'Lote acima de ' . SAL_CORE_LOTE_MAX . ' pontos' ),
            413
        );
    }

    // Valida o lote inteiro ANTES de gravar qualquer coisa: meio lote gravado
    // deixaria o cliente sem saber de onde recomeçar.
    $linhas = array();
    foreach ( $lote as $i => $ponto ) {
        $linha = sal_core_valida_ponto( $ponto );
        if ( is_string( $linha ) ) {
            return new WP_REST_Response(
                array( 'status' => 'error', 'message' => "ponto {$i}: {$linha}" ),
                400
            );
        }
        $linhas[] = $linha;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'sal_track';

    // $wpdb->insert lida corretamente com NULL, o que um INSERT IGNORE montado
    // à mão não faz sem malabarismo. O custo é uma query por ponto; para um
    // lote que chega uma vez por travessia, é troca barata por correção.
    $suprimido = $wpdb->suppress_errors( true );
    $gravados  = 0;
    $ignorados = 0;
    foreach ( $linhas as $linha ) {
        if ( false !== $wpdb->insert( $table_name, $linha ) ) {
            $gravados++;
        } elseif ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
            $ignorados++;
        } else {
            $wpdb->suppress_errors( $suprimido );
            return new WP_REST_Response(
                array( 'status' => 'error', 'message' => 'Falha ao gravar', 'gravados' => $gravados ),
                500
            );
        }
    }
    $wpdb->suppress_errors( $suprimido );

    // Recalcula a célula a partir da linha mais nova DA TABELA, não do lote:
    // um lote pode chegar fora de ordem ou ser backfill de dado antigo.
    $ultimo = sal_core_ultimo_ponto();
    if ( $ultimo ) {
        sal_core_atualiza_celula( $ultimo['lat'], $ultimo['lon'] );
    }

    return array( 'ok' => true, 'gravados' => $gravados, 'ignorados' => $ignorados );
}

/* =========================================================================
 * POSIÇÃO PÚBLICA — a "regra da bolha"
 * =========================================================================
 *
 * O banco guarda a posição SEMPRE precisa. Quem decide o que é público é a
 * leitura, nunca a gravação — senão o histórico preciso se perde para sempre.
 *
 * A regra inteira sai de uma primitiva só: a CÉLULA de uma grade fixa de
 * ~110 km, com deslocamento secreto (sal_core_grade_offset).
 *
 *   Onde estou  → a célula atual. Nome do lugar + círculo. Sem lat/lon.
 *   Onde passei → todo ponto cuja célula dista >= 2 células da atual.
 *
 * Por que célula e não "círculo de 100 km em volta de mim":
 *
 *  1. Um círculo centrado em você TEM você no centro. Publicar o centro é
 *     publicar a posição, só que embrulhada.
 *  2. Parado no mesmo fundeadouro por duas semanas, a célula publicada é
 *     idêntida byte a byte todo dia — repetição não acrescenta informação.
 *     Um círculo que acompanha o barco gera um dado novo a cada amostra, e a
 *     interseção das amostras estreita a posição.
 *  3. Se a rota apenas parasse a 100 km de você, o ponto final dela traçaria
 *     um arco ao seu redor. Vários finais se cruzam e te localizam. A grade
 *     quantiza esse limite e mata o ataque.
 *
 * Efeito colateral desejado: voltar a um lugar já publicado o esconde de
 * novo, automaticamente, porque a bolha anda junto com o barco.
 */

// Lado da célula em graus. 1.0° ≈ 110 km de norte a sul; em longitude varia
// com o cosseno da latitude (~110 km no equador, ~92 km em -34°). O círculo
// que cobre a célula tem raio de ~78 km.
if ( ! defined( 'SAL_CORE_CELULA_GRAUS' ) ) {
    define( 'SAL_CORE_CELULA_GRAUS', 1.0 );
}

// Histerese: só troca a célula publicada quando o barco está pelo menos isto
// para dentro da nova. Sem margem, ficar em cima de uma divisa faz a célula
// oscilar — e a oscilação revela a divisa, que é uma linha, não uma área.
if ( ! defined( 'SAL_CORE_MARGEM_GRAUS' ) ) {
    define( 'SAL_CORE_MARGEM_GRAUS', 0.15 );
}

// Sem ponto novo por este tempo, o barco é considerado fora do ar.
if ( ! defined( 'SAL_CORE_SILENCIO_HORAS' ) ) {
    define( 'SAL_CORE_SILENCIO_HORAS', 6 );
}

/**
 * Deslocamento secreto da grade, sorteado uma vez e guardado.
 *
 * Sem ele as divisas seriam números redondos e portanto adivinháveis: quem
 * soubesse onde elas caem saberia, ao ver a célula trocar, que o barco
 * acabou de cruzar uma linha conhecida.
 */
function sal_core_grade_offset() {
    $off = get_option( 'sal_core_grade_offset' );
    if ( is_array( $off ) && count( $off ) === 2 ) {
        return array( (float) $off[0], (float) $off[1] );
    }
    $off = array(
        wp_rand( 0, 999999 ) / 1000000 * SAL_CORE_CELULA_GRAUS,
        wp_rand( 0, 999999 ) / 1000000 * SAL_CORE_CELULA_GRAUS,
    );
    update_option( 'sal_core_grade_offset', $off, false );
    return $off;
}

/**
 * Índice da célula que contém o ponto. Devolve array( i_lat, i_lon ).
 */
function sal_core_celula( $lat, $lon ) {
    $off = sal_core_grade_offset();
    return array(
        (int) floor( ( (float) $lat - $off[0] ) / SAL_CORE_CELULA_GRAUS ),
        (int) floor( ( (float) $lon - $off[1] ) / SAL_CORE_CELULA_GRAUS ),
    );
}

/**
 * Centro geométrico da célula — a única coordenada que sai para o público.
 * Não carrega nenhuma informação sobre onde dentro da célula o barco está.
 */
function sal_core_celula_centro( array $celula ) {
    $off = sal_core_grade_offset();
    return array(
        ( $celula[0] + 0.5 ) * SAL_CORE_CELULA_GRAUS + $off[0],
        ( $celula[1] + 0.5 ) * SAL_CORE_CELULA_GRAUS + $off[1],
    );
}

/**
 * Raio, em km, do círculo que cobre a célula (semi-diagonal).
 */
function sal_core_celula_raio_km( array $celula ) {
    $centro = sal_core_celula_centro( $celula );
    $alt_lat = SAL_CORE_CELULA_GRAUS * 110.574;
    $alt_lon = SAL_CORE_CELULA_GRAUS * 111.320 * cos( deg2rad( $centro[0] ) );
    return round( sqrt( pow( $alt_lat / 2, 2 ) + pow( $alt_lon / 2, 2 ) ) );
}

/**
 * Atualiza a célula publicada aplicando histerese. Chamada só na INGESTÃO —
 * endpoint público nunca escreve estado, para não haver corrida entre
 * leitores concorrentes.
 */
function sal_core_atualiza_celula( $lat, $lon ) {
    if ( $lat === null || $lon === null ) {
        return;
    }
    $nova  = sal_core_celula( $lat, $lon );
    $atual = get_option( 'sal_core_celula_publicada' );

    if ( ! is_array( $atual ) || count( $atual ) !== 2 ) {
        update_option( 'sal_core_celula_publicada', $nova, false );
        return;
    }
    $atual = array( (int) $atual[0], (int) $atual[1] );
    if ( $atual === $nova ) {
        return;
    }

    // A histerese é POR EIXO, e isso não é detalhe.
    //
    // A versão ingênua exige estar a salvo das quatro bordas da célula nova
    // ao mesmo tempo. Com ela, um barco que navegue rente a uma divisa de
    // longitude nunca satisfaz a condição — e a célula publicada TRAVA. O
    // barco se afasta, a célula fica para trás, e a bolha passa a ser medida
    // contra uma célula obsoleta: pontos de rota vizinhos à posição real
    // voltariam a ser publicados. A proteção falharia exatamente onde
    // deveria agir.
    //
    // Por eixo, cada índice anda quando o barco está fundo o bastante
    // naquele eixo, independentemente do outro.
    $off   = sal_core_grade_offset();
    $pos   = array( (float) $lat, (float) $lon );
    $final = $atual;

    foreach ( array( 0, 1 ) as $eixo ) {
        if ( $nova[ $eixo ] === $atual[ $eixo ] ) {
            continue;
        }
        $d      = $pos[ $eixo ] - ( $nova[ $eixo ] * SAL_CORE_CELULA_GRAUS + $off[ $eixo ] );
        $dentro = min( $d, SAL_CORE_CELULA_GRAUS - $d );
        if ( $dentro >= SAL_CORE_MARGEM_GRAUS ) {
            $final[ $eixo ] = $nova[ $eixo ];
        }
    }

    if ( $final !== $atual ) {
        update_option( 'sal_core_celula_publicada', $final, false );
    }
}

/**
 * Célula atual, em modo somente leitura. Cai para o cálculo direto a partir
 * do último ponto quando a option ainda não existe (base recém-populada).
 */
function sal_core_celula_atual() {
    $atual = get_option( 'sal_core_celula_publicada' );
    if ( is_array( $atual ) && count( $atual ) === 2 ) {
        return array( (int) $atual[0], (int) $atual[1] );
    }
    $ultimo = sal_core_ultimo_ponto();
    if ( ! $ultimo || $ultimo['lat'] === null || $ultimo['lon'] === null ) {
        return null;
    }
    return sal_core_celula( $ultimo['lat'], $ultimo['lon'] );
}

/**
 * Último ponto COM posição (bruto, uso interno — nunca devolvido ao público).
 */
function sal_core_ultimo_ponto() {
    global $wpdb;
    $t = $wpdb->prefix . 'sal_track';
    return $wpdb->get_row(
        "SELECT * FROM {$t} WHERE lat IS NOT NULL AND lon IS NOT NULL ORDER BY ts DESC, id DESC LIMIT 1",
        ARRAY_A
    );
}

/**
 * Nome legível da célula, via geocodificação reversa do CENTRO da célula.
 *
 * Repare que quem é geocodificado é o centro, nunca o barco: pedir o nome da
 * posição real entregaria a coordenada exata ao serviço de terceiro em troca
 * de um nome aproximado. Resultado fica em transient, porque a célula muda
 * raramente. Falha em silêncio — nome é enfeite, não pode derrubar a rota.
 */
function sal_core_nome_da_celula( array $celula ) {
    $chave = 'sal_nome_' . $celula[0] . '_' . $celula[1];
    $cache = get_transient( $chave );
    if ( $cache !== false ) {
        return $cache === '-' ? null : $cache;
    }

    $centro = sal_core_celula_centro( $celula );
    $resp = wp_remote_get(
        add_query_arg(
            array(
                'lat'             => round( $centro[0], 4 ),
                'lon'             => round( $centro[1], 4 ),
                'format'          => 'json',
                'zoom'            => 8,
                'accept-language' => 'pt-BR',
            ),
            'https://nominatim.openstreetmap.org/reverse'
        ),
        array(
            'timeout'    => 8,
            'user-agent' => 'HashtagSal/1.0 (+https://hashtagsal.com.br)',
        )
    );

    $nome = null;
    if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
        $dados = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( isset( $dados['address'] ) && is_array( $dados['address'] ) ) {
            $a = $dados['address'];
            foreach ( array( 'city', 'town', 'municipality', 'county', 'state_district', 'state' ) as $campo ) {
                if ( ! empty( $a[ $campo ] ) ) {
                    $nome = (string) $a[ $campo ];
                    break;
                }
            }
            if ( $nome && ! empty( $a['state'] ) && $nome !== $a['state'] ) {
                $nome .= ' – ' . $a['state'];
            }
        }
    }
    set_transient( $chave, $nome === null ? '-' : $nome, 30 * DAY_IN_SECONDS );
    return $nome;
}

/**
 * GET /sal/v1/agora — onde o barco está, de forma deliberadamente vaga.
 *
 * Não devolve lat/lon do barco, não devolve rumo e não devolve velocidade:
 * rumo e velocidade, integrados a partir do último ponto público, reconstroem
 * a posição por navegação estimada. Só saem daqui grandezas que não localizam.
 * O timestamp é arredondado para a hora — "atualizado há 2 minutos", somado à
 * célula, seria por si só um canal de rastreamento.
 */
function sal_core_get_agora() {
    $ultimo = sal_core_ultimo_ponto();
    if ( ! $ultimo ) {
        return array( 'transmitindo' => false, 'area' => null );
    }

    $celula = sal_core_celula_atual();
    if ( ! $celula ) {
        return array( 'transmitindo' => false, 'area' => null );
    }
    $centro = sal_core_celula_centro( $celula );

    $visto_em = strtotime( $ultimo['ts'] . ' UTC' );
    $silencio = ( time() - $visto_em ) > ( SAL_CORE_SILENCIO_HORAS * HOUR_IN_SECONDS );

    // A tensão continua sendo gravada, mas quem aparece é o SOC: "84%" diz
    // quanta energia resta, e "13,3 V" só diz isso para quem sabe ler curva
    // de bateria — e a tensão sobe com o painel solar carregando, o que faz
    // o número parecer melhor justamente quando o banco está fraco.
    $vitais = array();
    $mapa_vitais = array(
        'soc'        => 'bateria_pct',
        'depth'      => 'profundidade_m',
        'aws'        => 'vento_no',
        'water_temp' => 'agua_c',
    );
    foreach ( $mapa_vitais as $col => $rotulo ) {
        if ( isset( $ultimo[ $col ] ) && $ultimo[ $col ] !== null ) {
            $vitais[ $rotulo ] = round( (float) $ultimo[ $col ], 1 );
        }
    }

    // A sondagem só serve para a folga sob a quilha se for recente; com dado
    // velho o número viraria uma garantia falsa sobre água que já mudou.
    $prof = ( ! $silencio && isset( $ultimo['depth'] ) && $ultimo['depth'] !== null )
        ? (float) $ultimo['depth'] : null;
    $mare = sal_core_mare_agora( $ultimo['lat'], $ultimo['lon'], $prof );

    return array(
        'transmitindo'  => ! $silencio,
        'atualizado_em' => gmdate( 'Y-m-d\TH:00:00\Z', $visto_em ),
        'mare'          => $mare,
        // "fundeado", "navegando"... não localiza e é o dado mais narrativo
        // que temos. Sai inteiro; velocidade e rumo, não.
        'estado'        => isset( $ultimo['estado'] ) ? $ultimo['estado'] : null,
        'area'          => array(
            'lat'     => round( $centro[0], 4 ),
            'lon'     => round( $centro[1], 4 ),
            'raio_km' => sal_core_celula_raio_km( $celula ),
            'nome'    => sal_core_nome_da_celula( $celula ),
        ),
        'vitais'        => $vitais,
    );
}

/**
 * GET /sal/v1/rota — os lugares por onde o barco já passou, precisos.
 *
 * Precisos porque já foram deixados para trás: só sai o que está a duas
 * células ou mais da atual, o que garante pelo menos uma célula inteira
 * (~110 km) de separação. O filtro roda a cada leitura, então voltar a um
 * lugar já publicado o esconde de novo sem ninguém precisar marcar nada.
 */
function sal_core_get_rota( WP_REST_Request $request ) {
    global $wpdb;
    $t = $wpdb->prefix . 'sal_track';

    $limite = (int) $request->get_param( 'limite' );
    $limite = ( $limite > 0 && $limite <= 5000 ) ? $limite : 2000;

    // visivel = 0 é o veto manual: serve para tirar do ar um ponto sem apagá-lo
    // do banco. Nasceu para a revisão das estrelas importadas do Google Maps,
    // onde entram lugares que não são do barco (casa de amigo, oficina...).
    $linhas = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ts, lat, lon, sog, nome, tipo FROM {$t}
             WHERE lat IS NOT NULL AND lon IS NOT NULL AND visivel = 1
             ORDER BY ts ASC, id ASC LIMIT %d",
            $limite
        ),
        ARRAY_A
    );

    $celula  = sal_core_celula_atual();
    $pontos  = array();
    $lugares = array();
    $ocultos = 0;

    foreach ( $linhas as $linha ) {
        if ( $celula ) {
            $c = sal_core_celula( $linha['lat'], $linha['lon'] );
            if ( max( abs( $c[0] - $celula[0] ), abs( $c[1] - $celula[1] ) ) < 2 ) {
                $ocultos++;
                continue;
            }
        }

        // Lugares são marcadores nomeados, não vértices de rota. Ligá-los numa
        // linha desenharia uma derrota que nunca foi navegada — em vários
        // trechos, por dentro da terra.
        if ( 'lugar' === $linha['tipo'] ) {
            $lugares[] = array(
                'nome' => $linha['nome'],
                'lat'  => round( (float) $linha['lat'], 5 ),
                'lon'  => round( (float) $linha['lon'], 5 ),
            );
            continue;
        }

        $pontos[] = array(
            'ts'  => gmdate( 'c', strtotime( $linha['ts'] . ' UTC' ) ),
            'lat' => round( (float) $linha['lat'], 5 ),
            'lon' => round( (float) $linha['lon'], 5 ),
            'sog' => $linha['sog'] === null ? null : round( (float) $linha['sog'], 1 ),
        );
    }

    // "ocultos" é honestidade: diz ao leitor que o mapa está incompleto de
    // propósito, em vez de deixá-lo achar que a rota acabou ali.
    return array( 'pontos' => $pontos, 'lugares' => $lugares, 'ocultos' => $ocultos );
}

/**
 * Métricas do histórico. `bolha` marca as que precisam do filtro de posição.
 *
 * Velocidade é a única com `bolha => true`, e não é excesso de zelo: uma série
 * de velocidade, integrada a partir do último ponto de rota publicado, dá a
 * distância percorrida desde ali — navegação estimada. As outras são
 * escalares que não apontam para lugar nenhum.
 */
function sal_core_metricas() {
    return array(
        'bateria_pct'    => array( 'col' => 'soc',        'rotulo' => 'Bateria',      'unidade' => '%',  'cor' => '#059669', 'bolha' => false ),
        'profundidade_m' => array( 'col' => 'depth',      'rotulo' => 'Profundidade', 'unidade' => 'm',  'cor' => '#1a5b8f', 'bolha' => false,
                                   'col_min' => 'depth_min', 'col_max' => 'depth_max' ),
        // A faixa min..max transforma "12 nós" em "12 nós, variando de 8 a 19".
        // Num canal de vela a rajada é metade da história; média sozinha a
        // apaga, e é justamente o pico que decide se o dia foi duro.
        'vento_no'       => array( 'col' => 'aws',        'rotulo' => 'Vento',        'unidade' => 'nós','cor' => '#7c3aed', 'bolha' => false,
                                   'col_min' => 'aws_min', 'col_max' => 'aws_max' ),
        'agua_c'         => array( 'col' => 'water_temp', 'rotulo' => 'Água',         'unidade' => '°C', 'cor' => '#ea580c', 'bolha' => false ),
        'velocidade_no'  => array( 'col' => 'sog',        'rotulo' => 'Velocidade',   'unidade' => 'nós','cor' => '#0891b2', 'bolha' => true ),
    );
}

/* -------------------------------------------------------------------------
 * MARÉ PREVISTA
 * -------------------------------------------------------------------------
 * Fonte: Open-Meteo Marine (`sea_level_height_msl`). Grátis, sem chave,
 * cobertura global — conferido em 2026-07-31 com amplitudes coerentes em
 * Maceió (1,96 m), Canal da Mancha (3,39 m), Mediterrâneo (0,21 m) e
 * Patagônia (8,41 m). Traz passado e futuro, o que permite sobrepor a
 * previsão ao que o barco mediu.
 *
 * POR QUE NÃO DEDUZIR DA PRÓPRIA SONDAGEM: foi a primeira tentativa e está
 * errada. O barco gira na poita descrevendo um círculo de dezenas de metros,
 * e o fundo dentro desse círculo varia sozinho. Pior: o giro é comandado por
 * vento e pela CORRENTE DE MARÉ, que reverte no ritmo da própria maré — o
 * erro fica correlacionado com o sinal e não há filtro que separe os dois.
 *
 * O datum é o nível médio do mar, não o da carta. Não importa para o que a
 * página faz: tudo aqui é DIFERENÇA de altura ("ainda desce 0,6 m"), e
 * diferença independe de onde está o zero.
 *
 * O modelo é oceânico e de grade grossa (~10 km). Dentro de estuário ele erra
 * a fase — e é justamente essa discrepância que a página mostra, sobrepondo
 * previsão e sondagem observada.
 */
if ( ! defined( 'SAL_CORE_MARE_JANELA_S' ) ) {
    define( 'SAL_CORE_MARE_JANELA_S', 2 * HOUR_IN_SECONDS );
}
if ( ! defined( 'SAL_CORE_MARE_AMPLITUDE_M' ) ) {
    define( 'SAL_CORE_MARE_AMPLITUDE_M', 0.05 );
}

/**
 * Extremos locais de uma série. Genérico de propósito: recebe pares
 * ( ts, valor ) e devolve as viradas, com refino parabólico.
 *
 * @param array $serie lista de array( ts_unix, valor|null )
 * @param int   $passo espaçamento típico em segundos
 */
function sal_core_extremos_serie( array $serie, $passo ) {
    $n = count( $serie );
    $k = max( 1, (int) round( SAL_CORE_MARE_JANELA_S / max( 1, $passo ) ) );
    if ( $n < 2 * $k + 1 ) {
        return array();
    }

    $marcas = array();
    // A janela precisa estar completa DOS DOIS LADOS. Sem isso, o primeiro
    // ponto da série é sempre o menor do que se vê dele em diante e vira uma
    // virada fantasma no início da subida — o erro chegava a um quarto do
    // período, ~3 h. Bug pego por teste em 2026-07-31.
    for ( $i = $k; $i <= $n - 1 - $k; $i++ ) {
        if ( $serie[ $i ][1] === null ) {
            continue;
        }
        $v = $serie[ $i ][1];
        $eh_max = true;
        $eh_min = true;
        $piso = $v;
        $teto = $v;
        $vizinhos = 0;

        for ( $j = $i - $k; $j <= $i + $k; $j++ ) {
            if ( $j === $i || $serie[ $j ][1] === null ) {
                continue;
            }
            $vizinhos++;
            $u = $serie[ $j ][1];
            if ( $u > $v ) { $eh_max = false; }
            if ( $u < $v ) { $eh_min = false; }
            if ( $u < $piso ) { $piso = $u; }
            if ( $u > $teto ) { $teto = $u; }
        }
        if ( $vizinhos < $k ) {
            continue;
        }
        if ( ( $teto - $piso ) < SAL_CORE_MARE_AMPLITUDE_M ) {
            continue; // variação pequena demais para ser virada
        }
        if ( ! ( $eh_max xor $eh_min ) ) {
            continue;
        }

        $ts  = $serie[ $i ][0];
        $val = $v;

        // Refino parabólico. A previsão vem de hora em hora; sem isto a
        // preamar sairia sempre "em ponto", com até 30 min de erro.
        $a = $serie[ $i - 1 ][1];
        $c = $serie[ $i + 1 ][1];
        if ( $a !== null && $c !== null ) {
            $den = $a - 2 * $v + $c;
            if ( abs( $den ) > 1e-9 ) {
                $desloc = 0.5 * ( $a - $c ) / $den;
                if ( abs( $desloc ) <= 1 ) {
                    $ts  = (int) round( $ts + $desloc * $passo );
                    $val = $v - 0.25 * ( $a - $c ) * $desloc;
                }
            }
        }

        $marcas[] = array(
            'ts'    => $ts,
            'valor' => round( $val, 2 ),
            'tipo'  => $eh_max ? 'alta' : 'baixa',
        );
    }
    return $marcas;
}

/**
 * Série de maré prevista para a posição, de -7 a +3 dias, de hora em hora.
 *
 * A posição é arredondada para 0,1° antes de sair daqui. Isso não custa
 * precisão — a grade do modelo é mais grossa que isso — e evita entregar a
 * coordenada exata do barco a um terceiro só para saber a maré.
 *
 * @return array|null lista de array( ts_unix, altura_m )
 */
function sal_core_mare_prevista( $lat, $lon ) {
    $lat = round( (float) $lat, 1 );
    $lon = round( (float) $lon, 1 );

    $chave = 'sal_mare_' . str_replace( array( '.', '-' ), array( '_', 'm' ), $lat . '_' . $lon );
    $cache = get_transient( $chave );
    if ( false !== $cache ) {
        return '-' === $cache ? null : $cache;
    }

    $resp = wp_remote_get(
        add_query_arg(
            array(
                'latitude'      => $lat,
                'longitude'     => $lon,
                'hourly'        => 'sea_level_height_msl',
                'past_days'     => 7,
                'forecast_days' => 3,
                'timeformat'    => 'unixtime',
            ),
            'https://marine-api.open-meteo.com/v1/marine'
        ),
        array( 'timeout' => 12, 'user-agent' => 'HashtagSal/1.0 (+https://hashtagsal.com.br)' )
    );

    $serie = null;
    if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
        $dados = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( isset( $dados['hourly']['time'], $dados['hourly']['sea_level_height_msl'] ) ) {
            $t = $dados['hourly']['time'];
            $h = $dados['hourly']['sea_level_height_msl'];
            $serie = array();
            foreach ( $t as $i => $ts ) {
                $v = isset( $h[ $i ] ) ? $h[ $i ] : null;
                $serie[] = array( (int) $ts, $v === null ? null : (float) $v );
            }
            // Fiorde estreito ou lago cai fora da grade oceânica e volta tudo
            // nulo. Melhor não ter maré do que ter uma linha reta fingindo.
            $validos = 0;
            foreach ( $serie as $x ) {
                if ( $x[1] !== null ) { $validos++; }
            }
            if ( $validos < 24 ) {
                $serie = null;
            }
        }
    }

    set_transient( $chave, $serie === null ? '-' : $serie, HOUR_IN_SECONDS );
    return $serie;
}

/**
 * O que o navegante precisa saber agora: quando vira, quanto ainda desce e
 * quanta água sobra sob a quilha na baixamar.
 */
function sal_core_mare_agora( $lat, $lon, $profundidade ) {
    $serie = sal_core_mare_prevista( $lat, $lon );
    if ( ! $serie ) {
        return null;
    }

    $agora = time();
    $extremos = sal_core_extremos_serie( $serie, HOUR_IN_SECONDS );

    $proximo = null;
    foreach ( $extremos as $e ) {
        if ( $e['ts'] > $agora ) { $proximo = $e; break; }
    }
    if ( ! $proximo ) {
        return null;
    }

    // Altura prevista agora, interpolada entre as duas horas vizinhas.
    $altura_agora = null;
    for ( $i = 0; $i < count( $serie ) - 1; $i++ ) {
        if ( $serie[ $i ][0] <= $agora && $serie[ $i + 1 ][0] > $agora
             && $serie[ $i ][1] !== null && $serie[ $i + 1 ][1] !== null ) {
            $f = ( $agora - $serie[ $i ][0] ) / ( $serie[ $i + 1 ][0] - $serie[ $i ][0] );
            $altura_agora = $serie[ $i ][1] + $f * ( $serie[ $i + 1 ][1] - $serie[ $i ][1] );
            break;
        }
    }
    if ( $altura_agora === null ) {
        return null;
    }

    $delta = $proximo['valor'] - $altura_agora;

    $saida = array(
        'sentido'     => $delta < 0 ? 'baixando' : 'subindo',
        'proxima'     => $proximo['tipo'],
        'proxima_em'  => gmdate( 'c', $proximo['ts'] ),
        'variacao_m'  => round( abs( $delta ), 2 ),
    );

    // Sob a quilha: o que o ecobatímetro lê menos o calado. Só faz sentido
    // com sondagem fresca — por isso quem chama passa a profundidade ou null.
    $calado = (float) get_option( 'sal_core_calado_m', 0 );
    if ( $profundidade !== null && $calado > 0 ) {
        $saida['sob_quilha_m']    = round( $profundidade - $calado, 2 );
        $saida['sob_quilha_min_m'] = round( $profundidade - $calado + min( 0, $delta ), 2 );
    }
    return $saida;
}

/**
 * A partir de qual instante a velocidade pode ser publicada.
 *
 * Devolve o ts do ponto de rota mais recente que já saiu da bolha. Dado
 * posterior a isso fica oculto, pelo mesmo motivo que a rota recente fica.
 *
 * A varredura é limitada: se em 5000 linhas nenhuma estiver fora da bolha,
 * devolve a mais antiga vista — errar para o lado de esconder mais.
 */
function sal_core_corte_velocidade() {
    global $wpdb;
    $t = $wpdb->prefix . 'sal_track';

    $celula = sal_core_celula_atual();
    if ( ! $celula ) {
        return null;
    }

    $linhas = $wpdb->get_results(
        "SELECT ts, lat, lon FROM {$t}
         WHERE lat IS NOT NULL AND lon IS NOT NULL AND tipo = 'rota'
         ORDER BY ts DESC, id DESC LIMIT 5000",
        ARRAY_A
    );
    if ( ! $linhas ) {
        return null;
    }

    foreach ( $linhas as $linha ) {
        $c = sal_core_celula( $linha['lat'], $linha['lon'] );
        if ( max( abs( $c[0] - $celula[0] ), abs( $c[1] - $celula[1] ) ) >= 2 ) {
            return $linha['ts'];
        }
    }
    return end( $linhas )['ts'];
}

/**
 * GET /sal/v1/series — histórico dos sinais vitais.
 *
 * Parâmetro `janela`: 24h | 7d | 30d | tudo (padrão 24h).
 *
 * Agrega em blocos dimensionados para caber ~240 pontos na tela, qualquer que
 * seja a janela. Sem isso, 30 dias amostrados a cada minuto seriam 43 mil
 * pontos no navegador de quem abrir a página pelo celular no meio do mar.
 */
function sal_core_get_series( WP_REST_Request $request ) {
    global $wpdb;
    $t = $wpdb->prefix . 'sal_track';

    $janelas = array(
        '24h'  => DAY_IN_SECONDS,
        '7d'   => 7 * DAY_IN_SECONDS,
        '30d'  => 30 * DAY_IN_SECONDS,
        'tudo' => 0,
    );
    $janela = (string) $request->get_param( 'janela' );
    if ( ! isset( $janelas[ $janela ] ) ) {
        $janela = '24h';
    }

    $desde_sql = null;
    if ( $janelas[ $janela ] > 0 ) {
        $desde_sql = gmdate( 'Y-m-d H:i:s', time() - $janelas[ $janela ] );
    }

    $onde = "WHERE tipo = 'rota'";
    if ( $desde_sql ) {
        $onde .= $wpdb->prepare( ' AND ts >= %s', $desde_sql );
    }

    $extremos = $wpdb->get_row( "SELECT MIN(ts) AS de, MAX(ts) AS ate FROM {$t} {$onde}", ARRAY_A );
    if ( empty( $extremos['de'] ) ) {
        return array( 'janela' => $janela, 'bloco_s' => 0, 'series' => array() );
    }

    $span  = max( 60, strtotime( $extremos['ate'] . ' UTC' ) - strtotime( $extremos['de'] . ' UTC' ) );
    $bloco = max( 60, (int) ceil( $span / 240 ) );

    // TIMESTAMPDIFF em vez de UNIX_TIMESTAMP: o segundo interpreta o DATETIME
    // no fuso da sessão MySQL, e a coluna é UTC. Um servidor com time_zone
    // diferente deslocaria o gráfico inteiro em silêncio.
    $epoca = "TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', ts)";

    $metricas = sal_core_metricas();
    $selects  = array( "FLOOR({$epoca}/{$bloco})*{$bloco} AS bloco" );
    foreach ( $metricas as $chave => $m ) {
        $selects[] = "AVG({$m['col']}) AS " . $chave;
        // A composição fecha: cada linha já traz média/mín/máx do seu próprio
        // intervalo, então o bloco é a média das médias e o extremo dos
        // extremos. Tirar MIN da coluna da média daria uma faixa estreita
        // demais — o pico de um intervalo curto sumiria na média dele.
        if ( ! empty( $m['col_min'] ) ) {
            $selects[] = "MIN({$m['col_min']}) AS {$chave}__min";
            $selects[] = "MAX({$m['col_max']}) AS {$chave}__max";
        }
    }
    // Velocidade máxima do bloco: é o que diz se o barco estava parado, e só
    // parado a sondagem mede maré em vez de relevo do fundo.
    $selects[] = 'MAX(sog) AS __sog_max';

    $linhas = $wpdb->get_results(
        'SELECT ' . implode( ', ', $selects ) . " FROM {$t} {$onde} GROUP BY bloco ORDER BY bloco ASC",
        ARRAY_A
    );

    $corte = sal_core_corte_velocidade();
    $corte_unix = $corte ? strtotime( $corte . ' UTC' ) : null;

    $series = array();
    foreach ( $metricas as $chave => $m ) {
        $pontos = array();
        $faixa  = array();
        $tem      = false;
        $tem_faixa = false;

        foreach ( $linhas as $linha ) {
            $bloco_ts = (int) $linha['bloco'];
            $oculto   = $m['bolha'] && $corte_unix !== null && $bloco_ts > $corte_unix;

            $v = $oculto ? null : $linha[ $chave ];
            if ( $v !== null ) {
                $tem = true;
                $v   = round( (float) $v, 2 );
            }
            $pontos[] = array( $bloco_ts, $v );

            if ( ! empty( $m['col_min'] ) ) {
                $mn = $oculto ? null : $linha[ $chave . '__min' ];
                $mx = $oculto ? null : $linha[ $chave . '__max' ];
                if ( $mn !== null && $mx !== null ) {
                    $tem_faixa = true;
                    $mn = round( (float) $mn, 2 );
                    $mx = round( (float) $mx, 2 );
                } else {
                    $mn = null;
                    $mx = null;
                }
                $faixa[] = array( $bloco_ts, $mn, $mx );
            }
        }

        if ( ! $tem ) {
            continue; // série vazia não vira gráfico vazio
        }

        $serie = array(
            'rotulo'  => $m['rotulo'],
            'unidade' => $m['unidade'],
            'cor'     => $m['cor'],
            'pontos'  => $pontos,
        );
        if ( $tem_faixa ) {
            $serie['faixa'] = $faixa;
        }

        $series[ $chave ] = $serie;
    }

    // Maré prevista SOBREPOSTA À PROFUNDIDADE, no mesmo eixo e no mesmo
    // recorte — nunca como gráfico próprio.
    //
    // Unidades diferentes (metro de fundo contra metro de maré) resolvidas
    // por deslocamento: profundidade = fundo + maré + constante, então somar
    // à previsão a diferença entre as duas médias põe as curvas na mesma
    // escala. O que sobra de diferença entre elas é o que interessa — a
    // defasagem de fase, que é o atraso do estuário.
    $de_unix  = (int) ( floor( strtotime( $extremos['de'] . ' UTC' ) / $bloco ) * $bloco );
    $ate_unix = (int) ( floor( strtotime( $extremos['ate'] . ' UTC' ) / $bloco ) * $bloco );

    $ultimo_ponto = sal_core_ultimo_ponto();
    if ( $ultimo_ponto && isset( $series['profundidade_m'] ) ) {
        $prev = sal_core_mare_prevista( $ultimo_ponto['lat'], $ultimo_ponto['lon'] );
        if ( $prev ) {
            // Recorte idêntico ao dos dados medidos. Sem isto a previsão
            // avançaria pelo futuro e os dois gráficos ficariam com escalas
            // de tempo diferentes, o que torna a comparação mentirosa.
            $mare = array();
            $soma_mare = 0.0;
            $n_mare = 0;
            foreach ( $prev as $x ) {
                if ( $x[0] < $de_unix || $x[0] > $ate_unix || $x[1] === null ) {
                    continue;
                }
                $mare[] = $x;
                $soma_mare += $x[1];
                $n_mare++;
            }

            $soma_prof = 0.0;
            $n_prof = 0;
            foreach ( $series['profundidade_m']['pontos'] as $pt ) {
                if ( $pt[1] !== null ) { $soma_prof += $pt[1]; $n_prof++; }
            }

            if ( $n_mare >= 3 && $n_prof >= 3 ) {
                $desloc = ( $soma_prof / $n_prof ) - ( $soma_mare / $n_mare );
                $curva = array();
                foreach ( $mare as $x ) {
                    $curva[] = array( $x[0], round( $x[1] + $desloc, 2 ) );
                }
                $series['profundidade_m']['previsao'] = $curva;

                $marcas_mare = array();
                foreach ( sal_core_extremos_serie( $prev, HOUR_IN_SECONDS ) as $mk ) {
                    if ( $mk['ts'] >= $de_unix && $mk['ts'] <= $ate_unix ) {
                        $marcas_mare[] = $mk;
                    }
                }
                if ( $marcas_mare ) {
                    $series['profundidade_m']['marcas_tempo'] = $marcas_mare;
                }
            }
        }
    }

    // TODOS os gráficos usam este recorte, sem exceção. Deixar cada série
    // definir o próprio começo e fim faria duas curvas com escalas de tempo
    // diferentes parecerem alinhadas — e a comparação entre elas seria falsa.
    return array(
        'janela'   => $janela,
        'bloco_s'  => $bloco,
        'de'       => gmdate( 'c', $de_unix ),
        'ate'      => gmdate( 'c', $ate_unix ),
        'de_unix'  => $de_unix,
        'ate_unix' => $ate_unix,
        'series'   => $series,
    );
}

/**
 * GET /sal/v1/last — APOSENTADO.
 *
 * Devolvia `SELECT *` da última linha: lat/lon exatos, sem autenticação, para
 * qualquer um. Enquanto a tabela só tinha a linha semente de teste isso era
 * inofensivo; com dado real seria transmissão de posição ao vivo, passando por
 * cima de toda a regra acima. E como devolvia a linha inteira, qualquer coluna
 * nova vazaria sozinha, sem ninguém decidir nada.
 *
 * Fica como 410 em vez de sumir: se algo ainda chamar, aparece no log como
 * chamada explícita a uma rota aposentada, não como um 404 anônimo.
 */
function sal_core_get_last_point() {
    return new WP_REST_Response(
        array(
            'status'  => 'gone',
            'message' => 'Endpoint aposentado por privacidade. Use /sal/v1/agora e /sal/v1/rota.',
        ),
        410
    );
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
    wp_register_script( 'sal-core-balance', SAL_CORE_URL . 'js/balance.js', array( 'sal-core-leaflet' ), SAL_CORE_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'sal_core_enqueue_assets' );

/**
 * Regista as opções do plugin. Isso permite que o usuário configure
 * chaves de API, lista de posts do Instagram e Mailchimp action.
 */
function sal_core_register_settings() {
    register_setting( 'sal_core_settings', 'sal_core_youtube_api_key' );
    register_setting( 'sal_core_settings', 'sal_core_youtube_channel_id' );
    register_setting( 'sal_core_settings', 'sal_core_youtube_playlist_id' );
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
                <tr>
                    <th scope="row"><label for="sal_core_youtube_playlist_id">Playlist ID (opcional)</label></th>
                    <td>
                        <input type="text" id="sal_core_youtube_playlist_id" name="sal_core_youtube_playlist_id" value="<?php echo esc_attr( get_option( 'sal_core_youtube_playlist_id' ) ); ?>" class="regular-text" />
                        <p class="description">Se preenchido, <code>[sal_youtube]</code> e o card de último vídeo na home buscam dessa playlist em vez do canal inteiro. ID está na URL: <code>youtube.com/playlist?list=<strong>ID</strong></code>.</p>
                    </td>
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
 * Shortcode do painel do Balanço: mapa com a área aproximada do momento e a
 * rota já percorrida.
 *
 * O gráfico de velocidade saiu junto com o Chart.js. Velocidade ao vivo,
 * integrada a partir do último ponto público, reconstrói a posição — e o
 * Chart.js vinha de CDN sem versão fixada, o que é risco de cadeia de
 * suprimentos por um gráfico que não podíamos publicar de qualquer forma.
 */
function sal_core_balance_shortcode( $atts ) {
    wp_enqueue_style( 'sal-core-leaflet' );
    wp_enqueue_script( 'sal-core-leaflet' );
    wp_enqueue_script( 'sal-core-balance' );
    // Fuso do SITE, não do navegador de quem lê. A maré acontece na hora do
    // barco; alguém abrindo a página de Lisboa veria a preamar quatro horas
    // deslocada se o horário viesse do relógio dele.
    wp_localize_script( 'sal-core-balance', 'salBalanco', array(
        'agora'      => esc_url_raw( rest_url( 'sal/v1/agora' ) ),
        'rota'       => esc_url_raw( rest_url( 'sal/v1/rota' ) ),
        'series'     => esc_url_raw( rest_url( 'sal/v1/series' ) ),
        'fuso_horas' => (float) get_option( 'gmt_offset', 0 ),
    ) );

    $janelas = array( '24h' => '24 h', '7d' => '7 dias', '30d' => '30 dias', 'tudo' => 'Tudo' );

    ob_start();
    ?>
    <div class="sal-balanco">
        <div class="sal-balanco__mapa-caixa">
            <div id="sal-balance-map" class="sal-balanco__mapa"></div>
        </div>
        <p class="sal-balanco__estado" id="sal-balance-estado">Carregando a posição do Balanço…</p>

        <div class="sal-balanco__leituras" id="sal-balance-cartoes"></div>

        <div class="sal-balanco__periodo" role="group" aria-label="Período do histórico">
            <?php foreach ( $janelas as $chave => $rotulo ) : ?>
                <button type="button" data-janela="<?php echo esc_attr( $chave ); ?>"
                    <?php echo '24h' === $chave ? 'class="is-ativo" aria-pressed="true"' : 'aria-pressed="false"'; ?>>
                    <?php echo esc_html( $rotulo ); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="sal-balanco__graficos" id="sal-balance-graficos"></div>
    </div>
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
    $api_key     = trim( get_option( 'sal_core_youtube_api_key' ) );
    $channel_id  = trim( get_option( 'sal_core_youtube_channel_id' ) );
    $playlist_id = trim( get_option( 'sal_core_youtube_playlist_id' ) );
    $videos = array();

    // Preferência: playlist quando configurada (filtra a curadoria);
    // senão busca por canal inteiro (comportamento original).
    if ( $api_key && $playlist_id ) {
        // playlistItems não suporta order=date; pegamos até 50 e ordenamos
        // server-side por contentDetails.videoPublishedAt (data real do
        // upload, não data de adição à playlist).
        $url = add_query_arg( array(
            'part'       => 'snippet,contentDetails',
            'playlistId' => $playlist_id,
            'maxResults' => 50,
            'key'        => $api_key,
        ), 'https://www.googleapis.com/youtube/v3/playlistItems' );
        $response = wp_remote_get( $url, array( 'timeout' => 10 ) );
        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            $data  = json_decode( wp_remote_retrieve_body( $response ), true );
            $items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
            usort( $items, function( $a, $b ) {
                $da = $a['contentDetails']['videoPublishedAt'] ?? $a['snippet']['publishedAt'] ?? '';
                $db = $b['contentDetails']['videoPublishedAt'] ?? $b['snippet']['publishedAt'] ?? '';
                return strcmp( $db, $da );
            } );
            foreach ( $items as $item ) {
                if ( isset( $item['snippet']['resourceId']['videoId'] ) ) {
                    $videos[] = array(
                        'id'    => $item['snippet']['resourceId']['videoId'],
                        'title' => $item['snippet']['title'] ?? '',
                    );
                    if ( count( $videos ) >= $count ) {
                        break;
                    }
                }
            }
        }
    } elseif ( $api_key && $channel_id ) {
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
    // Fallback via RSS se API indisponível ou sem chave.
    // YouTube serve feed por playlist em ?playlist_id=... (ordem da playlist).
    if ( ! $videos ) {
        if ( $playlist_id ) {
            $feed_url = 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . urlencode( $playlist_id );
        } else {
            $channel = $channel_id ?: 'UCZ9bK5YKp6-sRPY1lgdPb5w';
            $feed_url = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . urlencode( $channel );
        }
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
        echo '<div class="sal-video"><iframe loading="lazy" src="https://www.youtube-nocookie.com/embed/' . $id . '" title="YouTube video" frameborder="0" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>';
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
 * Shortcode para a página do Clube do #SAL (Apoia.se). Permite
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
    $club_id = $make_page( 'clube-sal', 'Clube do #SAL', 'Em breve novidades para os apoiadores do #SAL' );
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

// Fim do arquivo