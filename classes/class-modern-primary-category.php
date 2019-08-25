<?php
/**
 * The worker class. No pun intended.
 */
defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'ST_Modern_Primary_Category' ) ):

class ST_Modern_Primary_Category {

	/**
	 * The name of a post meta field that will store ids of the primary terms for every supported taxonomy
	 */
	const META_FIELD_NAME     = '_stmpc_term_ids';

	/**
	 * When there is no term set for a post, but the permalink structure includes a %taxonomy% placeholder,
	 * a generic substitute will be used, based on this pattern (%s will be replaced with taxonomy name).
	 *
	 * Please note that for `category` taxonomy, the `default_category` option from the site settings will be used instead.
	 */
	const DEFAULT_TERM_SUBSTITUTE = 'no-%s';

	/**
	 * Capability required to see this feature enabled on post edit page.
	 * @see ST_Modern_Primary_Category::current_user_can()
	 */
	const REQUIRED_CAPABILITY = 'edit_posts';

	/**
	 *
	 */
	static function on_load() {

		//activation logic will be run only on admin
		add_action( 'init_admin', [__CLASS__, 'maybe_run_activation_logic'] );


		//make sure Gutenberg and REST API is aware of out post_meta field
		add_action( 'init', [__CLASS__, 'register_meta'] );

		//register dependencies
		add_action( 'init', [__CLASS__, 'register_scripts'] );

		//enqueue admin scripts and assets
		add_action( 'enqueue_block_editor_assets', [__CLASS__, 'enqueue_scripts'] );

		// Set the %category% value for permalinks (normal posts)
		add_filter( 'post_link_category', [__CLASS__, 'post_link_category'], 10, 3 );

		// Set the %taxonomy% value for permalinks of normal posts
		add_filter( 'post_link', [__CLASS__, 'post_link'], 10, 3 );

		// Pro only, handle custom post types and their custom taxonomies
		add_filter( 'post_type_link', [__CLASS__, 'post_type_link'], 10, 4 );

		// Disable the WPSEO v3.1+ Primary Category feature.
		add_filter( 'wpseo_primary_term_taxonomies', '__return_empty_array' );

	}

	/**
	 * If this is the first time the plugin runs, reset permalinks
	 */
	static function maybe_run_activation_logic() {
		if ( is_admin() && get_option( 'stmpc/activation' ) ) {
			flush_rewrite_rules();
			delete_option( 'stmpc/activation' );
		}
	}

	/**
	 * Register post meta field so that Gutenberg can access it via REST API
	 */
	static function register_meta() {
		/**
		 * This post meta field will hold a JSON string of an object,
		 * where keys will be taxonomy names and values will be id of the primary term for that taxonomy,
		 * e.g.: {"category":9, "products_tax":42}
		 *
		 * Please keep in mind, that the CPTs that use this field
		 * must have `show_in_rest` set to `true`,
		 * and their `supports` array must include `'custom-fields'`.
		 *
		 * Note also, that to have nice permalinks for custom post types,
		 * you can use %category% and custom %taxonomy% placeholders when registering a CPT:
		 * 'rewrite' => array(
		 *      'slug' => '/%category%/%demo_taxonomy%',
		 *      'with_front' => true
		 * )
		 */
		register_meta( 'post', self::META_FIELD_NAME, array(
			/**
			 * Without `show_in_rest`, Gutenberg would not be able to access this postmeta field!
			 */
			'show_in_rest'      => true,
			'type'              => 'string',
			/**
			 * As of WordPress 4.7, you can use `object_subtype` to limit the use of this field to specific post type.
	         * 'object_subtype' => 'your_custom_post_type_name',
			 */
			'single'            => true,
			'sanitize_callback' => 'sanitize_text_field',
			/**
			 * This is necessary when meta key starts with an underscore and is considered "protected":
			 */
			'auth_callback'     => [ __CLASS__, 'current_user_can' ]
		) );
	}

	/**
	 * Check whether current user is allowed to use the UI features added by this plugin.
	 *
	 * @return bool
	 */
	static function current_user_can() {

		return apply_filters( 'stmpc/current_user_can', current_user_can( self::REQUIRED_CAPABILITY ) );
	}

