<?php
/**
 * Plugin Name: Polylang OpenAI Translator
 * Description: Translate posts and pages with OpenAI, then create or update linked Polylang translations.
 * Version: 0.1.20
 * Author: Codex
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: polylang-openai-translator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class POT_Polylang_OpenAI_Translator {
	private const OPTION_KEY = 'pot_openai_translator_options';
	private const NONCE_ACTION = 'pot_translate_post';

	public static function boot(): void {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_dependency_notice' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
		add_action( 'wp_ajax_pot_translate_post', array( __CLASS__, 'ajax_translate_post' ) );
		add_action( 'wp_ajax_pot_translate_job_step', array( __CLASS__, 'ajax_translate_job_step' ) );
		add_action( 'wp_ajax_pot_publish_translations', array( __CLASS__, 'ajax_publish_translations' ) );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'maybe_show_all_media_in_editors' ), 999 );
	}

	public static function register_settings(): void {
		register_setting(
			'pot_openai_translator',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_options' ),
				'default'           => self::default_options(),
			)
		);
	}

	public static function register_settings_page(): void {
		add_options_page(
			__( 'Polylang OpenAI Translator', 'polylang-openai-translator' ),
			__( 'Polylang OpenAI Translator', 'polylang-openai-translator' ),
			'manage_options',
			'pot-openai-translator',
			array( __CLASS__, 'render_settings_page' )
		);

		add_management_page(
			__( 'OpenAI Batch Translator', 'polylang-openai-translator' ),
			__( 'OpenAI Batch Translator', 'polylang-openai-translator' ),
			'edit_pages',
			'pot-openai-batch-translator',
			array( __CLASS__, 'render_batch_page' )
		);
	}

	public static function maybe_show_dependency_notice(): void {
		if ( self::has_polylang() ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Polylang OpenAI Translator requires Polylang to be installed and activated.', 'polylang-openai-translator' );
		echo '</p></div>';
	}

	public static function register_meta_box(): void {
		if ( ! self::has_polylang() ) {
			return;
		}

		$post_types = self::get_supported_post_types();
		foreach ( $post_types as $post_type ) {
			if ( 'gp_elements' !== $post_type && function_exists( 'pll_is_translated_post_type' ) && ! pll_is_translated_post_type( $post_type ) ) {
				continue;
			}

			add_meta_box(
				'pot-openai-translator',
				__( 'OpenAI Translation', 'polylang-openai-translator' ),
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	private static function get_supported_post_types(): array {
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		if ( post_type_exists( 'gp_elements' ) ) {
			$post_types['gp_elements'] = 'gp_elements';
		}

		return array_values( array_unique( $post_types ) );
	}

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = self::get_options();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Polylang OpenAI Translator', 'polylang-openai-translator' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'pot_openai_translator' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pot-api-key"><?php esc_html_e( 'OpenAI API Key', 'polylang-openai-translator' ); ?></label></th>
						<td>
							<input id="pot-api-key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]" type="password" class="regular-text" value="<?php echo esc_attr( $options['api_key'] ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Stored on this WordPress site and used only for server-side requests.', 'polylang-openai-translator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pot-model"><?php esc_html_e( 'Model', 'polylang-openai-translator' ); ?></label></th>
						<td>
							<input id="pot-model" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[model]" type="text" class="regular-text" value="<?php echo esc_attr( $options['model'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Use the model name required by your API provider.', 'polylang-openai-translator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pot-api-endpoint"><?php esc_html_e( 'API Endpoint', 'polylang-openai-translator' ); ?></label></th>
						<td>
							<input id="pot-api-endpoint" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_endpoint]" type="url" class="regular-text" value="<?php echo esc_attr( $options['api_endpoint'] ); ?>" />
							<p class="description"><?php esc_html_e( 'For OpenAI-compatible providers, enter the full endpoint URL, for example https://example.com/v1/chat/completions.', 'polylang-openai-translator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pot-api-format"><?php esc_html_e( 'API Format', 'polylang-openai-translator' ); ?></label></th>
						<td>
							<select id="pot-api-format" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_format]">
								<option value="responses" <?php selected( $options['api_format'], 'responses' ); ?>><?php esc_html_e( 'Responses API (/v1/responses)', 'polylang-openai-translator' ); ?></option>
								<option value="chat_completions" <?php selected( $options['api_format'], 'chat_completions' ); ?>><?php esc_html_e( 'Chat Completions (/v1/chat/completions)', 'polylang-openai-translator' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Most OpenAI-compatible services support Chat Completions.', 'polylang-openai-translator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pot-max-output-tokens"><?php esc_html_e( 'Max output tokens', 'polylang-openai-translator' ); ?></label></th>
						<td>
							<input id="pot-max-output-tokens" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_output_tokens]" type="number" min="1000" max="50000" step="500" value="<?php echo esc_attr( (string) $options['max_output_tokens'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pot-request-timeout"><?php esc_html_e( 'Request timeout', 'polylang-openai-translator' ); ?></label></th>
						<td>
							<input id="pot-request-timeout" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[request_timeout]" type="number" min="30" max="900" step="30" value="<?php echo esc_attr( (string) $options['request_timeout'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Seconds to wait for each API request. Use a larger value for slow compatible API providers or long Elementor pages.', 'polylang-openai-translator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Media metadata', 'polylang-openai-translator' ); ?></th>
						<td>
							<label for="pot-translate-media">
								<input id="pot-translate-media" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[translate_media]" type="checkbox" value="1" <?php checked( ! empty( $options['translate_media'] ) ); ?> />
								<?php esc_html_e( 'Translate referenced media metadata and create Polylang media translations', 'polylang-openai-translator' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Leave off unless you specifically need translated media title, alt text, caption, and description. Turning this on may affect how Polylang filters media in editors.', 'polylang-openai-translator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Editor media library', 'polylang-openai-translator' ); ?></th>
						<td>
							<label for="pot-show-all-media">
								<input id="pot-show-all-media" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[show_all_media_in_editors]" type="checkbox" value="1" <?php checked( ! empty( $options['show_all_media_in_editors'] ) ); ?> />
								<?php esc_html_e( 'Show all language media in editor image pickers', 'polylang-openai-translator' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Recommended when Polylang Media is enabled and Elementor or WordPress image pickers only show a few images.', 'polylang-openai-translator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pot-custom-instructions"><?php esc_html_e( 'Translation instructions', 'polylang-openai-translator' ); ?></label></th>
						<td>
							<textarea id="pot-custom-instructions" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[custom_instructions]" rows="6" class="large-text"><?php echo esc_textarea( $options['custom_instructions'] ); ?></textarea>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function render_batch_page(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}

		if ( ! self::has_polylang() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'OpenAI Batch Translator', 'polylang-openai-translator' ) . '</h1><p>' . esc_html__( 'Polylang is not active.', 'polylang-openai-translator' ) . '</p></div>';
			return;
		}

		$batch_post_types = array( 'page' );
		if ( post_type_exists( 'gp_elements' ) ) {
			$batch_post_types[] = 'gp_elements';
		}

		$pages = get_posts(
			array(
				'post_type'      => $batch_post_types,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$slugs = pll_languages_list( array( 'fields' => 'slug' ) );
		$names = pll_languages_list( array( 'fields' => 'name' ) );
		$items = array();

		foreach ( $pages as $page ) {
			if ( ! current_user_can( 'edit_post', $page->ID ) ) {
				continue;
			}

			$current_lang = pll_get_post_language( $page->ID, 'slug' );
			if ( empty( $current_lang ) && function_exists( 'pll_default_language' ) ) {
				$current_lang = pll_default_language( 'slug' );
			}

			$targets = array();
			foreach ( $slugs as $index => $slug ) {
				if ( $slug === $current_lang ) {
					continue;
				}
				$targets[] = array(
					'slug'  => $slug,
					'label' => $names[ $index ] ?? $slug,
				);
			}

			$post_type_object = get_post_type_object( $page->post_type );
			$items[] = array(
				'id'      => $page->ID,
				'title'   => get_the_title( $page ),
				'type'    => $post_type_object ? $post_type_object->labels->singular_name : $page->post_type,
				'lang'    => $current_lang,
				'nonce'   => wp_create_nonce( self::NONCE_ACTION . '_' . $page->ID ),
				'targets' => $targets,
			);
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OpenAI Batch Translator', 'polylang-openai-translator' ); ?></h1>
			<p><?php esc_html_e( 'Select pages, translate each one to all other Polylang languages, then publish linked translations automatically.', 'polylang-openai-translator' ); ?></p>
			<p>
				<button type="button" class="button" id="pot-batch-select-all"><?php esc_html_e( 'Select all', 'polylang-openai-translator' ); ?></button>
				<button type="button" class="button" id="pot-batch-clear"><?php esc_html_e( 'Clear', 'polylang-openai-translator' ); ?></button>
				<button type="button" class="button button-primary" id="pot-batch-start"><?php esc_html_e( 'Translate selected and publish', 'polylang-openai-translator' ); ?></button>
			</p>
			<div id="pot-batch-status" style="margin:12px 0; min-height:24px;"></div>
			<table class="widefat striped">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" id="pot-batch-toggle" /></td>
						<th><?php esc_html_e( 'Page', 'polylang-openai-translator' ); ?></th>
						<th><?php esc_html_e( 'Type', 'polylang-openai-translator' ); ?></th>
						<th><?php esc_html_e( 'Source language', 'polylang-openai-translator' ); ?></th>
						<th><?php esc_html_e( 'Target languages', 'polylang-openai-translator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<tr>
							<th class="check-column">
								<input type="checkbox" class="pot-batch-page" value="<?php echo esc_attr( (string) $item['id'] ); ?>" />
							</th>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
							</td>
							<td><?php echo esc_html( $item['type'] ); ?></td>
							<td><?php echo esc_html( $item['lang'] ?: '-' ); ?></td>
							<td><?php echo esc_html( implode( ', ', wp_list_pluck( $item['targets'], 'label' ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<script>
			(function () {
				const pages = <?php echo wp_json_encode( $items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
				const byId = new Map(pages.map(function (page) { return [String(page.id), page]; }));
				const status = document.getElementById('pot-batch-status');
				const start = document.getElementById('pot-batch-start');
				const selectAll = document.getElementById('pot-batch-select-all');
				const clear = document.getElementById('pot-batch-clear');
				const toggle = document.getElementById('pot-batch-toggle');

				function boxes() {
					return Array.from(document.querySelectorAll('.pot-batch-page'));
				}

				function setChecked(value) {
					boxes().forEach(function (box) { box.checked = value; });
					if (toggle) {
						toggle.checked = value;
					}
				}

				if (selectAll) {
					selectAll.addEventListener('click', function () { setChecked(true); });
				}
				if (clear) {
					clear.addEventListener('click', function () { setChecked(false); });
				}
				if (toggle) {
					toggle.addEventListener('change', function () { setChecked(toggle.checked); });
				}

				start.addEventListener('click', async function () {
					const selected = boxes().filter(function (box) { return box.checked; }).map(function (box) { return byId.get(String(box.value)); }).filter(Boolean);
					if (!selected.length) {
						status.textContent = '<?php echo esc_js( __( 'Select at least one page.', 'polylang-openai-translator' ) ); ?>';
						return;
					}

					start.disabled = true;
					let translatedLanguages = 0;
					try {
						for (let pageIndex = 0; pageIndex < selected.length; pageIndex++) {
							const page = selected[pageIndex];
							for (let langIndex = 0; langIndex < page.targets.length; langIndex++) {
								const target = page.targets[langIndex];
								status.textContent = 'Page ' + (pageIndex + 1) + '/' + selected.length + ': ' + page.title + ' -> ' + target.label;
								await translatePageLanguage(page, target);
								translatedLanguages++;
							}
							status.textContent = 'Publishing translations for: ' + page.title;
							await publishPageTranslations(page);
						}
						status.textContent = 'Done. Translated ' + translatedLanguages + ' page-language jobs and published linked translations.';
					} catch (error) {
						status.textContent = error.message + ' Completed page-language jobs: ' + translatedLanguages;
					} finally {
						start.disabled = false;
					}
				});

				async function translatePageLanguage(page, target) {
					const payload = await postAjax({
						action: 'pot_translate_post',
						nonce: page.nonce,
						post_id: page.id,
						target_lang: target.slug
					});

					if (payload.data && payload.data.job_id) {
						await runJob(page, payload.data.job_id);
					}
				}

				async function runJob(page, jobId) {
					let payload;
					do {
						payload = await postAjax({
							action: 'pot_translate_job_step',
							nonce: page.nonce,
							job_id: jobId
						});
						if (payload.data && payload.data.message) {
							status.textContent = page.title + ': ' + payload.data.message;
						}
					} while (payload.data && !payload.data.done);
				}

				async function publishPageTranslations(page) {
					await postAjax({
						action: 'pot_publish_translations',
						nonce: page.nonce,
						post_id: page.id
					});
				}

				async function postAjax(values) {
					const body = new URLSearchParams();
					Object.keys(values).forEach(function (key) {
						body.set(key, values[key]);
					});

					const response = await fetch(ajaxurl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
						body: body.toString()
					});
					const raw = await response.text();
					let payload;
					try {
						payload = JSON.parse(raw);
					} catch (error) {
						throw new Error('Server returned non-JSON response (' + response.status + '): ' + raw.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300));
					}
					if (!payload.success) {
						throw new Error(payload.data && payload.data.message ? payload.data.message : 'Request failed.');
					}
					return payload;
				}
			})();
		</script>
		<?php
	}

	public static function render_meta_box( WP_Post $post ): void {
		if ( ! self::has_polylang() ) {
			echo esc_html__( 'Polylang is not active.', 'polylang-openai-translator' );
			return;
		}

		$options = self::get_options();
		if ( empty( $options['api_key'] ) ) {
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'Add your OpenAI API key before translating.', 'polylang-openai-translator' ),
				esc_url( admin_url( 'options-general.php?page=pot-openai-translator' ) ),
				esc_html__( 'Open settings', 'polylang-openai-translator' )
			);
			return;
		}

		$current_lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post->ID, 'slug' ) : '';
		$names        = function_exists( 'pll_languages_list' ) ? pll_languages_list( array( 'fields' => 'name' ) ) : array();
		$slugs        = function_exists( 'pll_languages_list' ) ? pll_languages_list( array( 'fields' => 'slug' ) ) : array();
		$translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $post->ID ) : array();
		$nonce        = wp_create_nonce( self::NONCE_ACTION . '_' . $post->ID );

		if ( empty( $slugs ) ) {
			echo '<p>' . esc_html__( 'No Polylang languages found.', 'polylang-openai-translator' ) . '</p>';
			return;
		}
		?>
		<p>
			<label for="pot-target-lang"><?php esc_html_e( 'Target language', 'polylang-openai-translator' ); ?></label>
			<select id="pot-target-lang" class="widefat">
				<?php foreach ( $slugs as $index => $slug ) : ?>
					<?php if ( $slug === $current_lang ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<option value="<?php echo esc_attr( $slug ); ?>">
						<?php
						$name   = $names[ $index ] ?? $slug;
						$status = ! empty( $translations[ $slug ] ) ? __( 'update', 'polylang-openai-translator' ) : __( 'create', 'polylang-openai-translator' );
						echo esc_html( sprintf( '%s (%s)', $name, $status ) );
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<button type="button" class="button button-primary widefat" id="pot-translate-button">
				<?php esc_html_e( 'Translate with OpenAI', 'polylang-openai-translator' ); ?>
			</button>
		</p>
		<p>
			<button type="button" class="button widefat" id="pot-translate-all-button">
				<?php esc_html_e( 'Translate all languages', 'polylang-openai-translator' ); ?>
			</button>
		</p>
		<p>
			<button type="button" class="button widefat" id="pot-publish-translations-button">
				<?php esc_html_e( 'Publish all translations', 'polylang-openai-translator' ); ?>
			</button>
		</p>
		<p id="pot-translate-status" style="min-height:20px;"></p>
		<script>
			(function () {
				const button = document.getElementById('pot-translate-button');
				const allButton = document.getElementById('pot-translate-all-button');
				const publishButton = document.getElementById('pot-publish-translations-button');
				const target = document.getElementById('pot-target-lang');
				const status = document.getElementById('pot-translate-status');
				if (!button || !target || !status) {
					return;
				}

				button.addEventListener('click', async function () {
					button.disabled = true;
					if (allButton) {
						allButton.disabled = true;
					}
					if (publishButton) {
						publishButton.disabled = true;
					}
					status.textContent = '<?php echo esc_js( __( 'Translating...', 'polylang-openai-translator' ) ); ?>';

					try {
						const payload = await translateLanguage(target.value);
						status.innerHTML = '<a href="' + payload.data.edit_url + '">' + payload.data.message + '</a>';
					} catch (error) {
						status.textContent = error.message;
					} finally {
						button.disabled = false;
						if (allButton) {
							allButton.disabled = false;
						}
						if (publishButton) {
							publishButton.disabled = false;
						}
					}
				});

				if (allButton) {
					allButton.addEventListener('click', async function () {
						const options = Array.from(target.options).map(function (option) {
							return {
								slug: option.value,
								label: option.textContent.trim()
							};
						});

						if (!options.length) {
							status.textContent = '<?php echo esc_js( __( 'No target languages found.', 'polylang-openai-translator' ) ); ?>';
							return;
						}

						button.disabled = true;
						allButton.disabled = true;
						if (publishButton) {
							publishButton.disabled = true;
						}
						const completed = [];

						try {
							for (let index = 0; index < options.length; index++) {
								const item = options[index];
								status.textContent = '<?php echo esc_js( __( 'Translating', 'polylang-openai-translator' ) ); ?> ' + (index + 1) + '/' + options.length + ': ' + item.label;
								const payload = await translateLanguageWithRetry(item.slug, item.label, index + 1, options.length);
								completed.push('<a href="' + payload.data.edit_url + '">' + item.label + '</a>');
							}

							status.innerHTML = '<?php echo esc_js( __( 'All translations are ready:', 'polylang-openai-translator' ) ); ?> ' + completed.join(', ');
						} catch (error) {
							status.textContent = error.message + (completed.length ? ' <?php echo esc_js( __( 'Completed before error:', 'polylang-openai-translator' ) ); ?> ' + completed.length + '/' + options.length : '');
						} finally {
							button.disabled = false;
							allButton.disabled = false;
							if (publishButton) {
								publishButton.disabled = false;
							}
						}
					});
				}

				if (publishButton) {
					publishButton.addEventListener('click', async function () {
						button.disabled = true;
						if (allButton) {
							allButton.disabled = true;
						}
						publishButton.disabled = true;
						status.textContent = '<?php echo esc_js( __( 'Publishing translations...', 'polylang-openai-translator' ) ); ?>';

						try {
							const payload = await publishTranslations();
							status.textContent = payload.data.message;
						} catch (error) {
							status.textContent = error.message;
						} finally {
							button.disabled = false;
							if (allButton) {
								allButton.disabled = false;
							}
							publishButton.disabled = false;
						}
					});
				}

				async function translateLanguage(language) {
					const body = new URLSearchParams();
					body.set('action', 'pot_translate_post');
					body.set('nonce', '<?php echo esc_js( $nonce ); ?>');
					body.set('post_id', '<?php echo esc_js( (string) $post->ID ); ?>');
					body.set('target_lang', language);

					const response = await fetch(ajaxurl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
						body: body.toString()
					});
					const raw = await response.text();
					let payload;
					try {
						payload = JSON.parse(raw);
					} catch (parseError) {
						throw new Error(formatAjaxHtmlError(raw, response.status));
					}
					if (!payload.success) {
						throw new Error(payload.data && payload.data.message ? payload.data.message : '<?php echo esc_js( __( 'Translation failed.', 'polylang-openai-translator' ) ); ?>');
					}

					if (payload.data && payload.data.job_id) {
						return await runTranslationJob(payload.data.job_id);
					}

					return payload;
				}

				async function runTranslationJob(jobId) {
					let payload;
					do {
						payload = await translateJobStep(jobId);
						if (payload.data && payload.data.message) {
							status.textContent = payload.data.message;
						}
					} while (payload.data && !payload.data.done);

					return payload;
				}

				async function translateJobStep(jobId) {
					const body = new URLSearchParams();
					body.set('action', 'pot_translate_job_step');
					body.set('nonce', '<?php echo esc_js( $nonce ); ?>');
					body.set('job_id', jobId);

					const response = await fetch(ajaxurl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
						body: body.toString()
					});
					const raw = await response.text();
					let payload;
					try {
						payload = JSON.parse(raw);
					} catch (parseError) {
						throw new Error(formatAjaxHtmlError(raw, response.status));
					}
					if (!payload.success) {
						throw new Error(payload.data && payload.data.message ? payload.data.message : '<?php echo esc_js( __( 'Translation failed.', 'polylang-openai-translator' ) ); ?>');
					}

					return payload;
				}

				async function translateLanguageWithRetry(language, label, index, total) {
					let lastError;
					for (let attempt = 1; attempt <= 2; attempt++) {
						try {
							if (attempt > 1) {
								status.textContent = '<?php echo esc_js( __( 'Retrying', 'polylang-openai-translator' ) ); ?> ' + index + '/' + total + ': ' + label + ' (' + attempt + '/2)';
								await wait(2000);
							}
							return await translateLanguage(language);
						} catch (error) {
							lastError = error;
						}
					}

					throw lastError;
				}

				function wait(milliseconds) {
					return new Promise(function (resolve) {
						window.setTimeout(resolve, milliseconds);
					});
				}

				async function publishTranslations() {
					const body = new URLSearchParams();
					body.set('action', 'pot_publish_translations');
					body.set('nonce', '<?php echo esc_js( $nonce ); ?>');
					body.set('post_id', '<?php echo esc_js( (string) $post->ID ); ?>');

					const response = await fetch(ajaxurl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
						body: body.toString()
					});
					const raw = await response.text();
					let payload;
					try {
						payload = JSON.parse(raw);
					} catch (parseError) {
						throw new Error(formatAjaxHtmlError(raw, response.status));
					}
					if (!payload.success) {
						throw new Error(payload.data && payload.data.message ? payload.data.message : '<?php echo esc_js( __( 'Publishing failed.', 'polylang-openai-translator' ) ); ?>');
					}

					return payload;
				}

				function formatAjaxHtmlError(raw, statusCode) {
					const titleMatch = raw.match(/<title[^>]*>([\s\S]*?)<\/title>/i);
					if (titleMatch && titleMatch[1]) {
						return 'Server returned HTML instead of JSON (' + statusCode + '): ' + decodeHtml(titleMatch[1]).trim();
					}

					const text = raw.replace(/<script[\s\S]*?<\/script>/gi, ' ')
						.replace(/<style[\s\S]*?<\/style>/gi, ' ')
						.replace(/<[^>]+>/g, ' ')
						.replace(/\s+/g, ' ')
						.trim();

					return text
						? 'Server returned HTML instead of JSON (' + statusCode + '): ' + text.slice(0, 300)
						: 'Server returned an empty or invalid response (' + statusCode + ').';
				}

				function decodeHtml(value) {
					const textarea = document.createElement('textarea');
					textarea.innerHTML = value;
					return textarea.value;
				}
			})();
		</script>
		<?php
	}

	public static function ajax_translate_post(): void {
		self::register_ajax_fatal_error_handler();

		try {
			$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
			if ( ! $post_id || ! check_ajax_referer( self::NONCE_ACTION . '_' . $post_id, 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Security check failed.', 'polylang-openai-translator' ) ), 403 );
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_send_json_error( array( 'message' => __( 'You cannot edit this post.', 'polylang-openai-translator' ) ), 403 );
			}

			if ( ! self::has_polylang() ) {
				wp_send_json_error( array( 'message' => __( 'Polylang is not active.', 'polylang-openai-translator' ) ), 400 );
			}

			$target_lang = isset( $_POST['target_lang'] ) ? sanitize_key( wp_unslash( $_POST['target_lang'] ) ) : '';
			$job_id      = self::maybe_create_translation_job( $post_id, $target_lang );
			if ( is_wp_error( $job_id ) ) {
				wp_send_json_error( array( 'message' => $job_id->get_error_message() ), 400 );
			}
			if ( $job_id ) {
				wp_send_json_success(
					array(
						'job_id'  => $job_id,
						'done'    => false,
						'message' => __( 'Large page detected. Starting chunked translation...', 'polylang-openai-translator' ),
					)
				);
			}

			$result      = self::translate_post( $post_id, $target_lang );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}

			wp_send_json_success(
				array(
					'message' => __( 'Translation is ready. Open translated post.', 'polylang-openai-translator' ),
					'post_id'  => $result,
					'edit_url' => get_edit_post_link( $result, 'raw' ),
				)
			);
		} catch ( Throwable $exception ) {
			wp_send_json_error( array( 'message' => self::format_throwable_message( $exception ) ), 500 );
		}
	}

	public static function ajax_translate_job_step(): void {
		self::register_ajax_fatal_error_handler();

		try {
			$job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
			if ( '' === $job_id || ! check_ajax_referer( self::NONCE_ACTION . '_' . self::get_job_post_id( $job_id ), 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Security check failed.', 'polylang-openai-translator' ) ), 403 );
			}

			$result = self::process_translation_job_step( $job_id );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}

			wp_send_json_success( $result );
		} catch ( Throwable $exception ) {
			wp_send_json_error( array( 'message' => self::format_throwable_message( $exception ) ), 500 );
		}
	}

	public static function ajax_publish_translations(): void {
		self::register_ajax_fatal_error_handler();

		try {
			$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
			if ( ! $post_id || ! check_ajax_referer( self::NONCE_ACTION . '_' . $post_id, 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Security check failed.', 'polylang-openai-translator' ) ), 403 );
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_send_json_error( array( 'message' => __( 'You cannot edit this post.', 'polylang-openai-translator' ) ), 403 );
			}

			if ( ! self::has_polylang() ) {
				wp_send_json_error( array( 'message' => __( 'Polylang is not active.', 'polylang-openai-translator' ) ), 400 );
			}

			$result = self::publish_linked_translations( $post_id );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}

			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: 1: published count, 2: skipped count */
						__( 'Published %1$d translations. Skipped %2$d.', 'polylang-openai-translator' ),
						$result['published'],
						$result['skipped']
					),
					'published' => $result['published'],
					'skipped'   => $result['skipped'],
				)
			);
		} catch ( Throwable $exception ) {
			wp_send_json_error( array( 'message' => self::format_throwable_message( $exception ) ), 500 );
		}
	}

	public static function maybe_show_all_media_in_editors( array $query ): array {
		if ( ! is_admin() || ! current_user_can( 'upload_files' ) ) {
			return $query;
		}

		$options = self::get_options();
		if ( empty( $options['show_all_media_in_editors'] ) ) {
			return $query;
		}

		unset( $query['lang'] );
		$query['suppress_filters'] = true;

		if ( isset( $query['tax_query'] ) && is_array( $query['tax_query'] ) ) {
			$query['tax_query'] = self::remove_language_tax_queries( $query['tax_query'] );
		}

		return $query;
	}

	private static function remove_language_tax_queries( array $tax_query ): array {
		$clean = array();
		foreach ( $tax_query as $key => $clause ) {
			if ( is_array( $clause ) && isset( $clause['taxonomy'] ) && 'language' === $clause['taxonomy'] ) {
				continue;
			}

			$clean[ $key ] = is_array( $clause ) ? self::remove_language_tax_queries( $clause ) : $clause;
		}

		return $clean;
	}

	private static function translate_post( int $post_id, string $target_lang ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'pot_missing_post', __( 'Source post not found.', 'polylang-openai-translator' ) );
		}

		$available_langs = pll_languages_list( array( 'fields' => 'slug' ) );
		if ( ! in_array( $target_lang, $available_langs, true ) ) {
			return new WP_Error( 'pot_bad_language', __( 'Target language is not configured in Polylang.', 'polylang-openai-translator' ) );
		}

		$source_lang = pll_get_post_language( $post_id, 'slug' );
		if ( empty( $source_lang ) && function_exists( 'pll_default_language' ) ) {
			$source_lang = pll_default_language( 'slug' );
			pll_set_post_language( $post_id, $source_lang );
		}

		if ( empty( $source_lang ) ) {
			return new WP_Error( 'pot_missing_source_language', __( 'Source post language is not set in Polylang.', 'polylang-openai-translator' ) );
		}

		if ( $source_lang === $target_lang ) {
			return new WP_Error( 'pot_same_language', __( 'Target language is the same as the source language.', 'polylang-openai-translator' ) );
		}

		$has_elementor_data = self::has_elementor_data( $post_id );
		$translation        = self::request_translation( $post, $source_lang, $target_lang, $has_elementor_data );
		if ( is_wp_error( $translation ) ) {
			return $translation;
		}

		return self::save_translated_post( $post, $post_id, $source_lang, $target_lang, $translation );
	}

	private static function save_translated_post( WP_Post $post, int $post_id, string $source_lang, string $target_lang, array $translation ) {
		$translations    = pll_get_post_translations( $post_id );
		$translated_id   = isset( $translations[ $target_lang ] ) ? absint( $translations[ $target_lang ] ) : 0;
		$translated_post = $translated_id ? get_post( $translated_id ) : null;
		$postarr         = array(
			'post_title'   => $translation['title'],
			'post_content' => $translation['content'],
			'post_excerpt' => $translation['excerpt'],
			'post_status'  => self::get_initial_translation_status( $post ),
			'post_type'    => $post->post_type,
			'post_author'  => $post->post_author,
		);

		if ( 'page' === $post->post_type ) {
			$postarr['menu_order'] = $post->menu_order;
			$postarr['post_parent'] = self::get_translated_parent_id( $post, $target_lang );
		}

		if ( $translated_post ) {
			if ( 'trash' === $translated_post->post_status ) {
				wp_untrash_post( $translated_id );
				$translated_post = get_post( $translated_id );
			}

			$postarr['ID']          = $translated_id;
			$postarr['post_status'] = $translated_post ? self::get_existing_translation_status( $post, $translated_post ) : self::get_initial_translation_status( $post );
			$saved_id              = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$postarr['post_name'] = sanitize_title( $translation['title'] );
			$saved_id            = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $saved_id ) ) {
			return $saved_id;
		}

		pll_set_post_language( $saved_id, $target_lang );
		self::copy_post_context( $post_id, $saved_id, $target_lang );
		$builder_result = self::translate_builder_content( $post_id, $saved_id, $source_lang, $target_lang );
		if ( is_wp_error( $builder_result ) ) {
			return $builder_result;
		}

		$translations[ $source_lang ] = $post_id;
		$translations[ $target_lang ] = $saved_id;
		pll_save_post_translations( array_filter( $translations ) );

		return $saved_id;
	}

	private static function get_initial_translation_status( WP_Post $post ): string {
		if ( 'gp_elements' === $post->post_type ) {
			return $post->post_status;
		}

		return 'draft';
	}

	private static function get_existing_translation_status( WP_Post $source_post, WP_Post $translated_post ): string {
		if ( 'trash' === $translated_post->post_status || 'auto-draft' === $translated_post->post_status ) {
			return self::get_initial_translation_status( $source_post );
		}

		return $translated_post->post_status;
	}

	private static function maybe_create_translation_job( int $post_id, string $target_lang ) {
		$post = get_post( $post_id );
		if ( ! $post || self::has_elementor_data( $post_id ) || ! self::should_chunk_post_content( $post->post_content ) ) {
			return '';
		}

		if ( self::should_preserve_markup_translation( $post ) ) {
			return '';
		}

		$available_langs = pll_languages_list( array( 'fields' => 'slug' ) );
		if ( ! in_array( $target_lang, $available_langs, true ) ) {
			return new WP_Error( 'pot_bad_language', __( 'Target language is not configured in Polylang.', 'polylang-openai-translator' ) );
		}

		$source_lang = pll_get_post_language( $post_id, 'slug' );
		if ( empty( $source_lang ) && function_exists( 'pll_default_language' ) ) {
			$source_lang = pll_default_language( 'slug' );
			pll_set_post_language( $post_id, $source_lang );
		}

		if ( empty( $source_lang ) ) {
			return new WP_Error( 'pot_missing_source_language', __( 'Source post language is not set in Polylang.', 'polylang-openai-translator' ) );
		}

		if ( $source_lang === $target_lang ) {
			return new WP_Error( 'pot_same_language', __( 'Target language is the same as the source language.', 'polylang-openai-translator' ) );
		}

		$job_id = 'pot_' . wp_generate_password( 24, false, false );
		$job    = array(
			'post_id'      => $post_id,
			'target_lang'  => $target_lang,
			'source_lang'  => $source_lang,
			'chunks'       => self::split_post_content_into_chunks( $post->post_content ),
			'translated'   => array(),
			'current'      => 0,
			'meta'         => null,
			'created'      => time(),
		);

		set_transient( self::job_transient_key( $job_id ), $job, 6 * HOUR_IN_SECONDS );

		return $job_id;
	}

	private static function get_job_post_id( string $job_id ): int {
		$job = get_transient( self::job_transient_key( $job_id ) );
		return is_array( $job ) && isset( $job['post_id'] ) ? absint( $job['post_id'] ) : 0;
	}

	private static function process_translation_job_step( string $job_id ) {
		$job = get_transient( self::job_transient_key( $job_id ) );
		if ( ! is_array( $job ) ) {
			return new WP_Error( 'pot_missing_job', __( 'Translation job expired or was not found. Please start again.', 'polylang-openai-translator' ) );
		}

		$post_id = absint( $job['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'pot_job_permission', __( 'You cannot edit this post.', 'polylang-openai-translator' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'pot_missing_post', __( 'Source post not found.', 'polylang-openai-translator' ) );
		}

		$chunks      = is_array( $job['chunks'] ?? null ) ? $job['chunks'] : array();
		$total_steps = count( $chunks ) + 2;

		if ( empty( $job['meta'] ) ) {
			$meta = self::request_translation( $post, (string) $job['source_lang'], (string) $job['target_lang'], true );
			if ( is_wp_error( $meta ) ) {
				return $meta;
			}

			$job['meta'] = array(
				'title'   => $meta['title'],
				'excerpt' => $meta['excerpt'],
			);
			set_transient( self::job_transient_key( $job_id ), $job, 6 * HOUR_IN_SECONDS );

			return array(
				'done'    => false,
				'message' => sprintf( __( 'Translated title and excerpt. Step %1$d/%2$d.', 'polylang-openai-translator' ), 1, $total_steps ),
			);
		}

		$chunk_keys = array_keys( $chunks );
		$current    = absint( $job['current'] ?? 0 );
		if ( isset( $chunk_keys[ $current ] ) ) {
			$key    = $chunk_keys[ $current ];
			$result = self::request_string_map_translation_chunk( array( $key => $chunks[ $key ] ), (string) $job['source_lang'], (string) $job['target_lang'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$job['translated'][ $key ] = (string) ( $result[ $key ] ?? $chunks[ $key ] );
			$job['current']            = $current + 1;
			set_transient( self::job_transient_key( $job_id ), $job, 6 * HOUR_IN_SECONDS );

			return array(
				'done'    => false,
				'message' => sprintf( __( 'Translated content chunk %1$d/%2$d.', 'polylang-openai-translator' ), $current + 1, count( $chunks ) ),
			);
		}

		$content = '';
		foreach ( $chunk_keys as $key ) {
			$content .= (string) ( $job['translated'][ $key ] ?? $chunks[ $key ] );
		}

		$saved_id = self::save_translated_post(
			$post,
			$post_id,
			(string) $job['source_lang'],
			(string) $job['target_lang'],
			array(
				'title'   => (string) ( $job['meta']['title'] ?? get_the_title( $post ) ),
				'excerpt' => (string) ( $job['meta']['excerpt'] ?? $post->post_excerpt ),
				'content' => $content,
			)
		);
		if ( is_wp_error( $saved_id ) ) {
			return $saved_id;
		}

		delete_transient( self::job_transient_key( $job_id ) );

		return array(
			'done'     => true,
			'message'  => __( 'Translation is ready. Open translated post.', 'polylang-openai-translator' ),
			'post_id'  => $saved_id,
			'edit_url' => get_edit_post_link( $saved_id, 'raw' ),
		);
	}

	private static function job_transient_key( string $job_id ): string {
		return 'pot_translate_job_' . preg_replace( '/[^a-zA-Z0-9_]/', '', $job_id );
	}

	private static function publish_linked_translations( int $post_id ) {
		$source_lang  = pll_get_post_language( $post_id, 'slug' );
		$translations = pll_get_post_translations( $post_id );

		if ( empty( $translations ) ) {
			return new WP_Error( 'pot_no_translations', __( 'No linked translations found.', 'polylang-openai-translator' ) );
		}

		$published = 0;
		$skipped   = 0;

		foreach ( $translations as $lang => $translation_id ) {
			$translation_id = absint( $translation_id );
			if ( ! $translation_id || $translation_id === $post_id || $lang === $source_lang ) {
				continue;
			}

			if ( ! current_user_can( 'publish_post', $translation_id ) || ! current_user_can( 'edit_post', $translation_id ) ) {
				$skipped++;
				continue;
			}

			$translation_post = get_post( $translation_id );
			if ( ! $translation_post ) {
				$skipped++;
				continue;
			}

			if ( 'publish' === $translation_post->post_status ) {
				$published++;
				continue;
			}

			if ( 'trash' === $translation_post->post_status ) {
				wp_untrash_post( $translation_id );
			}

			$updated = wp_update_post(
				array(
					'ID'          => $translation_id,
					'post_status' => 'publish',
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				$skipped++;
				continue;
			}

			$published++;
		}

		return array(
			'published' => $published,
			'skipped'   => $skipped,
		);
	}

	private static function register_ajax_fatal_error_handler(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}

		$registered = true;
		register_shutdown_function(
			static function (): void {
				$error = error_get_last();
				if ( ! is_array( $error ) || empty( $error['type'] ) ) {
					return;
				}

				$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
				if ( ! in_array( (int) $error['type'], $fatal_types, true ) ) {
					return;
				}

				$message = sprintf(
					'PHP fatal error: %s in %s:%d',
					$error['message'] ?? 'unknown error',
					$error['file'] ?? 'unknown file',
					$error['line'] ?? 0
				);

				if ( ! headers_sent() ) {
					status_header( 500 );
					header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
				}

				echo wp_json_encode(
					array(
						'success' => false,
						'data'    => array(
							'message' => $message,
						),
					)
				);
			}
		);
	}

	private static function format_throwable_message( Throwable $exception ): string {
		return sprintf(
			'PHP error: %s in %s:%d',
			$exception->getMessage(),
			$exception->getFile(),
			$exception->getLine()
		);
	}

	private static function request_translation( WP_Post $post, string $source_lang, string $target_lang, bool $skip_content = false ) {
		$options = self::get_options();
		if ( empty( $options['api_key'] ) ) {
			return new WP_Error( 'pot_missing_api_key', __( 'OpenAI API key is missing.', 'polylang-openai-translator' ) );
		}

		$should_preserve_markup = ! $skip_content && self::should_preserve_markup_translation( $post );
		$should_chunk_content   = ! $skip_content && ! $should_preserve_markup && self::should_chunk_post_content( $post->post_content );
		$payload = array(
			'title'   => get_the_title( $post ),
			'excerpt' => $post->post_excerpt,
			'content' => ( $skip_content || $should_chunk_content || $should_preserve_markup ) ? '' : $post->post_content,
		);

		$instructions = self::build_instructions( $source_lang, $target_lang, $options['custom_instructions'] );
		$input_json   = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$body         = self::build_api_body( $options, $instructions, $input_json );

		$response = self::post_to_api( $options, $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = $decoded['error']['message'] ?? sprintf( __( 'OpenAI request failed with HTTP %d.', 'polylang-openai-translator' ), $status_code );
			return new WP_Error( 'pot_openai_error', $message );
		}

		$output_text = self::extract_api_response_text( is_array( $decoded ) ? $decoded : array(), $options['api_format'] );
		$translation = self::decode_translation_json( $output_text );
		if ( is_wp_error( $translation ) ) {
			return $translation;
		}

		$content = $skip_content ? $post->post_content : (string) ( $translation['content'] ?? '' );
		if ( $should_preserve_markup ) {
			$content = self::request_markup_text_translation( $post->post_content, $source_lang, $target_lang );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
		}

		if ( $should_chunk_content ) {
			$content = self::request_chunked_content_translation( $post->post_content, $source_lang, $target_lang );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
		}

		return array(
			'title'   => sanitize_text_field( $translation['title'] ?? '' ),
			'excerpt' => (string) ( $translation['excerpt'] ?? '' ),
			'content' => $content,
		);
	}

	private static function should_chunk_post_content( string $content ): bool {
		return strlen( $content ) > 6000 || ( function_exists( 'has_blocks' ) && has_blocks( $content ) && strlen( $content ) > 3000 );
	}

	private static function should_preserve_markup_translation( WP_Post $post ): bool {
		return 'gp_elements' === $post->post_type;
	}

	private static function request_markup_text_translation( string $content, string $source_lang, string $target_lang ) {
		$segments = self::extract_markup_text_segments( $content );
		if ( empty( $segments ) ) {
			return $content;
		}

		$translations = array();
		$batch        = array();
		$batch_size   = 0;
		$batch_index  = 1;

		foreach ( $segments as $segment ) {
			$batch_key             = 'text_' . $batch_index;
			$batch[ $batch_key ]   = $segment;
			$batch_size           += strlen( $segment );
			$translations[ $segment ] = null;
			$batch_index++;

			if ( count( $batch ) >= 80 || $batch_size >= 4000 ) {
				$result = self::request_string_map_translation( $batch, $source_lang, $target_lang );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				foreach ( $batch as $key => $value ) {
					$translations[ $value ] = (string) ( $result[ $key ] ?? $value );
				}

				$batch      = array();
				$batch_size = 0;
			}
		}

		if ( ! empty( $batch ) ) {
			$result = self::request_string_map_translation( $batch, $source_lang, $target_lang );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			foreach ( $batch as $key => $value ) {
				$translations[ $value ] = (string) ( $result[ $key ] ?? $value );
			}
		}

		return self::replace_markup_text_segments( $content, $translations );
	}

	private static function extract_markup_text_segments( string $content ): array {
		$tokens   = preg_split( '/(<!--[\s\S]*?-->|<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$segments = array();

		foreach ( (array) $tokens as $token ) {
			if ( '' === $token || '<' === $token[0] ) {
				continue;
			}

			if ( ! self::is_translatable_text_segment( $token ) ) {
				continue;
			}

			$segments[] = $token;
		}

		return array_values( array_unique( $segments ) );
	}

	private static function replace_markup_text_segments( string $content, array $translations ): string {
		$tokens = preg_split( '/(<!--[\s\S]*?-->|<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $tokens ) ) {
			return $content;
		}

		foreach ( $tokens as $index => $token ) {
			if ( '' === $token || '<' === $token[0] || ! array_key_exists( $token, $translations ) ) {
				continue;
			}

			$leading  = '';
			$trailing = '';
			if ( preg_match( '/^\s+/u', $token, $match ) ) {
				$leading = $match[0];
			}
			if ( preg_match( '/\s+$/u', $token, $match ) ) {
				$trailing = $match[0];
			}

			$tokens[ $index ] = $leading . trim( (string) $translations[ $token ] ) . $trailing;
		}

		return implode( '', $tokens );
	}

	private static function is_translatable_text_segment( string $text ): bool {
		$plain = trim( wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) ) );
		if ( '' === $plain ) {
			return false;
		}

		if ( preg_match( '/^[\d\s\+\-\(\)\.:,\/#@]+$/u', $plain ) ) {
			return false;
		}

		if ( preg_match( '/^(https?:\/\/|mailto:|tel:|[\w.+-]+@[\w.-]+\.[a-z]{2,})/i', $plain ) ) {
			return false;
		}

		return true;
	}

	private static function request_chunked_content_translation( string $content, string $source_lang, string $target_lang ) {
		$chunks = self::split_post_content_into_chunks( $content );
		if ( count( $chunks ) <= 1 ) {
			$result = self::request_string_map_translation( array( 'content' => $content ), $source_lang, $target_lang );
			return is_wp_error( $result ) ? $result : (string) ( $result['content'] ?? $content );
		}

		$translated = self::request_string_map_translation( $chunks, $source_lang, $target_lang );
		if ( is_wp_error( $translated ) ) {
			return $translated;
		}

		$output = '';
		foreach ( array_keys( $chunks ) as $key ) {
			$output .= (string) ( $translated[ $key ] ?? $chunks[ $key ] );
		}

		return $output;
	}

	private static function split_post_content_into_chunks( string $content ): array {
		$parts = array();
		if ( function_exists( 'has_blocks' ) && has_blocks( $content ) && function_exists( 'parse_blocks' ) && function_exists( 'serialize_block' ) ) {
			foreach ( parse_blocks( $content ) as $block ) {
				$serialized = serialize_block( $block );
				if ( '' !== trim( $serialized ) ) {
					$parts[] = $serialized;
				}
			}
		}

		if ( empty( $parts ) ) {
			$parts = preg_split( "/(\n\s*\n)/", $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		}

		$chunks        = array();
		$current       = '';
		$current_index = 1;

		foreach ( $parts as $part ) {
			if ( '' !== $current && strlen( $current ) + strlen( $part ) > 5000 ) {
				$chunks[ 'content_' . $current_index ] = $current;
				$current                              = '';
				$current_index++;
			}

			$current .= $part;
		}

		if ( '' !== $current ) {
			$chunks[ 'content_' . $current_index ] = $current;
		}

		return $chunks ? $chunks : array( 'content_1' => $content );
	}

	private static function has_elementor_data( int $post_id ): bool {
		$raw_data = get_post_meta( $post_id, '_elementor_data', true );
		return '' !== $raw_data && null !== $raw_data;
	}

	private static function translate_builder_content( int $source_id, int $target_id, string $source_lang, string $target_lang ) {
		$elementor_result = self::translate_elementor_data( $source_id, $target_id, $source_lang, $target_lang );
		if ( is_wp_error( $elementor_result ) ) {
			return $elementor_result;
		}

		return true;
	}

	private static function translate_elementor_data( int $source_id, int $target_id, string $source_lang, string $target_lang ) {
		$raw_data = get_post_meta( $source_id, '_elementor_data', true );
		if ( '' === $raw_data || null === $raw_data ) {
			return true;
		}

		$data = json_decode( (string) $raw_data, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'pot_bad_elementor_json', __( 'Elementor data exists but is not valid JSON.', 'polylang-openai-translator' ) );
		}

		$options = self::get_options();
		if ( ! empty( $options['translate_media'] ) ) {
			$media_result = self::translate_elementor_media_references( $data, $source_lang, $target_lang );
			if ( is_wp_error( $media_result ) ) {
				return $media_result;
			}
		}

		$strings = array();
		self::collect_builder_strings( $data, $strings );

		if ( empty( $strings ) ) {
			update_post_meta( $target_id, '_elementor_data', wp_slash( (string) $raw_data ) );
			return true;
		}

		$translated_strings = self::request_string_map_translation( $strings, $source_lang, $target_lang );
		if ( is_wp_error( $translated_strings ) ) {
			return $translated_strings;
		}

		self::apply_builder_strings( $data, $translated_strings );
		update_post_meta( $target_id, '_elementor_data', wp_slash( wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );

		return true;
	}

	private static function collect_builder_strings( &$node, array &$strings, string $key = '' ): void {
		if ( is_array( $node ) ) {
			foreach ( $node as $child_key => &$child ) {
				self::collect_builder_strings( $child, $strings, (string) $child_key );
			}
			return;
		}

		if ( ! is_string( $node ) || ! self::is_translatable_builder_string( $key, $node ) ) {
			return;
		}

		$id             = 's' . ( count( $strings ) + 1 );
		$strings[ $id ] = $node;
		$node           = '{{' . $id . '}}';
	}

	private static function translate_elementor_media_references( &$node, string $source_lang, string $target_lang ) {
		if ( ! is_array( $node ) ) {
			return true;
		}

		if ( isset( $node['id'] ) && is_numeric( $node['id'] ) && self::looks_like_elementor_media_array( $node ) ) {
			$translated_id = self::translate_media_attachment( (int) $node['id'], $source_lang, $target_lang );
			if ( is_wp_error( $translated_id ) ) {
				return $translated_id;
			}
			if ( $translated_id ) {
				$node['id'] = $translated_id;
			}
		}

		foreach ( $node as &$child ) {
			$result = self::translate_elementor_media_references( $child, $source_lang, $target_lang );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	private static function looks_like_elementor_media_array( array $node ): bool {
		return isset( $node['url'] )
			|| isset( $node['source'] )
			|| isset( $node['alt'] )
			|| isset( $node['size'] )
			|| isset( $node['library'] );
	}

	private static function apply_builder_strings( &$node, array $translated_strings ): void {
		if ( is_array( $node ) ) {
			foreach ( $node as &$child ) {
				self::apply_builder_strings( $child, $translated_strings );
			}
			return;
		}

		if ( is_string( $node ) && preg_match( '/^\{\{(s\d+)\}\}$/', $node, $matches ) ) {
			$node = isset( $translated_strings[ $matches[1] ] ) ? (string) $translated_strings[ $matches[1] ] : '';
		}
	}

	private static function is_translatable_builder_string( string $key, string $value ): bool {
		$value = trim( $value );
		if ( '' === $value ) {
			return false;
		}

		if ( strlen( $value ) > 12000 ) {
			return false;
		}

		if ( preg_match( '#^(https?:)?//#i', $value ) || preg_match( '#^[\w./:-]+\.(jpg|jpeg|png|gif|webp|svg|mp4|webm|pdf|zip)$#i', $value ) ) {
			return false;
		}

		if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $value ) ) {
			return false;
		}

		$allowed_keys = array(
			'title',
			'editor',
			'text',
			'description',
			'caption',
			'alt',
			'image_alt',
			'placeholder',
			'label',
			'content',
			'button_text',
			'link_text',
			'tab_title',
			'tab_content',
			'item_title',
			'item_description',
			'html',
		);

		return in_array( $key, $allowed_keys, true ) || (bool) preg_match( '/(_title|_text|_content|_description|_caption|_label|_placeholder)$/', $key );
	}

	private static function request_string_map_translation( array $strings, string $source_lang, string $target_lang ) {
		$chunks      = array();
		$chunk       = array();
		$chunk_chars = 0;
		foreach ( $strings as $key => $value ) {
			$value_chars = strlen( (string) $value );
			$is_content_chunk = 0 === strpos( (string) $key, 'content_' );
			$max_items        = $is_content_chunk ? 3 : 80;
			$max_chars        = $is_content_chunk ? 12000 : 20000;
			if ( $chunk && ( count( $chunk ) >= $max_items || $chunk_chars + $value_chars > $max_chars ) ) {
				$chunks[]    = $chunk;
				$chunk       = array();
				$chunk_chars = 0;
			}

			$chunk[ $key ] = $value;
			$chunk_chars  += $value_chars;
		}

		if ( $chunk ) {
			$chunks[] = $chunk;
		}

		$translated = array();
		foreach ( $chunks as $chunk ) {
			$result = self::request_string_map_translation_chunk( $chunk, $source_lang, $target_lang );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$translated = array_merge( $translated, $result );
		}

		return $translated;
	}

	private static function request_media_translation( WP_Post $attachment, string $source_lang, string $target_lang ) {
		$payload = array(
			'title'       => $attachment->post_title,
			'alt'         => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			'caption'     => $attachment->post_excerpt,
			'description' => $attachment->post_content,
		);

		if ( '' === trim( implode( '', array_map( 'strval', $payload ) ) ) ) {
			return $payload;
		}

		$result = self::request_string_map_translation( $payload, $source_lang, $target_lang );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return wp_parse_args(
			$result,
			array(
				'title'       => $payload['title'],
				'alt'         => $payload['alt'],
				'caption'     => $payload['caption'],
				'description' => $payload['description'],
			)
		);
	}

	private static function request_string_map_translation_chunk( array $strings, string $source_lang, string $target_lang ) {
		$options = self::get_options();
		if ( empty( $options['api_key'] ) ) {
			return new WP_Error( 'pot_missing_api_key', __( 'OpenAI API key is missing.', 'polylang-openai-translator' ) );
		}

		$instructions = sprintf(
			'Translate each JSON value from %s to %s. Preserve all HTML tags, attributes, shortcodes, URLs, variables, icon names, product codes, line breaks, and whitespace shape. Return only a valid JSON object with exactly the same keys. Translate visible human-readable text only.',
			$source_lang,
			$target_lang
		);

		if ( '' !== trim( $options['custom_instructions'] ) ) {
			$instructions .= "\n\nSite-specific instructions:\n" . trim( $options['custom_instructions'] );
		}

		$body = self::build_api_body(
			$options,
			$instructions,
			wp_json_encode( $strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);

		$response = self::post_to_api( $options, $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = $decoded['error']['message'] ?? sprintf( __( 'OpenAI request failed with HTTP %d.', 'polylang-openai-translator' ), $status_code );
			return new WP_Error( 'pot_openai_error', $message );
		}

		$output_text = self::extract_api_response_text( is_array( $decoded ) ? $decoded : array(), $options['api_format'] );
		$translated  = self::decode_json_object( $output_text );
		if ( is_wp_error( $translated ) ) {
			return $translated;
		}

		return $translated;
	}

	private static function build_instructions( string $source_lang, string $target_lang, string $custom_instructions ): string {
		$base = sprintf(
			'Translate WordPress post JSON from %s to %s. Keep HTML, Gutenberg block comments, shortcodes, URLs, product codes, and placeholders intact. Translate visible human-readable text only. Return only valid JSON with exactly these string keys: title, excerpt, content.',
			$source_lang,
			$target_lang
		);

		if ( '' !== trim( $custom_instructions ) ) {
			$base .= "\n\nSite-specific instructions:\n" . trim( $custom_instructions );
		}

		return $base;
	}

	private static function build_api_body( array $options, string $instructions, string $input_json ): array {
		if ( 'chat_completions' === $options['api_format'] ) {
			return array(
				'model'       => $options['model'],
				'messages'    => array(
					array(
						'role'    => 'system',
						'content' => $instructions,
					),
					array(
						'role'    => 'user',
						'content' => $input_json,
					),
				),
				'temperature' => 0.2,
				'max_tokens'  => (int) $options['max_output_tokens'],
			);
		}

		return array(
			'model'             => $options['model'],
			'instructions'      => $instructions,
			'input'             => $input_json,
			'max_output_tokens' => (int) $options['max_output_tokens'],
		);
	}

	private static function post_to_api( array $options, array $body ) {
		$endpoint = self::normalize_api_endpoint( $options['api_endpoint'], $options['api_format'] );
		$args     = array(
			'timeout'     => (int) $options['request_timeout'],
			'httpversion' => '1.1',
			'headers'     => array(
				'Authorization' => 'Bearer ' . $options['api_key'],
				'Content-Type'  => 'application/json',
			),
			'body'        => wp_json_encode( $body ),
		);

		$last_response = null;
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			self::refresh_time_limit( (int) $options['request_timeout'] + 60 );
			self::enable_http11_for_next_request();
			$response = wp_remote_post( $endpoint, $args );
			self::disable_http11_for_next_request();

			if ( ! is_wp_error( $response ) ) {
				return $response;
			}

			$last_response = $response;
			if ( ! self::is_retryable_http_error( $response ) || 3 === $attempt ) {
				return $response;
			}

			sleep( $attempt );
		}

		return $last_response;
	}

	private static function refresh_time_limit( int $seconds ): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( max( 120, $seconds ) );
		}
	}

	private static function is_retryable_http_error( WP_Error $error ): bool {
		$message = strtolower( $error->get_error_message() );

		return false !== strpos( $message, 'empty reply' )
			|| false !== strpos( $message, 'timed out' )
			|| false !== strpos( $message, 'operation timed out' )
			|| false !== strpos( $message, 'stream error' )
			|| false !== strpos( $message, 'internal_error' )
			|| false !== strpos( $message, 'connection reset' )
			|| false !== strpos( $message, 'remote end closed' );
	}

	private static function enable_http11_for_next_request(): void {
		add_action( 'http_api_curl', array( __CLASS__, 'force_curl_http11' ) );
	}

	private static function disable_http11_for_next_request(): void {
		remove_action( 'http_api_curl', array( __CLASS__, 'force_curl_http11' ) );
	}

	public static function force_curl_http11( $handle ): void {
		if ( defined( 'CURLOPT_HTTP_VERSION' ) && defined( 'CURL_HTTP_VERSION_1_1' ) ) {
			curl_setopt( $handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
		}
	}

	private static function normalize_api_endpoint( string $endpoint, string $api_format ): string {
		$endpoint = rtrim( trim( $endpoint ), '/' );

		if ( '' === $endpoint ) {
			$endpoint = 'https://api.openai.com/v1';
		}

		if ( preg_match( '#/(responses|chat/completions)$#', $endpoint ) ) {
			return $endpoint;
		}

		if ( 'chat_completions' === $api_format ) {
			return $endpoint . '/chat/completions';
		}

		return $endpoint . '/responses';
	}

	private static function extract_api_response_text( array $decoded, string $api_format ): string {
		if ( 'chat_completions' === $api_format ) {
			return (string) ( $decoded['choices'][0]['message']['content'] ?? '' );
		}

		return self::extract_response_text( $decoded );
	}

	private static function extract_response_text( array $decoded ): string {
		if ( isset( $decoded['output_text'] ) && is_string( $decoded['output_text'] ) ) {
			return $decoded['output_text'];
		}

		$text = '';
		foreach ( $decoded['output'] ?? array() as $item ) {
			foreach ( $item['content'] ?? array() as $content ) {
				if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
					$text .= $content['text'];
				}
			}
		}

		return $text;
	}

	private static function decode_translation_json( string $output_text ) {
		$decoded = self::decode_json_object( $output_text );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		if ( ! is_array( $decoded ) || ! isset( $decoded['title'], $decoded['content'] ) ) {
			return new WP_Error( 'pot_bad_openai_json', __( 'OpenAI did not return the expected translation JSON.', 'polylang-openai-translator' ) );
		}

		return $decoded;
	}

	private static function decode_json_object( string $output_text ) {
		$output_text = trim( $output_text );
		$decoded     = json_decode( $output_text, true );

		if ( ! is_array( $decoded ) ) {
			if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/s', $output_text, $matches ) ) {
				$decoded = json_decode( $matches[1], true );
			} elseif ( preg_match( '/\{.*\}/s', $output_text, $matches ) ) {
				$decoded = json_decode( $matches[0], true );
			}
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'pot_bad_openai_json', __( 'OpenAI did not return valid JSON.', 'polylang-openai-translator' ) );
		}

		return $decoded;
	}

	private static function copy_post_context( int $source_id, int $target_id, string $target_lang ): void {
		$thumbnail_id = get_post_thumbnail_id( $source_id );
		if ( $thumbnail_id ) {
			$options = self::get_options();
			if ( ! empty( $options['translate_media'] ) ) {
				$target_thumbnail_id = self::translate_media_attachment( $thumbnail_id, (string) pll_get_post_language( $source_id, 'slug' ), $target_lang );
				if ( ! is_wp_error( $target_thumbnail_id ) && $target_thumbnail_id ) {
					set_post_thumbnail( $target_id, $target_thumbnail_id );
					$thumbnail_id = 0;
				}
			}

			if ( $thumbnail_id ) {
				set_post_thumbnail( $target_id, $thumbnail_id );
			}
		}

		$template = get_page_template_slug( $source_id );
		if ( $template ) {
			update_post_meta( $target_id, '_wp_page_template', $template );
		}

		self::copy_elementor_support_meta( $source_id, $target_id );
		self::copy_generatepress_element_meta( $source_id, $target_id );

		self::copy_terms( $source_id, $target_id, $target_lang );

		$meta_keys = apply_filters(
			'pot_openai_translator_copy_meta_keys',
			array(),
			$source_id,
			$target_id,
			$target_lang
		);

		foreach ( array_unique( array_filter( array_map( 'sanitize_key', (array) $meta_keys ) ) ) as $meta_key ) {
			$values = get_post_meta( $source_id, $meta_key, false );
			delete_post_meta( $target_id, $meta_key );
			foreach ( $values as $value ) {
				add_post_meta( $target_id, $meta_key, maybe_unserialize( $value ) );
			}
		}
	}

	private static function translate_media_attachment( int $attachment_id, string $source_lang, string $target_lang ) {
		static $cache = array();

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return 0;
		}

		if ( function_exists( 'pll_is_translated_post_type' ) && ! pll_is_translated_post_type( 'attachment' ) ) {
			return $attachment_id;
		}

		if ( $source_lang === $target_lang ) {
			return $attachment_id;
		}

		if ( empty( $source_lang ) && function_exists( 'pll_default_language' ) ) {
			$source_lang = (string) pll_default_language( 'slug' );
		}

		if ( empty( $source_lang ) ) {
			return $attachment_id;
		}

		$cache_key = $attachment_id . ':' . $target_lang;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		if ( function_exists( 'pll_get_post_language' ) ) {
			$current_lang = pll_get_post_language( $attachment_id, 'slug' );
			if ( empty( $current_lang ) ) {
				return $attachment_id;
			}
		}

		$existing_id = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $attachment_id, $target_lang ) : 0;
		$source      = get_post( $attachment_id );
		if ( ! $source ) {
			return 0;
		}

		$translation = self::request_media_translation( $source, $source_lang, $target_lang );
		if ( is_wp_error( $translation ) ) {
			return $translation;
		}

		$attachment_data = array(
			'post_title'     => sanitize_text_field( $translation['title'] ?? $source->post_title ),
			'post_excerpt'   => (string) ( $translation['caption'] ?? $source->post_excerpt ),
			'post_content'   => (string) ( $translation['description'] ?? $source->post_content ),
			'post_mime_type' => $source->post_mime_type,
			'post_status'    => 'inherit',
			'post_type'      => 'attachment',
			'post_author'    => $source->post_author,
			'post_parent'    => 0,
			'guid'           => $source->guid,
		);

		if ( $existing_id ) {
			$attachment_data['ID'] = $existing_id;
			$target_id             = wp_update_post( wp_slash( $attachment_data ), true );
		} else {
			$target_id = wp_insert_post( wp_slash( $attachment_data ), true );
		}

		if ( is_wp_error( $target_id ) ) {
			return $target_id;
		}

		self::copy_attachment_file_meta( $attachment_id, (int) $target_id );

		if ( isset( $translation['alt'] ) ) {
			update_post_meta( (int) $target_id, '_wp_attachment_image_alt', sanitize_text_field( $translation['alt'] ) );
		}

		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( (int) $target_id, $target_lang );
		}

		if ( function_exists( 'pll_get_post_translations' ) && function_exists( 'pll_save_post_translations' ) ) {
			$translations                  = pll_get_post_translations( $attachment_id );
			$translations[ $source_lang ] = $attachment_id;
			$translations[ $target_lang ] = (int) $target_id;
			pll_save_post_translations( array_filter( $translations ) );
		}

		$cache[ $cache_key ] = (int) $target_id;

		return (int) $target_id;
	}

	private static function copy_attachment_file_meta( int $source_id, int $target_id ): void {
		$file = get_post_meta( $source_id, '_wp_attached_file', true );
		if ( '' !== $file && null !== $file ) {
			update_post_meta( $target_id, '_wp_attached_file', $file );
		}

		$metadata = wp_get_attachment_metadata( $source_id );
		if ( $metadata ) {
			wp_update_attachment_metadata( $target_id, $metadata );
		}
	}

	private static function copy_elementor_support_meta( int $source_id, int $target_id ): void {
		$meta_keys = array(
			'_elementor_edit_mode',
			'_elementor_template_type',
			'_elementor_version',
			'_elementor_page_settings',
			'_elementor_pro_version',
		);

		foreach ( $meta_keys as $meta_key ) {
			$value = get_post_meta( $source_id, $meta_key, true );
			if ( '' !== $value && null !== $value ) {
				update_post_meta( $target_id, $meta_key, maybe_unserialize( $value ) );
			}
		}

		delete_post_meta( $target_id, '_elementor_css' );
	}

	private static function copy_generatepress_element_meta( int $source_id, int $target_id ): void {
		if ( 'gp_elements' !== get_post_type( $source_id ) ) {
			return;
		}

		$all_meta = get_post_meta( $source_id );
		foreach ( $all_meta as $meta_key => $values ) {
			if ( self::is_skipped_generatepress_element_meta_key( $meta_key ) ) {
				continue;
			}

			delete_post_meta( $target_id, $meta_key );
			foreach ( $values as $value ) {
				add_post_meta( $target_id, $meta_key, maybe_unserialize( $value ) );
			}
		}

		self::refresh_generateblocks_css( $target_id );
		self::touch_generatepress_element( $target_id );
	}

	private static function is_skipped_generatepress_element_meta_key( string $meta_key ): bool {
		$skipped_keys = array(
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
		);

		foreach ( $skipped_keys as $skipped_key ) {
			if ( $meta_key === $skipped_key ) {
				return true;
			}
		}

		return false;
	}

	private static function refresh_generateblocks_css( int $post_id ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $post_id, 'posts' );
			wp_cache_delete( $post_id, 'post_meta' );
		}
	}

	private static function touch_generatepress_element( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'gp_elements' !== $post->post_type ) {
			return;
		}

		clean_post_cache( $post_id );
		wp_update_post(
			array(
				'ID'                => $post_id,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			)
		);
		clean_post_cache( $post_id );
	}

	private static function copy_terms( int $source_id, int $target_id, string $target_lang ): void {
		$taxonomies = get_object_taxonomies( get_post_type( $source_id ), 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy->public ) {
				continue;
			}

			$term_ids = wp_get_object_terms( $source_id, $taxonomy->name, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
				continue;
			}

			$target_terms = array();
			foreach ( $term_ids as $term_id ) {
				$target_term_id = $term_id;
				if ( function_exists( 'pll_is_translated_taxonomy' ) && pll_is_translated_taxonomy( $taxonomy->name ) && function_exists( 'pll_get_term' ) ) {
					$target_term_id = pll_get_term( $term_id, $target_lang );
				}
				if ( $target_term_id ) {
					$target_terms[] = (int) $target_term_id;
				}
			}

			if ( $target_terms ) {
				wp_set_object_terms( $target_id, $target_terms, $taxonomy->name, false );
			}
		}
	}

	private static function get_translated_parent_id( WP_Post $post, string $target_lang ): int {
		if ( ! $post->post_parent || ! function_exists( 'pll_get_post' ) ) {
			return 0;
		}

		return (int) pll_get_post( $post->post_parent, $target_lang );
	}

	public static function sanitize_options( $input ): array {
		$defaults = self::default_options();
		$input    = is_array( $input ) ? $input : array();

		return array(
			'api_key'             => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '',
			'model'               => isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : $defaults['model'],
			'api_endpoint'        => ! empty( $input['api_endpoint'] ) ? esc_url_raw( $input['api_endpoint'] ) : $defaults['api_endpoint'],
			'api_format'          => isset( $input['api_format'] ) && in_array( $input['api_format'], array( 'responses', 'chat_completions' ), true ) ? $input['api_format'] : $defaults['api_format'],
			'max_output_tokens'   => isset( $input['max_output_tokens'] ) ? max( 1000, min( 50000, absint( $input['max_output_tokens'] ) ) ) : $defaults['max_output_tokens'],
			'request_timeout'     => isset( $input['request_timeout'] ) ? max( 30, min( 900, absint( $input['request_timeout'] ) ) ) : $defaults['request_timeout'],
			'translate_media'     => ! empty( $input['translate_media'] ) ? 1 : 0,
			'show_all_media_in_editors' => ! empty( $input['show_all_media_in_editors'] ) ? 1 : 0,
			'custom_instructions' => isset( $input['custom_instructions'] ) ? sanitize_textarea_field( $input['custom_instructions'] ) : '',
		);
	}

	private static function get_options(): array {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), self::default_options() );
	}

	private static function default_options(): array {
		return array(
			'api_key'             => '',
			'model'               => 'gpt-5.2',
			'api_endpoint'        => 'https://api.openai.com/v1/responses',
			'api_format'          => 'responses',
			'max_output_tokens'   => 12000,
			'request_timeout'     => 300,
			'translate_media'     => 0,
			'show_all_media_in_editors' => 1,
			'custom_instructions' => 'Use natural, business-ready wording. Preserve technical terms when translating them would reduce accuracy.',
		);
	}

	private static function has_polylang(): bool {
		return function_exists( 'pll_languages_list' )
			&& function_exists( 'pll_get_post_language' )
			&& function_exists( 'pll_set_post_language' )
			&& function_exists( 'pll_get_post_translations' )
			&& function_exists( 'pll_save_post_translations' );
	}
}

POT_Polylang_OpenAI_Translator::boot();
