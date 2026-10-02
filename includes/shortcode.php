<?php
defined( 'ABSPATH' ) || exit;

add_shortcode( 'simple_contact_form', 'scf_render_form' );

function scf_render_form( $atts ): string {
    $opts = shortcode_atts( [
        'btn_label'   => get_option( 'scf_btn_label', 'Enviar' ),
    ], $atts );

    // Spam tokens
    $hp_name = 'scf_' . md5( 'hp' . NONCE_SALT );
    $token   = base64_encode( time() . '|' . wp_hash( time() ) );

    ob_start();
    ?>
    <div class="scf-wrapper" id="scf-wrapper">
        <div class="scf-card">
            <div class="scf-form-panel">
                <div id="scf-form-content">
                    <div class="scf-alert scf-alert--error" id="scf-error" role="alert"></div>

                    <!-- Spam guards -->
                    <?php $hp_name_attr = esc_attr( $hp_name ); ?>
                    <div class="scf-hp" aria-hidden="true">
                        <label for="<?php echo $hp_name_attr; ?>">Leave this field empty</label>
                        <input type="text" id="<?php echo $hp_name_attr; ?>"
                               name="<?php echo $hp_name_attr; ?>" tabindex="-1" autocomplete="off">
                    </div>
                    <input type="hidden" name="scf_time_token" value="<?php echo esc_attr( $token ); ?>">

                    <div class="scf-fields">
                        <div class="scf-field">
                            <label for="scf_name">Nombre</label>
                            <input type="text" id="scf_name" name="scf_name"
                                   autocomplete="name" placeholder="Tu nombre completo" required>
                        </div>
                        <div class="scf-field">
                            <label for="scf_email">Correo</label>
                            <input type="email" id="scf_email" name="scf_email"
                                   autocomplete="email" placeholder="Tu correo electrónico" required>
                        </div>
                        <div class="scf-field">
                            <label for="scf_phone">Teléfono</label>
                            <input type="tel" id="scf_phone" name="scf_phone"
                                   autocomplete="tel" placeholder="Tu número de teléfono" required>
                        </div>
                        <div class="scf-field">
                            <label for="scf_company">Empresa</label>
                            <input type="text" id="scf_company" name="scf_company"
                                   autocomplete="organization" placeholder="Nombre de tu empresa" required>
                        </div>
                        <div class="scf-field">
                            <label for="scf_message">Mensaje</label>
                            <input type="text" id="scf_message" name="scf_message"
                                   placeholder="¿En qué podemos ayudarte?" required>
                        </div>
                    </div>

                    <button type="button" class="scf-submit" id="scf-submit">
                        <span class="scf-btn-label"><?php echo esc_html( $opts['btn_label'] ); ?></span>
                        <span class="scf-btn-spinner" style="display:none;">Enviando…</span>
                        <span class="scf-btn-arrow" aria-hidden="true">→</span>
                    </button>
                </div>

                <?php
                $ty_title   = scf_thankyou_string( 'title', '¡Gracias!' );
                $ty_message = scf_thankyou_string( 'message', 'Hemos recibido tu mensaje y te contactaremos pronto.' );
                $ty_image   = scf_thankyou_image_url();
                ?>
                <div class="scf-thankyou" id="scf-thankyou" role="status" tabindex="-1"
                     data-url="<?php echo esc_url( scf_thankyou_url() ); ?>" hidden>
                    
                    <?php if ( $ty_image ) : ?>
                        <?php // Opted out of lazy-loading: it's hidden at page load, so lazy-load scripts would never load it. ?>
                        <img class="scf-thankyou-image skip-lazy no-lazyload" data-no-lazy="1" loading="eager"
                             src="<?php echo esc_url( $ty_image ); ?>" alt="">
                    <?php endif; ?>

                     <?php if ( $ty_title ) : ?>
                        <h3 class="scf-thankyou-title"><?php echo esc_html( $ty_title ); ?></h3>
                    <?php endif; ?>
                    
                    <?php if ( $ty_message ) : ?>
                        <div class="scf-thankyou-message"><?php echo wpautop( wp_kses_post( $ty_message ) ); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div><!-- .scf-card -->
    </div>
    <?php
    return ob_get_clean();
}