	/**
	 * Register scripts.
	 */
	static function register_scripts() {

		wp_register_script(
			'st-mpc-script',
			plugins_url( '/assets/build/index.js', dirname( __FILE__ ) ),
			array(
				'wp-blocks',
				'wp-element',
				'wp-plugins',
				'wp-edit-post',
				'wp-i18n',
				'wp-components',
				'wp-data',
				'wp-compose',
				'wp-api-fetch',
				'wp-url',
				'lodash'
			),
			filemtime( plugin_dir_path( __FILE__ ) )
		);

		wp_register_style(
			'st-mpc-style',
			plugins_url( '/assets/style.css', dirname( __FILE__ )),
			array(),
			filemtime( plugin_dir_path( __FILE__ ) )
		);
	}

	/**
	 * Enqueue scripts
	 */
	static function enqueue_scripts() {
		if ( ! $screen = get_current_screen() ) {
			return;
		}
		if ( ! in_array( $screen->id, self::supported_post_type_names() ) ) {
			return;
		}
		if ( ! self::current_user_can() ) {
			return;
		}

		$script_params = array(
			'metafieldName'       => self::META_FIELD_NAME,
			'supportedTaxonomies' => self::supported_taxonomy_names()
		);

		wp_localize_script( 'st-mpc-script', 'stmpcParams', $script_params );

		wp_enqueue_script( 'st-mpc-script' );
		wp_enqueue_style( 'st-mpc-style' );
	}

	/**
	 * Get the category term to use when building permalink for regular posts.
	 *
	 * @param WP_Term $default
	 * @param WP_Term[] $terms
	 * @param WP_Post $post
	 *
	 * @return WP_Term
	 */
	public static function post_link_category( $default, $terms, $post ) {
		$primary_term_ids = self::get_primary_term_ids( $post->ID );

		if ( !isset( $primary_term_ids[ 'category' ] ) ) {
			return $default;
		}

		foreach ( $terms as $cat ) {
			if ( $cat->term_id == $primary_term_ids[ 'category' ] ) {
				return $cat;
			}
		}

		return $default;
	}

	/**
	 * For permalinks of regular posts, replaces placeholders of custom %taxonomy% with the names of corresponding primary terms.
	 *
	 * @param string  $permalink The post's permalink.
	 * @param WP_Post $post      The post in question.
	 * @param bool    $leavename Whether to keep the post name.
	 */
	static function post_link( $permalink, $post, $leavename ){

		if( strpos( $permalink, '%' ) === FALSE ) {
			/**
			 * All placeholders already resolved
			 */
			return $permalink;
		}

		$primary_term_ids = self::get_primary_term_ids( $post->ID );
		list( $find, $replace ) = self::get_taxonomy_placeholder_replacements( $post, $primary_term_ids );
		return trailingslashit( trim( str_replace( $find, $replace, $permalink ) ) );
	}

	/**
	 * Build permalink for a post of a custom post types.
	 *
	 * @param $url
	 * @param $post
	 * @param $leavename
	 * @param $sample
	 *
	 * @return mixed|string
	 */
	public static function post_type_link( $url, $post, $leavename, $sample ) {

		$post_types = self::supported_post_types();
		if ( ! isset( $post_types[ $post->post_type ] ) ) {
			/**
			 * Leave the urls of the unsupported post types intact
			 */
			return $url;
		}

		/**
		 * Find and replace these values in the permalink_structure
		 */
		$find = array(
			'%year%',
			'%monthnum%',
			'%day%',
			'%hour%',
			'%minute%',
			'%second%',
			'%post_id%',
			'%postname%',
			'%author%'
		);
		$replace   = explode( '|', get_the_date( 'Y|m|d|H|i|s', $post->ID ) );
		$replace[] = $post->ID;
		$replace[] = $post->post_name;
		$replace[] = get_the_author_meta( 'user_nicename', $post->post_author );

		$primary_term_ids = self::get_primary_term_ids( $post->ID );

		list( $tax_find, $tax_replace ) = self::get_taxonomy_placeholder_replacements( $post, $primary_term_ids );
		$find    = array_merge( $find, $tax_find );
		$replace = array_merge( $replace, $tax_replace );

		if ( ! $leavename ) {
			$find[]    = '%' . $post->post_type . '%';
			$replace[] = $post->post_name;
		}

		/**
		 * This works well with CPT UI and Custom Post Type Permalinks
		 */
		$find[]    = '%' . $post->post_type . '_slug%';
		$replace[] = str_replace( $tax_find, $tax_replace, $post_types[ $post->post_type ]->rewrite[ 'slug' ] );

		/**
		 * Base the url on the post_type slug, not the $url.
		 */
		global $wp_rewrite;
		$post_link = $wp_rewrite->get_extra_permastruct( $post->post_type );
		$slug      = $post_types[ $post->post_type ]->rewrite[ 'slug' ];
		$slug      = trailingslashit( site_url( trailingslashit( $slug ) . '%postname%' ) );
		$new_url   = str_replace( $find, $replace, $post_link );
		$new_url   = trailingslashit( trim( site_url( $new_url ) ) );

		return $new_url;
	}

