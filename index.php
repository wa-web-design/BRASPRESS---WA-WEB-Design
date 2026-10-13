/**
 * WA Web Design — Braspress WooCommerce
 * V8
 *
 * Recursos:
 * - Cotação Braspress por CEP
 * - Autenticação Basic Auth
 * - Classe de envio "pacotes-grandes"
 * - Taxa adicional percentual (%) — aceita valores positivos e negativos
 * - Taxa adicional fixa (R$) — aceita valores positivos e negativos
 * - Peso
 * - Volumes
 * - Cubagem
 * - Valor da mercadoria
 * - Prazo separado do título
 * - Diagnóstico de erros
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * =========================================================
 * REGISTRA O MÉTODO DE ENVIO
 * =========================================================
 */
add_filter( 'woocommerce_shipping_methods', function( $methods ) {

    $methods['wa_braspress'] = 'WA_Braspress_Shipping_Method';

    return $methods;
} );


/**
 * =========================================================
 * CLASSE PRINCIPAL
 * =========================================================
 */
class WA_Braspress_Shipping_Method extends WC_Shipping_Method {


    /**
     * Classe de envio que habilita a Braspress.
     */
    const BRASPRESS_SHIPPING_CLASS = 'pacotes-grandes';


    /**
     * =====================================================
     * CONSTRUTOR
     * =====================================================
     */
    public function __construct( $instance_id = 0 ) {

        $this->id                 = 'wa_braspress';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = 'Braspress';
        $this->method_description = 'Cotação de frete utilizando a API da Braspress.';

        $this->supports = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        );

        $this->title = 'Braspress';

        $this->init();

        $this->enabled = $this->get_option(
            'enabled',
            'yes'
        );

