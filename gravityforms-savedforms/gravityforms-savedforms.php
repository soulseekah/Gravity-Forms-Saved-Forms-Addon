<?php
	/*
		Plugin Name: Gravity Forms Saved Forms Add-On
		Author: Gennady Kovshenin
		Description: Adds state to forms allowing users to save form for later
		Version: 0.5.0.0
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
			add_filter( 'gform_form_settings', array( $this, 'save_form_settings' ), null, 2 );

			/* Form frontend logic */
			add_filter( 'gform_submit_button', array( $this, 'form_add_save_button'), null, 2 );
			add_filter( 'gform_next_button', array( $this, 'form_add_save_button'), null, 2 );
			add_filter( 'gform_form_post_get_meta', array( $this, 'form_save_confirmation' ), null );

			/* Restore logic */
			add_action( 'gform_form_args', array( $this, 'form_restore' ) );

			/* Save logic */
			add_action( 'gform_incomplete_submission_post_save', array( $this, 'form_save' ), null, 4 );
			add_action( 'gform_post_submission', array( $this, 'form_submit' ), null, 2 );

			/* Pending and completed entries */
			add_filter( 'gform_addon_navigation', array( $this, 'add_pending_completed_entries_item' ) );
		}

		public function early_init() {
			/* Load languages if available */
			load_plugin_textdomain( self::$textdomain, false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		}

		public function save_form_settings( $form_settings, $form ) {
			$tooltip = __( 'Saves partially filled forms for logged in users. Being a user is required, enable "Require Login" above.', self::$textdomain );
			ob_start();
			?>
				<tr>
					<th>
						<?php esc_html_e( 'Save forms', self::$textdomain ); ?> <a href='#' onclick='return false;' class='gf_tooltip tooltip tooltip_form_description' title="<?php echo esc_attr( $tooltip ); ?>"><i class='fa fa-question-circle'></i></a>
					</th>
					<td>
						<input type="checkbox" id="gform_enable_form_state" />
						<label for="gform_enable_form_state"><?php esc_html_e( 'Enable form state', self::$textdomain ) ?></label>
					</td>
				</tr>
				<tr id="gform_enable_form_state_on_submit_row" class="child_setting_row" style="display: none;">
					<td colspan="2" class="gf_sub_settings_cell">
						<div class="gf_animate_sub_settings">
							<table>
								<tr>
									<th>
										<?php $tooltip = __( 'After submitting the data will still be retained and will be prefilled when visiting the form again.', self::$textdomain ); ?>
										<?php esc_html_e( 'Save form on submit' );?> <a href='#' onclick='return false;' class='gf_tooltip tooltip tooltip_form_description' title="<?php echo esc_attr( $tooltip ); ?>"><i class='fa fa-question-circle'></i></a>
									</th>
									<td>
										<input type="checkbox" id="gform_enable_form_state_on_submit" />
										<label for="gform_enable_form_state_on_submit"><?php esc_html_e( 'Save form state even after submit', self::$textdomain ) ?></label>
									</td>
								</tr>
							</table>
						</div>
					</td>
				</tr>
			<?php
			$form_settings['Form Options']['saved_forms'] = ob_get_clean();

			return $form_settings;
		}

		public function editor_script() {
			if( rgget('page') != 'gf_edit_forms' )
				return;

			?>
				<script type="text/javascript">
					jQuery( '#gform_enable_form_state' ).attr( 'checked', form.enableFormState ? true : false ).change( function() {
						form.enableFormState = jQuery( '#gform_enable_form_state' ).is( ':checked' );
						if ( form.enableFormState ) ShowSettingRow( '#gform_enable_form_state_on_submit_row' );
						else HideSettingRow( '#gform_enable_form_state_on_submit_row' );
					} ).trigger( 'change' );
					
					jQuery( '#gform_enable_form_state_on_submit' ).attr( 'checked', form.enableFormStateOnSubmit ? true : false ).change( function() {
						form.enableFormStateOnSubmit = jQuery( '#gform_enable_form_state_on_submit' ).is( ':checked' );
					} ).trigger( 'change' );
				</script>
			<?php
		}

		public function form_add_save_button( $button_input, $form ) {
			/* there's nothing to be done if form state or login requirements are off */
			if ( !isset($form['requireLogin']) || !isset($form['enableFormState']) ) return $button_input;
			if ( !$form['requireLogin'] || !$form['enableFormState'] ) return $button_input;

			$tabindex_match = false; /* get the proper tabindex */
			if ( preg_match( "#tabindex\\s*=\\s*[\"'](\\d+)[\"']#", $button_input, $_tabindex_match ) == 1 ) {
				$tabindex_match = intval( $_tabindex_match[1] ) + 1;
			}

			$onclick = esc_attr( str_replace( '%d', $form['id'], 'if(window["gf_submitting_%d"]){return false;}'
				. 'window["gf_submitting_%d"]=true;'
				. 'jQuery("#gform_save_%d").length || jQuery("#gform_%d").append("<input type=\"hidden\" id=\"gform_save_%d\" name=\"gform_save_%d\">");'
				. 'jQuery("#gform_save_%d").val(1); jQuery("#gform_%d").trigger("submit",[true]);'
			) );

			$button_input .= '<input type="submit" id="gform_save_state_'.$form['id'].'" class="gform_save_state button gform_button" name="gform_save" value="'
							.__( "Save for later", self::$textdomain ).'" tabindex="'.$tabindex_match.'" onclick="'.$onclick.'">';
			return $button_input;
		}

		/**
		 * Injects the necessary save confirmation message
		 *
		 * At an early stage when our plugin is active we inject
		 *  the confirmation message. This message cannot be deleted
		 *  or modified within the Forms/Confirmations settings in the
		 *  admin. This will need to be addressed some time later.
		 *
		 * This is done on the `gform_form_post_get_meta` action as at
		 *  the time of writing, the saved forms logic in Gravity Forms
		 *  provides no other hooks to inject a custom confirmation with
		 *  a type of 'form_saved'.
		 *
		 * @param GFForm $form The form
		 *
		 * @return GFForm The modified form
		 */
		public function form_save_confirmation( $form ) {
			if ( !isset( $form['enableFormState'] ) || !$form['enableFormState'] )
				return $form;

			$user = wp_get_current_user();
			if ( !$user )
				return $form;

			if ( is_admin() )
				return $form;

			$form['confirmations']['gform_saved_forms'] = array(
				'id' => 'gform_saved_forms',
				'name' => __( 'Save confirmation', self::$textdomain ),
				'event' => 'form_saved',
				'message' => __( 'Your progress has been saved. You can return to this form anytime in the future to complete it.', self::$textdomain ),
			);
			return $form;
		}

		/**
		 * Do the necessary saved form housekeeping
		 *
		 * Called on the `gform_incomplete_submission_post_save` action.
		 *
		 * Since Gravity Forms doesn't save the user's ID even when forms
		 *  require login, we need to save the user ID ourselves. We could do
		 *  this in the form metadata I guess, but for now we'll do the old
		 *  way of saving in the user metadata instead.
		 *
		 * @param array $submission The current submission
		 * @param string $resume_token The secret resume token
		 * @param GFForm $form The current form
		 * @param array $form The entry
		 *
		 * @return void
		 */
		public function form_save( $submission, $resume_token, $form, $entry ) {
			if ( !isset( $form['enableFormState'] ) || !$form['enableFormState'] )
				return;

			$user = wp_get_current_user();
			if ( !$user )
				return;

			update_user_meta( $user->ID, 'has_pending_form_' . $form['id'], $resume_token );
		}


		/**
		 * Process a submission
		 *
		 * We mark this form as completed for the user. And if "Save on Submit"
		 *  is not turned on we delete the pending data
		 *
		 * @param array The lead
		 * @param GFForm The form
		 *
		 * @return void
		 */
		public function form_submit( $lead, $form ) {
			if ( !isset( $form['enableFormState'] ) || !$form['enableFormState'] )
				return;

			$user = wp_get_current_user();
			if ( !$user )
				return;

			update_user_meta( $user->ID, 'completed_form_' . $form['id'], true );

			/* only delete the data if save on submit option has not been enabled */
			if ( !isset( $form['enableFormStateOnSubmit'] ) || !$form['enableFormStateOnSubmit'] )
				delete_user_meta( $user->ID, 'has_pending_form_' . $form['id'] );
		}

		/**
		 * Handle a saved form restore
		 *
		 * Called on the `gform_form_args` filter
		 *
		 * This is a hack. We basically lie to Gravity Forms
		 *  and make it think that we have a token in the $_GET
		 *  superglobal so that its restore logic kicks in.
		 *
		 * @param array $args The current form arguments
		 *
		 * @return array $args
		 */
		public function form_restore( $args ) {
			if ( empty( $args['form_id'] ) )
				return $args;

			$form = RGFormsModel::get_form_meta( $args['form_id'] );
			if ( !isset( $form['enableFormState'] ) || !$form['enableFormState'] )
				return $args;

			$user = wp_get_current_user();
			if ( !$user )
				return $args;

			if ( !isset( $_POST['gform_submit'] ) )
				$_GET['gf_token'] = get_user_meta( $user->ID, 'has_pending_form_' . $args['form_id'] );

			return $args;
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
	}

	if ( defined( 'WP_CONTENT_DIR' ) ) new GFStatefulForms; /* initialize */