	/**
	 * Get a term to use in place of a taxonomy placeholder
	 *
	 * @param $post
	 * @param array $primary_term_ids
	 *
	 * @return array
	 */
	protected static function get_taxonomy_placeholder_replacements( $post, $primary_term_ids = array() ){

		$find = array();
		$replace = array();

		foreach ( self::post_type_taxonomies( $post->post_type ) as $info ) {
			/**
			 * try to replace custom %taxonomy% placeholders with corresponding primary terms
			 */
			$taxonomy = $info->name;
			$term_id   = 0;
			if ( !empty( $primary_term_ids[ $taxonomy ] ) ) {
				$term_id = $primary_term_ids[ $taxonomy ];
			} else {
				/**
				 * Get all terms assigned to the current post
				 */
				$terms = get_the_terms( $post->ID, $taxonomy );
				if ( is_wp_error( $terms ) ) {
					/**
					 * Normally, this should not happen!
					 */
					error_log( $terms->get_error_message() );
					continue;
				}
				if ( ! empty( $terms ) ) {
					/**
					 * will simply be the term with the lowest termId
					 */
					$term_id = $terms[ 0 ]->term_id;
				} else {
					/**
					 * get the default term from the stored options (or from filter)
					 */
					$term_id = self::get_default_term_id( $taxonomy );
				}
			}
			if ( empty( $term_id ) ) {
				/**
				 * Found no term -- two strategies, should probably be surfaced to the settings page:
				 *  #1. just remove the placeholder and the preceding slash
				 *  #2. replace with a default string built from the taxonomy name, e.g. '/no-product-type'
				 *
				 */
				/**
				 * Stategy #1
				 */
//				$find[]    = '/%' . $taxonomy . '%';
//				$replace[] = '';
				/**
				 * Strategy #2
				 */
				$find[]    = '%' . $taxonomy . '%';
				$replace[] = str_replace( '%s', str_replace( '_', '-', $taxonomy ), self::DEFAULT_TERM_SUBSTITUTE );
			} else {
				$find[]    = '%' . $taxonomy . '%';
				$replace[] = self::get_taxonomy_parents( $term_id, $taxonomy, false, '/', true );
			}
		}

		return array( $find, $replace );
	}

	/**
	 * Get the name of default category term.
	 *
	 * @uses get_term
	 * @uses get_option
	 *
	 * @return string
	 */
	static function get_default_term_id( $taxonomy ) {
		static $terms;
		if ( !isset( $terms[ $taxonomy ] ) ) {
			if( 'category' === $taxonomy ){
				$term_id = get_option( 'default_category' );
			} else {
				$term_id = 0;
			}
			$terms[ $taxonomy ] = absint( apply_filters( 'stmpc/get_default_term_id', $term_id, $taxonomy ));
		}
		return $terms[ $taxonomy ];
	}

	/**
	 * Retrieve category parents with separator for general taxonomies.
	 * Modified version of get_category_parents()
	 *
	 * @param int $id Category ID.
	 * @param string $taxonomy Optional, default is 'category'.
	 * @param bool $link Optional, default is false. Whether to format with link.
	 * @param string $separator Optional, default is '/'. How to separate categories.
	 * @param bool $nicename Optional, default is false. Whether to use nice name for display.
	 * @param array $visited Optional. Already linked to categories to prevent duplicates.
	 * @return string
	 */
	protected static function get_taxonomy_parents( $id, $taxonomy = 'category', $link = false, $separator = '/', $nicename = false, $visited = array() ) {

		$chain  = array();
		$parent = get_term( $id, $taxonomy );

		if ( is_wp_error( $parent ) ) {
			return '-';
		}

		$name = $nicename ? $parent->slug : $parent->name;

		if ( $parent->parent && ( $parent->parent != $parent->term_id ) && ! in_array( $parent->parent, $visited ) ) {
			$visited[] = $parent->parent;
			$chain[]   = self::get_taxonomy_parents( $parent->parent, $taxonomy, $link, $separator, $nicename, $visited );
		}

		if ( $link ) {
			$link_title = sprintf( __( 'View all posts in %s', 'stmpc' ), $parent->name );

			$chain[] = sprintf('<a href="%s" title="%s">%s</a>',
				esc_url( get_term_link( $parent, $taxonomy ) ),
				esc_attr( $link_title ),
				$name
			);
		} else {
			$chain[] = $name;
		}

		return implode( $separator, $chain );
	}

