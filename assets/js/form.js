( function ( $ ) {
    'use strict';

    $( function () {
        var $submit    = $( '#scf-submit' );
        var $error     = $( '#scf-error' );

        $submit.on( 'click', function () {
            var name    = $.trim( $( '#scf_name'    ).val() );
            var email   = $.trim( $( '#scf_email'   ).val() );
            var phone   = $.trim( $( '#scf_phone'   ).val() );
            var company = $.trim( $( '#scf_company' ).val() );
            var message = $.trim( $( '#scf_message' ).val() );

            $error.hide().text( '' );

            // Client-side quick validation
            var emailReg = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if ( ! name )                { showError( 'Por favor ingresa tu nombre.' );              return; }
            if ( ! emailReg.test(email)) { showError( 'Por favor ingresa un correo válido.' );       return; }
            if ( ! phone )               { showError( 'Por favor ingresa tu teléfono.' );            return; }
            if ( ! company )             { showError( 'Por favor ingresa el nombre de tu empresa.' ); return; }
            if ( ! message )             { showError( 'Por favor ingresa tu mensaje.' );             return; }

            // Disable button, show spinner
            $submit.prop( 'disabled', true );
            $submit.find( '.scf-btn-label'   ).hide();
            $submit.find( '.scf-btn-spinner' ).show();

            var data = {
                action:         'scf_submit',
                nonce:          scf_ajax.nonce,
                scf_name:       name,
                scf_email:      email,
                scf_phone:      phone,
                scf_company:    company,
                scf_message:    message,
                scf_time_token: $( 'input[name="scf_time_token"]' ).val() || '',
            };

            // Honeypot: its name is generated server-side, so read it off the field.
            var $hp = $( '.scf-hp input' );
            if ( $hp.length ) {
                data[ $hp.attr( 'name' ) ] = $hp.val();
            }

            submit( data ).done( function ( res ) {
                if ( res && res.success ) {
                    window.dataLayer = window.dataLayer || [];
                    window.dataLayer.push( { event: 'scf_form_submit' } );
                    if ( res.data && res.data.notify_key ) {
                        sendNotify( res.data.notify_key );
                    }
                    showThankYou();
                } else {
                    showError( ( res && res.data && res.data.message ) || 'An error occurred. Please try again.' );
                    resetButton();
                }
            } ).fail( function () {
                showError( 'Network error. Please check your connection and try again.' );
                resetButton();
            } );
        } );

        // The REST route is faster; admin-ajax is the fallback when the route
        // answers 4xx (a security plugin blocking anonymous REST, a 404, a
        // stale X-WP-Nonce). Anything else isn't retried, since the submission
        // may already have been stored.
        function submit( data ) {
            var ajax = function () {
                return $.ajax( { url: scf_ajax.ajax_url, method: 'POST', data: data, dataType: 'json' } );
            };
            if ( ! scf_ajax.rest_url ) {
                return ajax();
            }

            var deferred = $.Deferred();
            $.ajax( {
                url:      scf_ajax.rest_url,
                method:   'POST',
                data:     data,
                dataType: 'json',
                headers:  { 'X-WP-Nonce': scf_ajax.rest_nonce },
            } ).done( deferred.resolve ).fail( function ( xhr ) {
                var blocked = xhr.status >= 400 && xhr.status < 500;
                ( blocked ? ajax() : $.Deferred().reject() ).done( deferred.resolve ).fail( deferred.reject );
            } );
            return deferred.promise();
        }

        // Fire-and-forget: a beacon survives the visitor leaving the page, and
        // the server falls back to WP-Cron if it never arrives.
        function sendNotify( key ) {
            var fd = new FormData();
            fd.append( 'action', 'scf_notify' );
            fd.append( 'key', key );
            if ( navigator.sendBeacon && navigator.sendBeacon( scf_ajax.ajax_url, fd ) ) {
                return;
            }
            $.post( scf_ajax.ajax_url, { action: 'scf_notify', key: key } );
        }

        function showThankYou() {
            var $thankyou = $( '#scf-thankyou' );
            var url       = $thankyou.data( 'url' );

            $( '#scf-form-content' ).remove();
            $thankyou.prop( 'hidden', false );

            // Show the thank-you URL without loading it. Going Back reloads
            // the form page so the address and content match again.
            if ( url && window.history && history.pushState ) {
                try {
                    history.pushState( { scf: 'thankyou' }, '', url );
                    $( window ).one( 'popstate', function () {
                        window.location.reload();
                    } );
                } catch ( e ) {} // Cross-origin URL (e.g. WPML per-language domains).
            }

            // Move focus for screen readers without scrolling the page.
            if ( $thankyou[0] ) {
                $thankyou[0].focus( { preventScroll: true } );
            }
        }

        function showError( msg ) {
            $error.text( msg ).show();
            $( 'html, body' ).animate( { scrollTop: $error.offset().top - 80 }, 300 );
        }

        function resetButton() {
            $submit.prop( 'disabled', false );
            $submit.find( '.scf-btn-label'   ).show();
            $submit.find( '.scf-btn-spinner' ).hide();
        }
    } );
} )( jQuery );
