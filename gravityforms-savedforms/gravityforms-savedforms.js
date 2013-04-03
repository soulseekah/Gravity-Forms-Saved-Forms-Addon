/* On multi-page forms the Save button should display on each page */
jQuery( document ).bind( 'gform_post_render', function() {
	jQuery( '.gform_next_button' ).after( jQuery( '.gform_save_state' ).clone() );
} );
