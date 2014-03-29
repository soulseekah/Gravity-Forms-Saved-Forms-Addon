<?php
	/*
		Plugin Name: Gravity Forms Saved Forms Add-On
		Author: Gennady Kovshenin
		Description: Adds state to forms allowing users to save form for later
		Version: 0.4.2
		Author URI: http://codeseekah.com
	*/


	class GFStatefulForms {
		private static $instance = null;
		private static $textdomain = 'gravitforms-statefulforms';

		public function __construct() {
			if ( is_object( self::$instance ) && get_class( self::$instance == __CLASS__ ) )
				wp_die( __CLASS__.' can have only one instance; won\'t initialize, use '.__CLASS__.'::get_instance()' );
			self::$instance = $this;

			$this->bootstrap();
		}

		public static function get_instance() {
			return ( get_class( self::$instance ) == __CLASS__ ) ? self::$instance : new self;
		}

		public function bootstrap() {
			/* Attach hooks and other early initialization */
			add_action( 'plugins_loaded', array( $this, 'early_init' ) );

			/* Form editor script - using admin_footer for consistency between GF 1.7 and previous versions
                where the form settings have moved and the gform_editor_js hook is not available */
			add_action( 'admin_footer', array( $this, 'editor_script' ) );

			/* Form Settings/Advanced */
			add_action( 'gform_advanced_settings', array( $this, 'advanced_form_settings' ), null, 2 );
			add_filter( 'gform_notification_ui_settings', array( $this, 'notification_settings' ), null, 3 );
			add_filter( 'gform_pre_notification_save', array( $this, 'save_notification_settings' ), null, 2 );

			/* Form frontend logic */
			add_filter( 'gform_pre_render', array( $this, 'try_restore_saved_state' ) );
			add_filter( 'gform_submit_button', array( $this, 'form_add_save_button'), null, 2 );
			add_filter( 'gform_validation', array( $this, 'form_submit_save_autovalidate' ) );
			add_filter( 'gform_confirmation', array( $this, 'form_save_confirmation' ), null, 4 );
			add_filter( 'gform_disable_notification', array( $this, 'disable_notification_on_save' ), null, 4 );
			add_action( 'gform_enqueue_scripts', array( $this, 'enqueue_frontend_javascript' ), null, 2 );
			add_action( 'init', array( $this, 'maybe_save_confirmation' ) );

			/* Pending and completed entries */
			add_filter( 'gform_addon_navigation', array( $this, 'add_pending_completed_entries_item' ) );
		}

		public function early_init() {
			/* Load languages if available */
			load_plugin_textdomain( self::$textdomain, false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		}

		public function advanced_form_settings( $position, $form_id ) {
			if ( $position != 800 ) return; /* need bottom of form */
			?>
				<li>
					<input type="checkbox" id="gform_enable_form_state" />
					<label for="gform_enable_form_state"><?php _e( "Enable form state (being a user is required, enable \"Require Login\" above)", self::$textdomain ) ?></label>
				</li>
			<?php
		}

		public function editor_script() {
			if( rgget('page') != 'gf_edit_forms' )
				return;

			?>
				<script type="text/javascript">
					jQuery( '#gform_enable_form_state' ).attr( 'checked', form.enableFormState ? true : false ).change( function() {
						form.enableFormState = jQuery( '#gform_enable_form_state' ).is( ':checked' );
					} );
					form.enableFormState = jQuery( '#gform_enable_form_state' ).is( ':checked' );
				</script>
			<?php
		}

		public function notification_settings( $ui_settings, $notification, $form ) {
			if ( !isset( $form['enableFormState'] ) || !$form['enableFormState'] ) return $ui_settings;

			ob_start();
			?>
				<tr valign="top">
					<th scope="row">
						<label for="gform_notification_send_on_save_state"><?php _e( 'Send on save state', 'gravityforms' ); ?></label>
					</th>
					<td>
						<?php if ( !isset( $notification['sendOnSave'] ) ) $notification['sendOnSave'] = false; ?>
						<input type="checkbox" name="gform_notification_send_on_save_state" id="gform_notification_send_on_save_state" value="1" <?php checked( $notification['sendOnSave'], true ); ?>/>
						<label for="gform_notification_send_on_save_state" class="inline"><?php _e( 'Send this notification when the form is saved', 'gravityforms' ); ?></label>
					</td>
				</tr>
			<?php
			$ui_settings['notification_send_on_save_state'] = ob_get_clean();

			return $ui_settings;
		}

		public function save_notification_settings( $notification, $form ) {
			$notification['sendOnSave'] = isset( $_POST['gform_notification_send_on_save_state'] );
			return $notification;
		}

		public function try_restore_saved_state( $form ) {
			if ( !isset( $form['enableFormState'] ) || !$form['enableFormState'] ) return $form;

			$user = wp_get_current_user();

			$lead_id = get_user_meta( $user->ID, 'has_pending_form_'.$form['id'], true );
			if ( !$lead_id ) return $form;

			$lead = RGFormsModel::get_lead( $lead_id );

			/* populate the available values */
			foreach ( $form['fields'] as $form_part ) {
				if ( $form_part['inputs'] === null || $form_part['inputs'] === '' ) { /* single-part */
					$input_id = $form_part['id'];
					if ( !isset( $lead[strval( $input_id )] ) ) continue;

					$input_name = 'input_' . str_replace( '.', '_', strval( $input_id ) );

					// only add value from saved lead if new value is not entered
					if( isset( $_POST[$input_name] ) && !empty( $_POST[$input_name] ) )
						continue;

					$_POST[$input_name] = $this->maybe_transform_data( $lead[strval( $input_id )], $lead, $form_part, $form );

				} else foreach ( $form_part['inputs'] as $input ) { /* multi-part */
					if ( !isset( $lead[strval( $input['id'] )] ) ) continue;

					$input_name = 'input_' . str_replace( '.', '_', strval( $input['id'] ) );

					// only add value from saved lead if new value is not entered
					if( isset( $_POST[$input_name] ) && !empty( $_POST[$input_name] ) )
						continue;

					$_POST[$input_name] = $this->maybe_transform_data( $lead[strval( $input['id'] )], $lead, $form_part, $form );

				}

			}

			$_POST['is_submit_'.$form['id']] = '1'; /* force the form to be poisoned */

			return $form;
		}

		private function maybe_transform_data( $data, $lead, $form_part, $form ) {
			if ( $form_part['type']  == 'list' && $form_part['enableColumns'] ) {
				$_data = array();
				foreach ( unserialize( $data ) as $row ) {
					$_data = array_merge( $_data, array_values( $row ) );
				}
				return $_data;
			}
			return $data;
		}

		public function form_add_save_button( $button_input, $form ) {
			/* there's nothing to be done if form state or login requirements are off */
			if ( !isset($form['requireLogin']) || !isset($form['enableFormState']) ) return $button_input;
			if ( !$form['requireLogin'] || !$form['enableFormState'] ) return $button_input;

			$tabindex_match = false; /* get the proper tabindex */
			if ( preg_match( "#tabindex\\s*=\\s*[\"'](\\d+)[\"']#", $button_input, $_tabindex_match ) == 1 ) {
				$tabindex_match = intval( $_tabindex_match[1] ) + 1;
			}

			$button_input .= '<input type="submit" id="gform_save_state_'.$form['id'].'" class="gform_save_state button gform_button" name="gform_save_state_'.$form['id'].'" value="'
							.__( "Save for later", self::$textdomain ).'" tabindex="'.$tabindex_match.'">';
			return $button_input;
		}

		public function enqueue_frontend_javascript( $form, $is_ajax ) {
			/* For multi-page forms, the save button has to also show */
			if ( !isset($form['requireLogin']) || !isset($form['enableFormState']) ) return;
			if ( !$form['requireLogin'] || !$form['enableFormState'] ) return;
			if ( !GFCommon::has_pages( $form ) ) return;
			wp_enqueue_script( 'gravityforms-savedforms', plugins_url( 'gravityforms-savedforms.js', __FILE__ ), array( 'jquery' ) );
		}

		public function form_submit_save_autovalidate( $validation_result ) {

			if ( !isset( $validation_result['form']['enableFormState'] ) ) return $validation_result;
			if ( !$validation_result['form']['enableFormState'] ) return $validation_result;
			if ( !isset( $_POST['gform_save_state_'.$validation_result['form']['id']] ) ) return $validation_result;

			/* forms that support states can be saved even if not valid */
			$validation_result['is_valid'] = true;

			return $validation_result;
		}

		public function form_save_confirmation( $confirmation, $form, $lead, $ajax ) {

			if ( !isset( $form['enableFormState'] ) || !$form['enableFormState'] ) return $confirmation;

			$user = wp_get_current_user();

			if ( !isset( $_POST['gform_save_state_'.$form['id']] ) ) {
				/* remove all saved data for this form and user */
				delete_user_meta( $user->ID, 'has_pending_form_'.$form['id'] );
				update_user_meta( $user->ID, 'completed_form_'.$form['id'], $lead['id'] );
				return $confirmation;
			}

			if ( !isset( $_POST['gform_save_state_'.$form['id']] ) ) return $confirmation; /* this should never happend */

			/* set pending to user id */
			gform_update_meta( $lead['id'], 'is_pending', $user->ID );
			/* set latest pending */
			update_user_meta( $user->ID, 'has_pending_form_'.$form['id'], $lead['id'] );
			/* set lead to pending */
			RGFormsModel::update_lead_property( $lead['id'], 'status', 'pending', false, true );

			do_action( 'gform_save_state', $form, $lead );

			$confirmation = __( 'Your progress has been saved. You can return to this form anytime in the future to complete it.' );
			return $confirmation;
		}

		public function maybe_save_confirmation() {
			/* On paged forms all processing is suppressed, so we still need to do something */
			$form_id = isset( $_POST['gform_submit'] ) ? $_POST['gform_submit'] : 0;
			if ( !$form_id ) return;

			if ( !isset( $_POST['gform_save_state_'.$form_id] ) ) return;

			$form_info = RGFormsModel::get_form( $form_id );
			$is_valid_form = $form_info && $form_info->is_active;

			if ( !$is_valid_form ) return;

			$form = RGFormsModel::get_form_meta( $form_id );

			if ( !isset($form['requireLogin']) || !isset($form['enableFormState']) ) return;
			if ( !$form['requireLogin'] || !$form['enableFormState'] ) return;
			if ( !GFCommon::has_pages( $form ) ) return;

			/* Spoof the page number and make-believe */
			$_POST[ "gform_target_page_number_{$form_id}" ] = 65535;
			require_once( GFCommon::get_base_path() . '/form_display.php' );
			GFFormDisplay::process_form( $form_id );
		}

		public function disable_notification_on_save( $is_disabled, $notification, $form, $entry ) {
			if ( !isset( $form['enableFormState'] ) ) return $is_disabled;
			if ( !$form['enableFormState'] ) return $is_disabled;

			if ( !isset( $_POST['gform_save_state_'.$form['id']] ) ) {
				/* This is a real submit */
				$is_disabled = isset( $notification['sendOnSave'] ) && $notification['sendOnSave'];
				return $is_disabled;
			}

			/* This is a save submit */
			if ( isset( $notification['sendOnSave'] ) && $notification['sendOnSave'] ) return $is_disabled;
			
			$is_disabled = true;
			return $is_disabled;
		}

		public function add_pending_completed_entries_item( $menu_items ) {
			$has_full_access = current_user_can( 'gform_full_access' );
			/* Adds a new menu item to Gravity Forms */
			$pending_completed_item = array(
					'name' => 'gf_pending_completed_entries',
					'label' => __( 'Pending/Completed Entries', self::$textdomain ),
					'callback' => array( $this, 'pending_completed_entries_screen' ),
					'permission' => $has_full_access ? 'gform_full_access' : 'gravityforms_view_entries'
				);
			$menu_items []= $pending_completed_item;
			return $menu_items;
		}

		public function pending_completed_entries_screen() {

			$forms = RGFormsModel::get_forms( null, 'title' );
			$id = RGForms::get( 'id' );
			if( sizeof($forms) == 0 ) {
				?>
					<div style="margin:50px 0 0 10px;">
					<?php echo sprintf(__("You don't have any active forms. Let's go %screate one%s", "gravityforms"), '<a href="?page=gravityforms.php&id=0">', '</a>'); ?>
					</div>
				<?php
				return;
			} else {
				if( empty($id) ) $form_id = $forms[0]->id;
				else $form_id = $id;
			}
			$form = RGFormsModel::get_form_meta( $form_id );

			if ( isset( $_GET['clear'] ) ):
				check_admin_referer( 'gfstate-clear' );
				$this->cleanup_saved_entries( $form['id'] ); /* cleanup while we're here */
			endif;

			?>
				<link rel="stylesheet" href="<?php echo GFCommon::get_base_url() ?>/css/admin.css" type="text/css" />
				<div class="wrap">
					<div class="icon32" id="gravity-entry-icon"><br></div>
					<h2><?php _e( 'Pending/Completed Entries for ', self::$textdomain ); echo $form['title']; ?></h2>

					<script type="text/javascript">
						function GF_ReplaceQuery(key, newValue){
							var new_query = "";
							var query = document.location.search.substring(1);
							var ary = query.split("&");
							var has_key=false;
							for (i=0; i < ary.length; i++) {
								var key_value = ary[i].split("=");

								if (key_value[0] == key){
									new_query += key + "=" + newValue + "&";
									has_key = true;
								}
								else if(key_value[0] != "display_settings"){
									new_query += key_value[0] + "=" + key_value[1] + "&";
								}
							}

							if(new_query.length > 0)
								new_query = new_query.substring(0, new_query.length-1);

							if(!has_key)
								new_query += new_query.length > 0 ? "&" + key + "=" + newValue : "?" + key + "=" + newValue;

							return new_query;
						}
						function GF_SwitchForm(id){
							if(id.length > 0){
								query = GF_ReplaceQuery("id", id);
								query = query.replace("gf_new_form", "gf_edit_forms");
								document.location = "?" + query;
							}
						}
					</script>

					<div id="gf_form_toolbar">
						<ul id="gf_form_toolbar_links">
							<li class="gf_form_switcher">
								<label for="export_form"><?php _e( 'Select A Form', 'gravityforms' ) ?></label>

								<?php
								if( RG_CURRENT_VIEW != 'entry' ): ?>
									<select name="form_switcher" id="form_switcher" onchange="GF_SwitchForm(jQuery(this).val());">
										<option value=""><?php _e( 'Switch Form', 'gravityforms' ) ?></option>
										<?php foreach($forms as $form_info): ?>
											<option value="<?php echo $form_info->id ?>"><?php echo $form_info->title ?></option>
										<?php endforeach; ?>
									</select>
								<?php
								endif; ?>

							</li>
						</ul>
					</div>

				<div>
					<?php if ( !isset( $form['enableFormState'] ) || !$form['enableFormState'] ): ?>
						<div style="margin:50px 0 0 10px;">
							<?php _e( sprintf( 'This form does not have persistent state keeping for users. Enable the feature by <a href="%s">edit this form\'s</a> settings.', 'admin.php?page=gf_edit_forms&id='.$form['id']), self::$textdomain ); ?>
						</div>
					<?php else: ?>
						<h3>Users who have completed this form</h3>
						<ul>
							<?php $users_completed = $this->get_users( $form['id'], true ); ?>
							<?php if ( !sizeof( $users_completed ) ): ?>
								<li>No users have completed this form yet.</li>
							<?php else: foreach ( $users_completed as $user ): ?>
								<li><a href="user-edit.php?user_id=<?php echo $user->ID; ?>"><?php echo $user->display_name; ?></a></li>
							<?php endforeach; endif; ?>
						</ul>
						<hr />
						<h3>Users who have saved this form for later</h3>
						<ul>
							<?php $users_pending = $this->get_users( $form['id'], false ); ?>
							<?php if ( !sizeof( $users_pending ) ): ?>
								<li>No users who have saved this form for later.</li>
							<?php else: foreach ( $users_pending as $user ): ?>
								<li><a href="user-edit.php?user_id=<?php echo $user->ID; ?>"><?php echo $user->display_name; ?></a></li>
							<?php endforeach; endif; ?>
						</ul>
						<hr />
						<a class="gfstate-clear" href="<?php echo wp_nonce_url( add_query_arg( 'clear', true ), 'gfstate-clear' ); ?>">clear all pending entries</a>
						<script type="text/javascript">
							jQuery( 'a.gfstate-clear' ).click( function( e ) {
								if ( !confirm( <?php echo json_encode( __( 'Are you sure you want to do this?', self::$textdomain ) ); ?> ) )
									e.preventDefault();
							} );
						</script>
					<?php endif; ?>
				</div>
				</div> <!-- /wrap -->
			<?php
		}

		public function get_users( $form_id, $completed = true ) {
			global $wpdb;

			if ( !$completed ) {
				$users = (array) get_users( array( 'meta_key' => 'has_pending_form_'.$form_id, 'meta_value' => '0', 'meta_compare' => '>' ) );
				foreach ( $users as &$user ) {
					$user->pending_entry = get_user_meta( $user->ID, 'has_pending_form_'.$form_id, true );
				}
				return $users;
			} else {
				$users = (array) get_users( array( 'meta_key' => 'completed_form_'.$form_id, 'meta_value' => '0', 'meta_compare' => '>' ) );
				foreach ( $users as &$user ) {
					$user->completed_entry = get_user_meta( $user->ID, 'has_pending_form_'.$form_id, true );
				}
				return $users;
			}
		}

		public function cleanup_saved_entries( $form_id, $max = 100 ) {
			/* Removes all entries/leads that are not tied to a user */
			$leads = RGFormsModel::get_leads( $form_id, 0, 'DESC', '', 0, $max, null, null, false, null, null, 'pending' );
			RGFormsModel::delete_leads( $leads );
			foreach ( $this->get_users( $form_id, false ) as $user ) {
				if ( $user->pending_entry ) {
					delete_user_meta( $user->ID, 'has_pending_form_'.$form_id );
				}
			}
		}
	}

	if ( defined( 'WP_CONTENT_DIR' ) ) new GFStatefulForms; /* initialize */