        $this->title = $this->get_option(
            'title',
            'Braspress'
        );
    }


    /**
     * =====================================================
     * CONFIGURAÇÕES
     * =====================================================
     */
    public function init() {

        $this->init_form_fields();

        $this->init_settings();

        add_action(
            'woocommerce_update_options_shipping_' . $this->id,
            array(
                $this,
                'process_admin_options'
            )
        );
    }


    /**
     * =====================================================
     * CAMPOS DO ADMIN
     * =====================================================
     */
    public function init_form_fields() {

        $this->instance_form_fields = array(

            'enabled' => array(
                'title'   => 'Ativar',
                'type'    => 'checkbox',
                'label'   => 'Ativar Braspress',
                'default' => 'yes',
            ),

            'title' => array(
                'title'       => 'Título',
                'type'        => 'text',
                'description' => 'Nome que aparecerá no checkout.',
                'default'     => 'Braspress',
                'desc_tip'    => true,
            ),

            'api_user' => array(
                'title'       => 'Usuário API',
                'type'        => 'text',
                'description' => 'Usuário fornecido pela Braspress.',
                'default'     => '',
            ),

            'api_password' => array(
                'title'       => 'Senha API',
                'type'        => 'password',
                'description' => 'Senha fornecida pela Braspress.',
                'default'     => '',
            ),

            'cnpj_remetente' => array(
                'title'       => 'CNPJ Remetente',
                'type'        => 'text',
                'description' => 'CNPJ utilizado como remetente e destinatário da consulta.',
                'default'     => '',
            ),

            'cep_origem' => array(
                'title'       => 'CEP de Origem',
                'type'        => 'text',
                'description' => 'CEP de onde as mercadorias serão enviadas.',
                'default'     => '',
            ),

            'tipo_frete' => array(
                'title'   => 'Tipo de Frete',
                'type'    => 'select',
                'default' => '1',
                'options' => array(
                    '1' => 'CIF',
                    '2' => 'FOB',
                    '3' => 'Consignado',
                ),
            ),

            /**
             * =================================================
             * TAXA PERCENTUAL
             *
             * Agora aceita valores negativos.
             *
             * Exemplos:
             * 10   = acrescenta 10%
             * 20   = acrescenta 20%
             * 0    = sem alteração
             * -10  = desconto de 10%
             * -50  = desconto de 50%
             * =================================================
             */
            'taxa_percentual' => array(
                'title'       => 'Taxa adicional (%)',
                'type'        => 'number',
                'description' => 'Percentual acrescentado ou descontado do valor retornado pela Braspress. Ex.: 10 = +10%, -50 = desconto de 50%.',
                'default'     => '0',
                'desc_tip'    => true,
                'custom_attributes' => array(
                    'step' => '0.01',
                ),
            ),

            /**
             * =================================================
             * TAXA FIXA
             *
             * Também aceita valores negativos.
             *
             * Exemplos:
             * 20   = acrescenta R$ 20,00
             * -20  = desconta R$ 20,00
             * =================================================
             */
            'taxa_fixa' => array(
                'title'       => 'Taxa adicional (R$)',
                'type'        => 'number',
                'description' => 'Valor fixo acrescentado ou descontado do frete retornado pela Braspress. Ex.: 20 = +R$ 20, -20 = desconto de R$ 20.',
                'default'     => '0',
                'desc_tip'    => true,
                'custom_attributes' => array(
                    'step' => '0.01',
                ),
            ),

            'debug' => array(
                'title'   => 'Debug',
                'type'    => 'checkbox',
                'label'   => 'Ativar logs da Braspress',
                'default' => 'no',
            ),
        );
    }


    /**
     * =====================================================
     * LOG
     * =====================================================
     */
    private function log( $message ) {

        if (
            'yes' !== $this->get_option(
                'debug',
                'no'
            )
        ) {
            return;
        }

        if ( function_exists( 'wc_get_logger' ) ) {

            $logger = wc_get_logger();

            $logger->info(
                $message,
                array(
                    'source' => 'wa-braspress',
                )
            );
        }
    }


    /**
     * =====================================================
     * LIMPA CEP
     * =====================================================
     */
    private function clean_cep( $cep ) {

        return preg_replace(
            '/\D+/',
            '',
            (string) $cep
        );
    }


    /**
     * =====================================================
     * LIMPA CNPJ
     * =====================================================
     */
    private function clean_cnpj( $cnpj ) {

        return preg_replace(
            '/\D+/',
            '',
            (string) $cnpj
        );
    }


    /**
     * =====================================================
     * VERIFICA CLASSE DO PRODUTO
     * =====================================================
     */
    private function product_has_braspress_class( $product ) {

        if (
            ! $product
            ||
            ! is_a(
                $product,
                'WC_Product'
            )
        ) {
            return false;
        }

        $shipping_class =
            $product->get_shipping_class();


        /**
         * Caso seja variação e a classe não esteja
         * diretamente disponível, verifica o produto pai.
         */
        if (
            empty( $shipping_class )
            &&
            $product->is_type( 'variation' )
        ) {

            $parent_id =
                $product->get_parent_id();

            if ( $parent_id ) {

                $parent_product =
                    wc_get_product(
                        $parent_id
                    );

                if ( $parent_product ) {

                    $shipping_class =
                        $parent_product->get_shipping_class();
                }
            }
        }

        return (
            self::BRASPRESS_SHIPPING_CLASS ===
            $shipping_class
        );
    }


    /**
     * =====================================================
     * VERIFICA SE O PACOTE TEM PRODUTO BRASPRESS
     * =====================================================
     */
    private function package_has_braspress_product( $package ) {

        if (
            empty( $package['contents'] )
            ||
            ! is_array(
                $package['contents']
            )
        ) {
            return false;
        }

        foreach (
            $package['contents']
            as $cart_item
        ) {

            if (
                empty( $cart_item['data'] )
                ||
                ! is_a(
                    $cart_item['data'],
                    'WC_Product'
                )
            ) {
                continue;
            }

            if (
                $this->product_has_braspress_class(
                    $cart_item['data']
                )
            ) {
                return true;
            }
        }

        return false;
    }


    /**
     * =====================================================
     * CONVERSÃO PESO → KG
     * =====================================================
     */
    private function weight_to_kg(
        $weight,
        $unit
    ) {

        $weight = (float) $weight;

        switch (
            strtolower( $unit )
        ) {

            case 'g':
                return $weight / 1000;

            case 'lbs':
            case 'lb':
                return $weight * 0.45359237;

            case 'oz':
                return $weight * 0.0283495231;

            case 'kg':
            default:
                return $weight;
        }
    }


    /**
     * =====================================================
     * CONVERSÃO DIMENSÕES → METROS
     * =====================================================
     */
    private function dimension_to_meters(
        $value,
        $unit
    ) {

        $value = (float) $value;

        switch (
            strtolower( $unit )
        ) {

            case 'mm':
                return $value / 1000;

            case 'cm':
                return $value / 100;

            case 'm':
                return $value;

            case 'in':
                return $value * 0.0254;

            case 'yd':
                return $value * 0.9144;

            default:
                return $value / 100;
        }
    }


    /**
     * =====================================================
     * DIAGNÓSTICO
     * =====================================================
     */
    private function add_diagnostic_rate(
        $package,
        $message
    ) {

        $this->log(
            'DIAGNÓSTICO: ' .
            $message
        );

        $this->add_rate(
            array(
                'id' =>
                    $this->get_rate_id(),

                'label' =>
                    'BRASPRESS — ' .
                    $message,

                'cost' =>
                    0,

                'package' =>
                    $package,
            )
        );
    }


    /**
     * =====================================================
     * CALCULA FRETE
     * =====================================================
     */
    public function calculate_shipping(
        $package = array()
    ) {

        /**
         * -------------------------------------------------
         * 1. CLASSE DE ENVIO
         * -------------------------------------------------
         */
        if (
            ! $this->package_has_braspress_product(
                $package
            )
        ) {

            $this->log(
                'Pacote sem produtos da classe "' .
                self::BRASPRESS_SHIPPING_CLASS .
                '". Braspress não será exibida.'
            );

            return;
        }


        /**
         * -------------------------------------------------
         * 2. CONFIGURAÇÕES
         * -------------------------------------------------
         */
        $api_user =
            trim(
                $this->get_option(
                    'api_user',
                    ''
                )
            );

        $api_password =
            trim(
                $this->get_option(
                    'api_password',
                    ''
                )
            );

        $cnpj_remetente =
            $this->clean_cnpj(
                $this->get_option(
                    'cnpj_remetente',
                    ''
                )
            );

        $cep_origem =
            $this->clean_cep(
                $this->get_option(
                    'cep_origem',
                    ''
                )
            );

        $tipo_frete =
            (string) $this->get_option(
                'tipo_frete',
                '1'
            );


        /**
         * -------------------------------------------------
         * 3. TAXAS
         *
         * Valores negativos são permitidos.
         * -------------------------------------------------
         */
        $taxa_percentual =
            (float) $this->get_option(
                'taxa_percentual',
                '0'
            );

        $taxa_fixa =
            (float) $this->get_option(
                'taxa_fixa',
                '0'
            );


        /**
         * -------------------------------------------------
         * 4. CEP DESTINO
         * -------------------------------------------------
         */
        $cep_destino = '';

        if (
            ! empty(
                $package['destination']['postcode']
            )
        ) {

            $cep_destino =
                $package['destination']['postcode'];

        } elseif (
            WC()->customer
        ) {

            $cep_destino =
                WC()->customer->get_shipping_postcode();

            if (
                empty( $cep_destino )
            ) {

                $cep_destino =
                    WC()->customer->get_billing_postcode();
            }
        }

        $cep_destino =
            $this->clean_cep(
                $cep_destino
            );


        /**
         * -------------------------------------------------
         * 5. VALIDAÇÕES
         * -------------------------------------------------
         */
        if (
            empty( $api_user )
            ||
            empty( $api_password )
        ) {

            $this->add_diagnostic_rate(
                $package,
                'API não configurada'
            );

            return;
        }


        if (
            empty( $cnpj_remetente )
        ) {

            $this->add_diagnostic_rate(
                $package,
                'CNPJ remetente não configurado'
            );

            return;
        }


        if (
            strlen( $cep_origem ) !== 8
        ) {

            $this->add_diagnostic_rate(
                $package,
                'CEP de origem inválido'
            );

            return;
        }


        if (
            strlen( $cep_destino ) !== 8
        ) {

            $this->add_diagnostic_rate(
                $package,
                'CEP de destino inválido'
            );

            return;
        }


        /**
         * -------------------------------------------------
         * 6. VALOR DA MERCADORIA
         * -------------------------------------------------
         */
        $valor_mercadoria = 0;

        if ( WC()->cart ) {

            $valor_mercadoria =
                (float)
                WC()->cart->get_cart_contents_total();

            $valor_mercadoria +=
                (float)
                WC()->cart->get_discount_total();
        }


        /**
         * -------------------------------------------------
         * 7. PESO / VOLUMES / CUBAGEM
         * -------------------------------------------------
         */
        $peso_total    = 0;
        $volumes_total = 0;
        $cubagem       = array();

        $weight_unit =
            get_option(
                'woocommerce_weight_unit',
                'kg'
            );

        $dimension_unit =
            get_option(
                'woocommerce_dimension_unit',
                'cm'
            );


        if (
            empty( $package['contents'] )
            ||
            ! is_array(
                $package['contents']
            )
        ) {

            $this->add_diagnostic_rate(
                $package,
                'Nenhum produto encontrado'
            );

            return;
        }


        foreach (
            $package['contents']
            as $cart_item
        ) {

            if (
                empty( $cart_item['data'] )
                ||
                ! is_a(
                    $cart_item['data'],
                    'WC_Product'
                )
            ) {
                continue;
            }

            $product =
                $cart_item['data'];

            $quantity =
                ! empty(
                    $cart_item['quantity']
                )
                    ? (int)
                        $cart_item['quantity']
                    : 1;


            /**
             * PESO
             */
            $product_weight =
                (float)
                $product->get_weight();

            if (
                $product_weight > 0
            ) {

                $product_weight_kg =
                    $this->weight_to_kg(
                        $product_weight,
                        $weight_unit
                    );

                $peso_total +=
                    $product_weight_kg *
                    $quantity;
            }


            /**
             * DIMENSÕES
             */
            $length =
                (float)
                $product->get_length();

            $width =
                (float)
                $product->get_width();

            $height =
                (float)
                $product->get_height();


            $length_m =
                $this->dimension_to_meters(
                    $length,
                    $dimension_unit
                );

            $width_m =
                $this->dimension_to_meters(
                    $width,
                    $dimension_unit
                );

            $height_m =
                $this->dimension_to_meters(
                    $height,
                    $dimension_unit
                );


            if (
                $length_m > 0
                &&
                $width_m > 0
                &&
                $height_m > 0
            ) {

                $cubagem[] = array(

                    'comprimento' =>
                        round(
                            $length_m,
                            4
                        ),

                    'largura' =>
                        round(
                            $width_m,
                            4
                        ),

                    'altura' =>
                        round(
                            $height_m,
                            4
                        ),

                    'volumes' =>
                        $quantity,
                );
            }


            $volumes_total +=
                $quantity;
        }


        /**
         * -------------------------------------------------
         * 8. VALIDA PESO
         * -------------------------------------------------
         */
        if (
            $peso_total <= 0
        ) {

            $this->add_diagnostic_rate(
                $package,
                'Peso dos produtos não informado'
            );

            return;
        }


        /**
         * -------------------------------------------------
         * 9. VOLUMES
         * -------------------------------------------------
         */
        if (
            $volumes_total <= 0
        ) {

            $volumes_total = 1;
        }


        /**
         * -------------------------------------------------
         * 10. DESTINATÁRIO
         * -------------------------------------------------
         *
         * Mantido conforme a versão original.
         */
        $cnpj_destinatario =
            $cnpj_remetente;


        /**
         * -------------------------------------------------
         * 11. PAYLOAD
         * -------------------------------------------------
         */
        $payload = array(

            'cnpjRemetente' =>
                $cnpj_remetente,

            'cnpjDestinatario' =>
                $cnpj_destinatario,

            'modal' =>
                'R',

            'tipoFrete' =>
                $tipo_frete,

            'cepOrigem' =>
                $cep_origem,

            'cepDestino' =>
                $cep_destino,

            'vlrMercadoria' =>
                round(
                    $valor_mercadoria,
                    2
                ),

            'peso' =>
                round(
                    $peso_total,
                    3
                ),

            'volumes' =>
                $volumes_total,

            'cubagem' =>
                $cubagem,
        );


        /**
         * -------------------------------------------------
         * 12. LOG PAYLOAD
         * -------------------------------------------------
         */
        $this->log(
            'Solicitação Braspress: ' .
            wp_json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE
            )
        );


        /**
         * -------------------------------------------------
         * 13. API
         * -------------------------------------------------
         */
        $api_url =
            'https://api.braspress.com/v1/cotacao/calcular/json';


        $response =
            wp_remote_post(
                $api_url,
                array(

                    'timeout' =>
                        30,

                    'headers' =>
                        array(

                            'Authorization' =>
                                'Basic ' .
                                base64_encode(
                                    $api_user .
                                    ':' .
                                    $api_password
                                ),

                            'Content-Type' =>
                                'application/json',

                            'Accept' =>
                                'application/json',
                        ),

                    'body' =>
                        wp_json_encode(
                            $payload
                        ),
                )
            );


        /**
         * -------------------------------------------------
         * 14. ERRO WORDPRESS
         * -------------------------------------------------
         */
        if (
            is_wp_error(
                $response
            )
        ) {

            $error_message =
                $response->get_error_message();

            $this->add_diagnostic_rate(
                $package,
                'Erro de conexão: ' .
                $error_message
            );

            return;
        }


        /**
         * -------------------------------------------------
         * 15. STATUS HTTP
         * -------------------------------------------------
         */
        $http_code =
            wp_remote_retrieve_response_code(
                $response
            );

        $body =
            wp_remote_retrieve_body(
                $response
            );


        $this->log(
            'HTTP Braspress: ' .
            $http_code .
            ' | BODY: ' .
            $body
        );


        if (
            $http_code < 200
            ||
            $http_code >= 300
        ) {

            $this->add_diagnostic_rate(
                $package,
                'API HTTP ' .
                $http_code
            );

            return;
        }


        /**
         * -------------------------------------------------
         * 16. JSON
         * -------------------------------------------------
         */
        $data =
            json_decode(
                $body,
                true
            );


        if (
            null === $data
        ) {

            $this->add_diagnostic_rate(
                $package,
                'Resposta inválida da API'
            );

            return;
        }


        /**
         * -------------------------------------------------
         * 17. ERRO BRASPRESS
         * -------------------------------------------------
         */
        if (
            isset(
                $data['statusCode']
            )
            &&
            (int)
            $data['statusCode'] !== 200
        ) {

            $message = '';

            if (
                ! empty(
                    $data['message']
                )
            ) {

                $message =
                    $data['message'];
            }

            if (
                empty( $message )
                &&
                ! empty(
                    $data['errorList']
                )
            ) {

                $message =
                    wp_json_encode(
                        $data['errorList'],
                        JSON_UNESCAPED_UNICODE
                    );
            }

            if (
                empty( $message )
            ) {

                $message =
                    'Erro retornado pela API';
            }

            $this->add_diagnostic_rate(
                $package,
                $message
            );

            return;
        }


        /**
         * -------------------------------------------------
         * 18. FRETE
         * -------------------------------------------------
         */
        if (
            ! isset(
                $data['totalFrete']
            )
        ) {

            $this->add_diagnostic_rate(
                $package,
                'API não retornou totalFrete'
            );

            return;
        }


        $frete_braspress =
            (float)
            $data['totalFrete'];


        /**
         * -------------------------------------------------
         * 19. TAXA %
         *
         * Aceita positivo ou negativo.
         *
         * Exemplo:
         *
         * Frete = R$ 100
         * Taxa = -50%
         *
         * Resultado = R$ 50
         * -------------------------------------------------
         */
        $valor_taxa_percentual = 0;

        if (
            $taxa_percentual != 0
        ) {

            $valor_taxa_percentual =
                $frete_braspress *
                (
                    $taxa_percentual /
                    100
                );
        }


        /**
         * -------------------------------------------------
         * 20. TAXA FIXA
         *
         * Também aceita positivo ou negativo.
         * -------------------------------------------------
         */
        $valor_taxa_fixa = 0;

        if (
            $taxa_fixa != 0
        ) {

            $valor_taxa_fixa =
                $taxa_fixa;
        }


        /**
         * -------------------------------------------------
         * 21. FRETE FINAL
         * -------------------------------------------------
         */
        $frete_final =
            $frete_braspress
            +
            $valor_taxa_percentual
            +
            $valor_taxa_fixa;


        /**
         * Evita que o frete final fique negativo.
         *
         * Exemplo:
         * Frete = R$ 50
         * Taxa = -100%
         * Taxa fixa = -R$ 10
         *
         * Resultado matemático = -R$ 10
         *
         * Nesse caso o WooCommerce receberá R$ 0.
         */
        if (
            $frete_final < 0
        ) {

            $frete_final = 0;
        }


        $frete_final =
            round(
                $frete_final,
                2
            );


        /**
         * -------------------------------------------------
         * 22. PRAZO RETORNADO PELA BRASPRESS
         * -------------------------------------------------
         */
        $prazo = '';

        if (
            isset(
                $data['prazo']
            )
        ) {

            $prazo =
                trim(
                    (string)
                    $data['prazo']
                );
        }


        /**
         * -------------------------------------------------
         * 23. ID DA COTAÇÃO
         * -------------------------------------------------
         */
        $rate_id =
            $this->get_rate_id();


        if (
            ! empty(
                $data['id']
            )
        ) {

            $rate_id .=
                '-' .
                sanitize_title(
                    (string)
                    $data['id']
                );
        }


        /**
         * -------------------------------------------------
         * 24. TÍTULO
         * -------------------------------------------------
         */
        $rate_title =
            $this->title;


        /**
         * -------------------------------------------------
         * 25. PRAZO FORMATADO
         * -------------------------------------------------
         */
        $delivery_time = '';

        if (
            ! empty(
                $prazo
            )
        ) {

            if (
                is_numeric(
                    $prazo
                )
            ) {

                $delivery_time =
                    'Receba até ' .
                    $prazo .
                    ' dias úteis';

            } else {

                $delivery_time =
                    'Receba até ' .
                    $prazo;
            }
        }


        /**
         * -------------------------------------------------
         * 26. LOG FINAL
         * -------------------------------------------------
         */
        $this->log(
            'Frete Braspress: R$ ' .
            number_format(
                $frete_braspress,
                2,
                ',',
                '.'
            )
            .
            ' | Taxa %: ' .
            $taxa_percentual .
            '% | Valor taxa %: R$ ' .
            number_format(
                $valor_taxa_percentual,
                2,
                ',',
                '.'
            )
            .
            ' | Taxa fixa: R$ ' .
            number_format(
                $valor_taxa_fixa,
                2,
                ',',
                '.'
            )
            .
            ' | Frete final: R$ ' .
            number_format(
                $frete_final,
                2,
                ',',
                '.'
            )
            .
            ' | Prazo: ' .
            $delivery_time
        );


        /**
         * -------------------------------------------------
         * 27. ADICIONA FRETE
         * -------------------------------------------------
         */
        $rate_args = array(

            'id' =>
                $rate_id,

            'label' =>
                $rate_title,

            'cost' =>
                $frete_final,

            'package' =>
                $package,
        );


        /**
         * -------------------------------------------------
         * 28. METADADO DO PRAZO
         * -------------------------------------------------
         */
        if (
            ! empty(
                $delivery_time
            )
        ) {

            $rate_args['meta_data'] = array(

                'delivery_time' =>
                    $delivery_time,
            );
        }


        $this->add_rate(
            $rate_args
        );
    }
}