	/**
	 * Get a list of supported post types. By default, ALL custom post types and 'post' are enabled.
	 *
	 * @return WP_Post_Type[]
	 */
	public static function supported_post_types() {
		static $post_types;

		if ( ! $post_types ) {
			$post_types = get_post_types( array( '_builtin' => false, 'public' => true ), 'objects' );

			/**
			 * Make sure regular posts are supported.
			 * Note, this can be still prevented via the filter below.
			 */
			if ( empty( $post_types ) ) {
				$post_types = array( get_post_type_object( 'post' ));
			} else {
				array_unshift( $post_types, get_post_type_object( 'post' ) );
			}

			$post_types = apply_filters( 'stmpc/supported_post_types', $post_types );
		}

		return $post_types;
	}

	/**
	 * Get only the names names of all supported post types
	 *
	 * @return string[]
	 */
	public static function supported_post_type_names(){

		return array_keys( self::supported_post_types() );
	}

	/**
	 * Get the list of WP_Taxonomy objects representing taxonomies this plugin is supposed to support.
	 *
	 * @return WP_Taxonomy[] By default, returns all public hierarchical taxonomies
	 */
	public static function supported_taxonomies() {

		static $taxonomies;

		if ( ! is_array( $taxonomies ) ) {
			$taxonomies = get_taxonomies( array( 'public' => 1, 'hierarchical' => 1 ), 'objects' );
			$taxonomies = array_filter(
				$taxonomies,
				function ( $a ) {
					return !!$a->rewrite;
				}
			);
			$taxonomies = apply_filters( 'stmpc/supported_taxonomies', $taxonomies );
		}

		return $taxonomies;
	}

	/**
	 * Get the names of the supported taxonomies.
	 *
	 * @return string[]
	 */
	public static function supported_taxonomy_names(){
		return apply_filters( 'stmpc/supported_taxonomy_names', array_keys( self::supported_taxonomies() ));
	}


	/**
	 * Get a list of taxonomies that are enabled for the specified post type.
	 *
	 * @param string $post_type_name
	 *
	 * @return WP_Taxonomy[]
	 */
	public static function post_type_taxonomies( $post_type_name ){

		return array_filter(
			self::supported_taxonomies(),
			function( $taxonomy ) use ( $post_type_name ) {
				return in_array( $post_type_name, $taxonomy->object_type );
			}
		);

	}

	/**
	 * Get stored primary terms of the specified post
	 *
	 * @param int $post_id
	 *
	 * @return array
	 */
	public static function get_primary_term_ids( $post_id ) {

		$primary_term_ids = get_post_meta( $post_id, self::META_FIELD_NAME, true );

		if ( empty( $primary_term_ids ) ) {
			return array();
		}

		/**
		 * the value is stored as a JSON string
		 */
		if ( is_string( $primary_term_ids ) ) {
			$primary_term_ids = json_decode( $primary_term_ids, $assoc_array = true );
		}

		/**
		 * if no taxonomy is specified, assume the term id is from "category" taxonomy
		 */
		if ( is_numeric( $primary_term_ids ) ) {
			$primary_term_ids = array( 'category' => $primary_term_ids );
		}

		return $primary_term_ids;
	}

	/**
	 * NOT USED.
	 *
	 * Set the primary term ids for the specified post.
	 *
	 * @param int $post_id
	 * @param array|null $primary_term_ids
	 */
//	public static function set_primary_term_ids( $post_id, $primary_term_ids = null ) {
//
//		if ( !empty( $primary_term_ids ) && is_array( $primary_term_ids ) ) {
//			update_post_meta( $post_id, self::META_FIELD_NAME, json_encode( $primary_term_ids ) );
//		} else {
//			delete_post_meta( $post_id, self::META_FIELD_NAME );
//		}
//	}

} //class

endif;

ST_Modern_Primary_Category::on_load();
