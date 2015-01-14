/* On multi-page forms the Save button should display on each page */
jQuery( document ).bind( 'gform_post_render', function( e, form_id, page ) {
	var save_button = jQuery( '#gform_' + form_id + ' .gform_save_state' );
	if ( !save_button ) return;
	save_button.parents( 'form' ).find( '.gform_next_button' ).after( save_button.clone() );
} );
