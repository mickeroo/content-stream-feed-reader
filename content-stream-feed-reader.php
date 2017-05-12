<?php
/*
 * Plugin Name: Content Stream Feed Reader
 * Description: Downloads and parses content from the specified Content Stream feed, creating new posts.
 * Author: Mike Eaton
 * Version: 1.0.1
 */

/**
 * Class ContentStreamFeedReader
 */
class ContentStreamFeedReader {

	/**
	 * The folder uploaded articles will be copied to.
	 *
	 * @var string
	 */
	private $upload_dir;

	/**
	 * Images will be copied here.
	 *
	 * @var string
	 */
	private $local_image_dir;

	/**
	 * List of different frequency types.
	 *
	 * @var array
	 */
	private $cron_freq;

	/**
	 * Content Stream object used to connect to the API.
	 *
	 * @var ContentStream
	 */
	private $stream;

	/**
	 * Singleton instance of this object.
	 *
	 * @var bool
	 */
	private static $instance = false;

	/**
	 * Generates a singleton for this object.
	 *
	 * @return bool|ContentStreamFeedReader
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * ContentStreamFeedReader constructor.
	 */
	private function __construct() {

		$upload                = wp_upload_dir();
		$upload_dir            = $upload['basedir'];
		$this->upload_dir      = $upload_dir . '/content-stream';
		$this->local_image_dir = $this->upload_dir . '/syndicationAssets';

		add_action( 'admin_menu', array( $this, 'create_settings_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'add_js_scripts' ) );

		register_activation_hook( __FILE__, array( $this, 'activation' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivation' ) );

		// Pull in the connection params from the options array if they exist already.
		$options    = get_option( CSFR_OPTIONS );
		$username   = (isset( $options['username'] ) ) ? $options['username'] : '';
		$password   = (isset( $options['password'] ) ) ? $options['password'] : '';
		$feed_id    = (isset( $options['feed_id'] ) ) ? $options['feed_id'] : '';
		$this->stream = new ContentStream( 'https://contentstream.cfemedia.com/api/', $username, $password, $feed_id );

		$this->cron_freq = array(
			'daily' => 'Daily',
			'weekly' => 'Weekly',
			'biweekly' => 'Every Two Weeks',
		);

		add_action( 'cs_schedule_cron_import', array( $this, 'cs_run_cron_import' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );

	}

	/**
	 * Setup when the plugin is activated.
	 */
	public function activation() {

		// Create article and image upload folders within content dir.
		if ( ! is_dir( $this->upload_dir ) ) {
			wp_mkdir_p( $this->upload_dir );
		}

		$imported = $this->upload_dir . DIRECTORY_SEPARATOR . 'imported';
		if ( ! is_dir( $imported ) ) {
			wp_mkdir_p( $imported );
		}

		if ( ! is_dir( $this->local_image_dir ) ) {
			wp_mkdir_p( $this->local_image_dir );
		}
	}

	/**
	 * Cleanup when the plugin is deactivated.
	 */
	public function deactivation() {
		$this->disable_cron();
	}

	/**
	 * Adds some javascript functionality to this admin page.
	 *
	 * @param $hook string The admin page's hook name (from add_options_page)
	 */
	public function add_js_scripts( $hook ) {
		if ( 'settings_page_content-stream' === $hook ) {
			wp_enqueue_script( 'csfr-admin-js', plugin_dir_url( __FILE__ ) . '/js/admin.js', array( 'jquery', 'jquery-ui-core', 'jquery-ui-datepicker' ), '1.0.0', true );
			wp_enqueue_style( 'jquery-ui-datepicker' , '//ajax.googleapis.com/ajax/libs/jqueryui/1.10.4/themes/smoothness/jquery-ui.css' );
		}
	}

	/**
	 * Adds the settings page to the admin menu.
	 */
	public function create_settings_page() {
		add_options_page( 'Content Stream Settings', 'Content Stream', 'manage_options', 'content-stream', array( $this, 'render_settings_form' ) );
	}

	/**
	 * Generate the admin settings form.
	 *
	 * Ideally we would have an admin page for displaying available articles and running the import manually but since
	 * this is supposed to be primarily an automatic process, I added the import buttons to this page as backups to
	 * keep the UI simple. Creating a separate import page would allow this page to be simpler and we could clean
	 * up code that handles the multiple post types.
	 */
	public function render_settings_form() {

		if ( isset( $_POST['updated'] ) && 'true' === $_POST['updated'] ) {
			$this->process_form();
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'You do not have sufficient permissions to access this page.' ) );
		}

		// Are there articles on ContentStream to download?
		/** Response object from ContentStream. @var getContentListResponse */
		$content_list = $this->stream->get_content_list();

		if ( 1 === $content_list->errorOccurred )  {
			if ( 'Unauthorized Access' == $content_list->errorDescription ) {
				echo '<div class="error">
					<p>Please check the username, password, and feed ID fields. There was an authentication error connecting to the Content Stream service.</p>
				</div>';
			} else {
				echo '<div class="error">
					<p>There was an error connecting to the Content Stream service: ' . $content_list->errorDescription . '</p>
				</div>';
			}
		}

		// Are there local articles to import?
		$articles = $this->get_articles_list();

		$options = get_option( CSFR_OPTIONS );
		if ( 'draft' === $options['post_status'] || '' === $options['post_status'] ) {
			$is_draft = 'checked';
			$is_published = '';
		} else {
			$is_draft = '';
			$is_published = 'checked';
		}

		$next_cron = wp_next_scheduled( 'cs_schedule_cron_import' );
		$cron_info = ( false !== $next_cron )
			 ? '<p>Next scheduled import: ' . date( 'l, M j, Y', $next_cron ) . '</p>'
			 : '<p>No import scheduled.</p>';

		// Mark the cron inputs as disabled if the checkbox is not checked.
		$checked = ( '1' === esc_html( $options['cron_enabled'] ) ) ? 'checked' : '';
		$disabled = ( $checked ) ? '' : 'disabled';

		// If there are remote or local items available to be imported, show the import buttons.
		$manual_import = ( count( $articles ) > 0 || $content_list->totalNumberInQueue > 0 ) ? true : false;
		?>
		<div class="wrap">
			<h1>Content Stream Feed Reader</h1>
			<form method="POST">
				<input type="hidden" name="updated" value="true" />
				<?php wp_nonce_field( 'csfr', 'csfr_form' ); ?>
				<p>Enter the ContentStream username, password, and ID for the feed you want to read.</p>
				<h2>Feed Settings</h2>
				<table class="form-table">
					<tbody>
					<tr>
						<th><label for="cs_username">Username</label></th>
						<td><input name="cs_username" id="cs_username" type="text" value="<?php echo esc_html( $options['username'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="cs_password">Password</label></th>
						<td><input name="cs_password" id="cs_password" type="text" value="<?php echo esc_html( $options['password'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="cs_feed_id">Feed ID</label></th>
						<td><input name="cs_feed_id" id="cs_feed_id" type="text" value="<?php echo esc_html( $options['feed_id'] ); ?>" class="regular-text" /></td>
					</tr>
					</tbody>
				</table>
				<h2>Post Settings</h2>
				<p>Set the author and status (draft or publish) the articles will be imported with.</p>
				<table class="form-table">
					<tbody>
					<tØr>
						<th><label for="cs_post_as">Post As</label></th>
						<td><?php wp_dropdown_users( array(
							'id' => 'cs_post_as',
							'name' => 'cs_post_as',
							'selected' => $options['post_as'],
						) ); ?>
						</td>
					</tØr>
					<tr>
						<th><label for="cs_post_category">Category</label></th>
						<td><?php wp_dropdown_categories( array(
								'id' => 'cs_post_category',
								'name' => 'cs_post_category',
								'selected' => $options['post_category'],
								'hide_empty' => false,
						) ); ?>
						</td>
					</tr>
					<tr>
						<th>Post Status</th>
						<td><input name="cs_post_status" id="cs_post_status_draft" type="radio" value="draft" <?php echo esc_html( $is_draft ); ?> /> <label for="cs_post_status_draft">Draft</label><br />
							<input name="cs_post_status" id="cs_post_status_publish" type="radio" value="publish" <?php echo esc_html( $is_published ); ?> /> <label for="cs_post_status_publish">Publish</label></td>
					</tr>
					</tbody>
				</table>
				<h2>Scheduled Import</h2>
				<p>When enabled by checking the box below and supplying a start date and frequency, import will take place automatically.</p>
				<?php echo $cron_info; ?>
				<table class="form-table">
					<tbody>
					<tr>
						<th><label for="cron_enabled">Enable Scheduled Import</label></th>
						<td><input name="cron_enabled" id="cron_enabled" type="checkbox" <?php echo esc_html( $checked ); ?>  /></td>
					</tr>
					<tr>
						<th><label for="cs_cron_start">Start Date</label></th>
						<td><input id="cs_cron_start" type="text" name="cs_cron_start"
							value="<?php echo esc_html( $options['cron_start'] ); ?>" class="regular-text"
							<?php echo esc_html( $disabled ); ?> /></td>
					</tr>
					<tr>
						<th><label for="cs_cron_freq">Frequency</label></th>
						<td><select name="cs_cron_freq" id="cs_cron_freq" <?php echo esc_html( $disabled ); ?>>
							<?php
							foreach ( $this->cron_freq as $key => $value ) {
								$selected = ( $options['cron_freq'] === $key ) ? 'selected' : '';
								?><option value="<?php echo esc_html( $key ); ?>" <?php echo esc_html( $selected ); ?>><?php echo esc_html( $value ); ?></option><?php
							} ?>
						</select>
						</td>
					</tr>
					</tbody>
				</table>
				<p class="submit">
					<input type="submit" name="settings-submit" id="settings-submit" class="button button-primary" value="Save Settings">
				</p>
				<?php if ( $manual_import ) { ?>
				<br/>
				<h2>Manual Import</h2>
				<p class="submit">
					<?php if ( $content_list->totalNumberInQueue > 0 ) { ?>
					<input type="submit" name="import-articles" id="import-articles" class="button button-primary"
						   value="Download and import <?php echo esc_html( $content_list->totalNumberInQueue ) . ' article(s)'; ?>"> &nbsp;
					<?php } ?>
					<?php if ( count( $articles ) > 0 ) { ?>
					<input type="submit" name="import-local" id="import-local" class="button button-primary"
						   value="Import <?php echo count( $articles ); ?> article(s)">
				<?php } ?>
				</p>
				<?php } ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Processes settings form contents.
	 */
	public function process_form() {

		if ( ! isset( $_POST['csfr_form'] ) || ! wp_verify_nonce( $_POST['csfr_form'], 'csfr' ) ) { ?>
			<div class="error">
				<p>There was an error processing this form. Please try again.</p>
			</div> <?php
			exit;
		} else {
			if ( isset( $_POST['settings-submit'] ) ) {
				$this->save_settings();
			} elseif ( isset( $_POST['import-articles'] ) ) {
				$this->import_articles();
			} elseif ( isset( $_POST['import-local'] ) ) {
				$articles = $this->get_articles_list();
				$this->import_local_articles( $articles );
			}
		}
	}

	/**
	 * Gets a list of xml files that have been downloaded from ContentStream
	 *
	 * @return array list of xml files
	 */
	private function get_articles_list() {

		$articles = array();
		$dir = new DirectoryIterator( $this->upload_dir );
		foreach ( $dir as $file_info ) {
			if ( ! $file_info->isDot() && 'xml' === $file_info->getExtension() ) {
				$articles[] = $file_info->getPathname();
			}
		}
		return $articles;
	}

	/**
	 * Creates WP 'Blog' posts for each of the xml files in the list.
	 *
	 * @param array $articles List of xml files.
	 */
	private function import_local_articles( $articles ) {

		$article_count = 0;
		foreach ( $articles as $article ) {
			$xml = simplexml_load_file( $article );

			if ( false === $xml ) {
				error_log( __FUNCTION__ . ' : Error loading XML file: ' . $article );
			} else {
				$post_id = $this->create_post( $xml );

				if ( ! is_wp_error( $post_id ) ) {
					$path = pathinfo( $article );
					$destination = $path['dirname'] . '/imported/';
					rename( $article, $destination . $path['basename'] );
					$article_count++;
				} else {
					error_log( __FUNCTION__ . ' : Error creating post: ' . $post_id->get_error_message() );
				}
			}
		}
		?><div class="updated"><p><?php echo esc_html( $article_count ); ?> article(s) have been imported.</p></div><?php
	}

	/**
	 * Downloads the articles from ContentStream then run the import process.
	 */
	private function import_articles() {

		$content_list = $this->stream->get_content_list();
		$this->stream->download_content( $content_list, $this->upload_dir, $this->local_image_dir, true );

		$articles = $this->get_articles_list();
		$this->import_local_articles( $articles );
	}

	/**
	 * Updates settings for this plugin.
	 */
	private function save_settings() {

		$options = get_option( CSFR_OPTIONS );

		// Sanitize the data coming in from the form.
		$username = sanitize_email( $_POST['cs_username'] );
		$password = sanitize_text_field( $_POST['cs_password'] );
		$feed_id = sanitize_text_field( $_POST['cs_feed_id'] );
		$status = ( 'publish' === $_POST['cs_post_status'] || 'draft' === $_POST['cs_post_status'] )
			? sanitize_text_field( $_POST['cs_post_status'] )
			: 'draft';
		$post_as_user = sanitize_text_field( $_POST['cs_post_as'] );
		$category = sanitize_text_field( $_POST['cs_post_category'] );

		// If either the cron start date or frequency change, we will need to update the cron.
		$cron_start = ( isset( $_POST['cs_cron_start'] ) )
			? sanitize_text_field( $_POST['cs_cron_start'] ) : '';
		$cron_start_changed = ( $options['cron_start'] !== $cron_start );

		$cron_freq = ( isset( $_POST['cs_cron_freq'] ) && array_key_exists( $_POST['cs_cron_freq'], $this->cron_freq ) )
			? sanitize_text_field( $_POST['cs_cron_freq'] ) : 'daily';
		$cron_freq_changed = ( $options['cron_freq'] !== $cron_freq );

		$cron_enabled = ( isset( $_POST['cron_enabled'] ) ) ? 1 : 0;
		$cron_status_changed = ( intval( $options['cron_enabled'] ) !== $cron_enabled);

		// Field validation
		$errors = '';
		if ( '' === $username || '' === $password || '' === $feed_id ) {
			$errors .= '<p>Username, password, and feed ID are required.</p>';
		}
		if ( $cron_enabled && empty( $cron_start ) ) {
			$errors .= '<p>A start date is required when Scheduled Import is enabled.</p>';
		}

		if ( '' === $errors ) {

			$options = array(
				'username' => $username,
				'password' => $password,
				'feed_id' => $feed_id,
				'post_status' => $status,
				'post_as' => $post_as_user,
				'post_category' => $category,
				'cron_start' => $cron_start,
				'cron_freq' => $cron_freq,
				'cron_enabled' => $cron_enabled,
			);
			update_option( CSFR_OPTIONS, $options );

			// Refresh the API connection settings.
			$this->stream->username = $username;
			$this->stream->password = $password;
			$this->stream->feed_id = $feed_id;

			if ( $cron_status_changed ) {
				if ( 1 === $cron_enabled ) {
					$this->enable_cron( $cron_start, $cron_freq, true );
				} else {
					$this->disable_cron( true );
				}
			} else {
				if ( $cron_freq_changed || $cron_start_changed ) {
					// "Update" an existing scheduled import.
					$this->disable_cron( );
					$this->enable_cron( $cron_start, $cron_freq );
				}
			}
			?>
			<div class="updated">
				<p>Settings have been saved.</p>
			</div> <?php
		} else { ?>
			<div class="error">
				<?php echo esc_html( $errors ); ?>
			</div> <?php
		} // End if().
	}

	/**
	 * Creates a WP cron task based on the start and frequency supplied by the user.
	 *
	 * @param string $cron_start Starting date of the recurring task.
	 * @param string $cron_freq How often the task will occur.
	 * @param bool   $show_message Displays the confirmation message if true.
	 */
	private function enable_cron( $cron_start, $cron_freq, $show_message = false ) {

		if ( ! wp_next_scheduled( 'cs_schedule_cron_import' ) ) {
			wp_schedule_event( strtotime( $cron_start ), $cron_freq, 'cs_schedule_cron_import' );
		}
		if ( $show_message ) {
			echo '<div class="updated"><p>Scheduled import has been enabled.</p></div>';
		}
	}

	/**
	 * Removes an existing WP Cron task.
	 *
	 * @param bool $show_mssage Displays the confirmation message if true.
	 */
	private function disable_cron( $show_mssage = false ) {

		$timestamp = wp_next_scheduled( 'cs_schedule_cron_import' );
		wp_unschedule_event( $timestamp, 'cs_schedule_cron_import' );
		if ( $show_mssage ) {
			echo '<div class="updated"><p>Scheduled import has been disabled.</p></div>';
		}
	}

	/**
	 * Executes callback for scheduled import WP Cron task.
	 */
	public function cs_run_cron_import() {
		// Run the download and import process.
		$this->import_articles();
	}

	/**
	 * Adds weekly and biweekly intervals to the WP Cron.
	 *
	 * @param array $schedules The current WP cron schedules.
	 * @return mixed The updated schedules array.
	 */
	public function add_cron_intervals( $schedules ) {
		$schedules['weekly'] = array(
			'interval' => 7 * 24 * 60 * 60,
			'display'  => esc_html__( 'Every Week' ),
		);

		$schedules['biweekly'] = array(
			'interval' => 2 * 7 * 24 * 60 * 60,
			'display'  => esc_html__( 'Every Two Weeks' ),
		);
		return $schedules;
	}

	/**
	 * Takes the supplied XML file and creates a new post with it.
	 *
	 * @param SimpleXMLElement $xml The XML file containing the post content.
	 * @return int|null|WP_Error
	 */
	private function create_post( $xml ) {

		$upload_dir = wp_upload_dir();
		$local_image_url = $upload_dir['baseurl'] . '/content-stream/syndicationAssets/';

		$title = (string) $xml->title;

		$subheader = ( isset( $xml->subheader[0] ) && ! empty( $xml->subheader[0] ) )
			? '<h2>' . (string) $xml->subheader[0] . '</h2>' : '';
		$abstract = ( isset( $xml->abstract[0] ) && ! empty( $xml->abstract[0] ) )
			? '<p><span class="stream-meta">Abstract:</span> ' . (string) $xml->abstract[0] . '</p>' : '';
		$body_text = str_replace( 'syndicationAssets/', $local_image_url, (string) $xml->bodytext[0] );

		$copyright = ( isset( $xml->copyright[0] ) && ! empty( $xml->copyright[0] ) )
			? '<p><span class="stream-meta">Copyright:</span> ' . (string) $xml->copyright[0] . '</p>' : '';

		$author = ( isset( $xml->author[0] ) && ! empty( $xml->author[0] ) )
			? '<p><span class="stream-meta">Author:</span> ' . (string) $xml->author[0] . '</p>' : '';

		// Iterate over the keywords and map to post tags.
		$terms = array();
		if ( isset( $xml->keywords[0] ) && ! empty( $xml->keywords[0] ) ) {
			$keywords = explode( ',', $xml->keywords[0] );
			// Does the tag exist? Add if it does not.
			foreach ( $keywords as $keyword ) {
				$keyword = trim( $keyword );
				$term_name = '';
				$term = get_term_by( 'name', $keyword, 'post_tag' );
				if ( false === $term ) {
					$term = wp_insert_term( $keyword, 'post_tag', array(
					    'slug' => sanitize_title( $keyword ),
					) );
					if ( ! is_wp_error( $term ) ) {
						$term_name = $keyword;
					}
				} else {
					$term_name = $term->name;
				}
				if ( ! empty( $term_name ) ) {
					$terms[] = $term_name;
				}
			}
		}

		// Arrangement of the content going into the post. Would be good to make this into a template.
		$content = $subheader .
			$abstract .
			$author .
			$body_text .
			$copyright;
		$options = get_option( CSFR_OPTIONS );
		$slug = sanitize_title( $title );
		$post_author = intval( sanitize_text_field( $options['post_as'] ) );
		$post_status = sanitize_text_field( $options['post_status'] );
		$category = sanitize_text_field( $options['post_category'] );
		$post = array(
			'comment_status'  => 'closed',
			'ping_status'   => 'closed',
			'post_author'   => $post_author,
			'post_category' => array( $category ),
			'post_name'   => $slug,
			'post_title'    => $title,
			'post_status'   => $post_status,
			'post_type'   => 'post',
			'post_content' => $content,
		);
		if ( count( $terms ) > 0 ) {
			$post['tags_input'] = $terms;
		}

		// $post_id is WP_ERROR on fail or null if the article exists
		$existing_post = get_page_by_title( $title, OBJECT, 'post' );
		if ( null === $existing_post || 'trash' === $existing_post->post_status ) {
			$post_id = wp_insert_post( $post, true );
		} else {
			error_log( 'Post ' . $existing_post->ID . ' already exists with the title "' . $title . '". Post not created.' );
			$post_id = null;
		}
		return $post_id;
	}
}
define( 'CSFR_ROOT_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSFR_CLASSES_DIR', CSFR_ROOT_DIR . '/classes/' );
define( 'CSFR_OPTIONS', 'csfr_options' );
define( 'CSFR_VERSION', '1.0.1' );

require_once CSFR_CLASSES_DIR . 'class-contentstream.php';

ContentStreamFeedReader::get_instance();
