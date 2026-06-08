<?php
/**
 * Registers WordPress Abilities for reading and writing posts and comments via MCP.
 * All abilities have mcp.public=true so they are visible through the default MCP server.
 */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'posts', [
		'label'       => 'Posts',
		'description' => 'Abilities for reading and writing WordPress posts and comments.',
	] );
	wp_register_ability_category( 'media', [
		'label'       => 'Media',
		'description' => 'Abilities for uploading files to the WordPress media library.',
	] );
} );

add_action( 'wp_abilities_api_init', function () {

	// ── posts/list ────────────────────────────────────────────────────────────
	wp_register_ability( 'posts/list', [
		'label'       => 'List Posts',
		'description' => 'List published posts. Optionally filter by category slug, tag, or search term.',
		'category'    => 'posts',
		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'category' => [
					'type'        => 'string',
					'description' => 'Filter by category slug (e.g. "tasks"). Omit for all categories.',
				],
				'tag' => [
					'type'        => 'string',
					'description' => 'Filter by tag slug (e.g. "in-progress"). Omit for all tags.',
				],
				'search' => [
					'type'        => 'string',
					'description' => 'Search term matched against post title and content.',
				],
				'slug' => [
					'type'        => 'string',
					'description' => 'Exact post slug — the last path segment of a Nexus URL (e.g. "my-post-title" from ".../my-post-title/"). Use this to resolve a URL to its post; it matches the slug exactly, unlike "search" which only matches title/content.',
				],
				'per_page' => [
					'type'        => 'integer',
					'description' => 'Max results to return (default 20, max 100).',
					'default'     => 20,
				],
			],
		],
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'read' ),
		'execute_callback'    => function ( $input ) {
			$args = [
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( (int) ( $input['per_page'] ?? 20 ), 100 ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			];
			if ( ! empty( $input['category'] ) ) $args['category_name'] = $input['category'];
			if ( ! empty( $input['tag'] ) )      $args['tag']           = $input['tag'];
			if ( ! empty( $input['search'] ) ) $args['s']    = $input['search'];
			if ( ! empty( $input['slug'] ) )   $args['name'] = sanitize_title( $input['slug'] );

			return [ 'posts' => array_map( '_post_ability_summary', get_posts( $args ) ) ];
		},
		'meta' => [
			'mcp'         => [ 'public' => true ],
			'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
		],
	] );

	// ── posts/get ─────────────────────────────────────────────────────────────
	wp_register_ability( 'posts/get', [
		'label'       => 'Get Post',
		'description' => 'Get a single post by ID, including full content and all approved comments.',
		'category'    => 'posts',
		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'id' => [ 'type' => 'integer', 'description' => 'Post ID.' ],
			],
			'required' => [ 'id' ],
		],
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'read' ),
		'execute_callback'    => function ( $input ) {
			$post = get_post( (int) $input['id'] );
			if ( ! $post || $post->post_status !== 'publish' ) {
				return new WP_Error( 'not_found', 'Post not found.' );
			}

			$raw_comments = get_comments( [ 'post_id' => $post->ID, 'status' => 'approve', 'order' => 'ASC' ] );
			$comments = array_map( fn( $c ) => [
				'id'      => (int) $c->comment_ID,
				'author'  => $c->comment_author,
				'content' => $c->comment_content,
				'date'    => $c->comment_date,
			], $raw_comments );

			$summary            = _post_ability_summary( $post );
			$summary['content'] = $post->post_content;
			$summary['comments'] = $comments;
			return $summary;
		},
		'meta' => [
			'mcp'         => [ 'public' => true ],
			'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
		],
	] );

	// ── posts/create ──────────────────────────────────────────────────────────
	wp_register_ability( 'posts/create', [
		'label'       => 'Create Post',
		'description' => 'Create a new published post.',
		'category'    => 'posts',
		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'title'    => [ 'type' => 'string', 'description' => 'Post title.' ],
				'content'  => [ 'type' => 'string', 'description' => 'Post body.' ],
				'category' => [ 'type' => 'string', 'description' => 'Category slug (e.g. "tasks"). Optional.' ],
				'tags'     => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Tag slugs to apply (e.g. ["backlog"]).',
				],
			],
			'required' => [ 'title' ],
		],
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'publish_posts' ),
		'execute_callback'    => function ( $input ) {
			$cat_ids = [];
			if ( ! empty( $input['category'] ) ) {
				$cat = get_category_by_slug( $input['category'] );
				if ( $cat ) $cat_ids[] = $cat->term_id;
			}

			$post_id = wp_insert_post( [
				'post_title'    => sanitize_text_field( $input['title'] ),
				'post_content'  => wp_kses_post( $input['content'] ?? '' ),
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_category' => $cat_ids,
				'tags_input'    => $input['tags'] ?? [],
			], true );

			if ( is_wp_error( $post_id ) ) return $post_id;
			return [ 'id' => $post_id, 'url' => get_permalink( $post_id ) ];
		},
		'meta' => [
			'mcp'         => [ 'public' => true ],
			'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
		],
	] );

	// ── posts/update ──────────────────────────────────────────────────────────
	wp_register_ability( 'posts/update', [
		'label'       => 'Update Post',
		'description' => 'Update a post\'s title, content, or tags. Only provided fields are changed.',
		'category'    => 'posts',
		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'id'      => [ 'type' => 'integer', 'description' => 'Post ID.' ],
				'title'   => [ 'type' => 'string', 'description' => 'New title (optional).' ],
				'content' => [ 'type' => 'string', 'description' => 'New content (optional).' ],
				'tags'    => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Replaces all current tags. Omit to leave tags unchanged.',
				],
			],
			'required' => [ 'id' ],
		],
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'edit_posts' ),
		'execute_callback'    => function ( $input ) {
			$post_id = (int) $input['id'];
			$post    = get_post( $post_id );
			if ( ! $post || $post->post_type !== 'post' ) return new WP_Error( 'not_found', 'Post not found.' );

			$update = [ 'ID' => $post_id ];
			if ( isset( $input['title'] ) )   $update['post_title']   = sanitize_text_field( $input['title'] );
			if ( isset( $input['content'] ) ) $update['post_content'] = wp_kses_post( $input['content'] );
			if ( count( $update ) > 1 ) {
				$result = wp_update_post( $update, true );
				if ( is_wp_error( $result ) ) return $result;
			}

			if ( isset( $input['tags'] ) ) {
				wp_set_post_tags( $post_id, $input['tags'] );
			}

			return _post_ability_summary( get_post( $post_id ) );
		},
		'meta' => [
			'mcp'         => [ 'public' => true ],
			'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
		],
	] );

	// ── posts/add-comment ─────────────────────────────────────────────────────
	wp_register_ability( 'posts/add-comment', [
		'label'       => 'Add Comment',
		'description' => 'Add a comment to a post. Pass "author" to set the display name (e.g. the agent\'s identity); defaults to the authenticated WordPress user.',
		'category'    => 'posts',
		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'post_id' => [ 'type' => 'integer', 'description' => 'ID of the post to comment on.' ],
				'content' => [ 'type' => 'string',  'description' => 'Comment text.' ],
				'author'  => [ 'type' => 'string',  'description' => 'Display name for the comment author (e.g. "coder-agent"). Defaults to the authenticated user\'s display name.' ],
			],
			'required' => [ 'post_id', 'content' ],
		],
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'read' ),
		'execute_callback'    => function ( $input ) {
			$post_id = (int) $input['post_id'];

			$post = get_post( $post_id );
			if ( ! $post || $post->post_type !== 'post' || $post->post_status !== 'publish' ) {
				return new WP_Error( 'not_found', 'Post not found.' );
			}

			if ( ! comments_open( $post_id ) ) {
				return new WP_Error( 'comments_closed', 'Comments are closed for this post.' );
			}

			$user   = wp_get_current_user();
			$author = ! empty( $input['author'] ) ? sanitize_text_field( $input['author'] ) : $user->display_name;

			// The comment is owned by the authenticated user UNLESS the author
			// string resolves to a real (non-admin) account — then attribute it to
			// that account so WordPress computes a distinct avatar and genuine
			// ownership per agent identity. Agents pass their login (e.g.
			// "coder-agent"); $author stays the passed string for display and for
			// get-comment author filtering.
			//
			// Design note (per code review): this branch uses a *single shared service
			// account* for all MCP calls (app:*-agent passwords). The `author` param
			// is the mechanism to pick per-agent identity/avatar. The only guard is
			// the manage_options check on the resolved account. If the model ever
			// changes to per-agent logins, add `$resolved->ID === $user->ID ||
			// current_user_can('edit_users')` here.
			$author_user_id = $user->ID;
			$author_email   = $user->user_email;

			$resolved = ! empty( $input['author'] ) ? get_user_by( 'login', $author ) : false;
			if ( $resolved && ! user_can( $resolved, 'manage_options' ) ) {
				$author_user_id = $resolved->ID;
				$author_email   = $resolved->user_email;
			} elseif ( $author !== $user->display_name ) {
				// Not an attributable account — block impersonating another
				// registered user's display name while staying owned by $user.
				global $wpdb;
				$taken = $wpdb->get_var( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->users} WHERE display_name = %s AND ID != %d",
					$author, $user->ID
				) );
				if ( $taken ) {
					return new WP_Error( 'author_conflict', 'That author name belongs to a registered user.' );
				}
			}

			$id = wp_insert_comment( [
				'comment_post_ID'      => $post_id,
				'comment_content'      => wp_kses_post( $input['content'] ),
				'comment_author'       => $author,
				'comment_author_email' => $author_email,
				'user_id'              => $author_user_id,
				'comment_approved'     => ( get_option( 'comment_moderation' ) || get_option( 'comment_previously_approved' ) ) ? 0 : 1,
			] );

			if ( ! $id ) return new WP_Error( 'insert_failed', 'Failed to insert comment.' );

			add_comment_meta( $id, '_is_markdown', '1', true );

			$comment = get_comment( $id );
			return [ 'id' => (int) $id, 'author' => $author, 'date' => $comment->comment_date ];
		},
		'meta' => [
			'mcp'         => [ 'public' => true ],
			'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
		],
	] );

	// ── posts/get-comment ─────────────────────────────────────────────────────
	wp_register_ability( 'posts/get-comment', [
		'label'       => 'Get Comment',
		'description' => 'Get a single comment by ID.',
		'category'    => 'posts',
		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'id' => [ 'type' => 'integer', 'description' => 'Comment ID.' ],
			],
			'required' => [ 'id' ],
		],
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'read' ),
		'execute_callback'    => function ( $input ) {
			$comment = get_comment( (int) $input['id'] );
			if ( ! $comment || ! in_array( $comment->comment_approved, [ '1', 'approve' ], true ) ) {
				return new WP_Error( 'not_found', 'Comment not found.' );
			}
			return [
				'id'      => (int) $comment->comment_ID,
				'post_id' => (int) $comment->comment_post_ID,
				'author'  => $comment->comment_author,
				'content' => $comment->comment_content,
				'date'    => $comment->comment_date,
			];
		},
		'meta' => [
			'mcp'         => [ 'public' => true ],
			'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
		],
	] );

	// ── posts/get-latest-comment ──────────────────────────────────────────────
	wp_register_ability( 'posts/get-latest-comment', [
		'label'       => 'Get Latest Comment',
		'description' => 'Get the most recent comment site-wide, optionally filtered by author display name. Useful for one agent to read the last comment left by another.',
		'category'    => 'posts',
		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'author' => [ 'type' => 'string', 'description' => 'Filter by comment author display name (e.g. "coder-agent"). Omit for any author.' ],
			],
		],
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'read' ),
		'execute_callback'    => function ( $input ) {
			global $wpdb;

			$sql  = "SELECT c.* FROM {$wpdb->comments} c
			         INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
			         WHERE c.comment_approved NOT IN ('spam', 'trash')
			         AND p.post_status = 'publish'";
			$args = [];

			if ( ! empty( $input['author'] ) ) {
				$sql   .= ' AND c.comment_author = %s';
				$args[] = $input['author'];
			}

			$sql .= ' ORDER BY c.comment_date DESC LIMIT 1';

			$comment = $args
				? $wpdb->get_row( $wpdb->prepare( $sql, ...$args ) )
				: $wpdb->get_row( $sql );

			if ( ! $comment ) {
				return new WP_Error( 'not_found', 'No comment found.' );
			}

			return [
				'id'      => (int) $comment->comment_ID,
				'post_id' => (int) $comment->comment_post_ID,
				'author'  => $comment->comment_author,
				'content' => $comment->comment_content,
				'date'    => $comment->comment_date,
				'status'  => $comment->comment_approved,
			];
		},
		'meta' => [
			'mcp'         => [ 'public' => true ],
			'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
		],
	] );

	// ── posts/update-comment ──────────────────────────────────────────────────
	wp_register_ability( 'posts/update-comment', [
		'label'       => 'Update Comment',
		'description' => 'Edit the content and/or author display name of an existing comment.',
		'category'    => 'posts',
		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'id'      => [ 'type' => 'integer', 'description' => 'Comment ID to update.' ],
				'content' => [ 'type' => 'string',  'description' => 'New comment text (optional).' ],
				'author'  => [ 'type' => 'string',  'description' => 'New author display name (optional).' ],
			],
			'required' => [ 'id' ],
		],
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'moderate_comments' ),
		'execute_callback'    => function ( $input ) {
			$id = (int) $input['id'];
			if ( ! get_comment( $id ) ) return new WP_Error( 'not_found', 'Comment not found.' );

			$update = [ 'comment_ID' => $id ];
			if ( isset( $input['content'] ) ) $update['comment_content'] = wp_kses_post( $input['content'] );
			if ( isset( $input['author'] ) ) {
				$author = sanitize_text_field( $input['author'] );
				$user   = wp_get_current_user();
				if ( $author !== $user->display_name ) {
					global $wpdb;
					$taken = $wpdb->get_var( $wpdb->prepare(
						"SELECT ID FROM {$wpdb->users} WHERE display_name = %s AND ID != %d",
						$author, $user->ID
					) );
					if ( $taken ) {
						return new WP_Error( 'author_conflict', 'That author name belongs to a registered user.' );
					}
				}
				$update['comment_author'] = $author;
			}

			if ( count( $update ) === 1 ) return new WP_Error( 'no_fields', 'Provide at least one field to update.' );

			$result = wp_update_comment( $update, true );
			if ( is_wp_error( $result ) ) return $result;

			$comment = get_comment( $id );
			return [
				'id'      => $id,
				'author'  => $comment->comment_author,
				'content' => $comment->comment_content,
				'date'    => $comment->comment_date,
			];
		},
		'meta' => [
			'mcp'         => [ 'public' => true ],
			'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
		],
	] );

	// ── media/list ────────────────────────────────────────────────────────────
	wp_register_ability( 'media/list', [
		'label'       => 'List Media',
		'description' => 'List files in the media library. Use "search" to filter by filename or title.',
		'category'    => 'media',
		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'search'   => [ 'type' => 'string',  'description' => 'Search term matched against filename and title.' ],
				'per_page' => [ 'type' => 'integer', 'description' => 'Max results (default 20, max 100).', 'default' => 20 ],
			],
		],
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'upload_files' ),
		'execute_callback'    => function ( $input ) {
			$args = [
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => max( 1, min( (int) ( $input['per_page'] ?? 20 ), 100 ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			];
			if ( ! empty( $input['search'] ) ) $args['s'] = $input['search'];

			return [ 'media' => array_map( fn( $a ) => [
				'id'        => $a->ID,
				'title'     => $a->post_title,
				'filename'  => wp_basename( get_attached_file( $a->ID ) ),
				'url'       => wp_get_attachment_url( $a->ID ),
				'mime_type' => $a->post_mime_type,
				'date'      => $a->post_date,
			], get_posts( $args ) ) ];
		},
		'meta' => [
			'mcp'         => [ 'public' => true ],
			'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
		],
	] );

	// ── media/get ─────────────────────────────────────────────────────────────
	wp_register_ability( 'media/get', [
		'label'       => 'Get Media File',
		'description' => 'Retrieve a media file by attachment ID. Returns content as a plain string for text files, or base64 for binary files.',
		'category'    => 'media',
		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'id' => [ 'type' => 'integer', 'description' => 'Attachment ID.' ],
			],
			'required' => [ 'id' ],
		],
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'upload_files' ),
		'execute_callback'    => function ( $input ) {
			$id   = (int) $input['id'];
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'attachment' ) {
				return new WP_Error( 'not_found', 'Attachment not found.' );
			}

			$path = get_attached_file( $id );
			if ( ! $path || ! file_exists( $path ) ) {
				return new WP_Error( 'file_missing', 'File not found on disk.' );
			}

			// Ensure file is within the WordPress upload directory (prevents path traversal).
			$upload_dir = wp_upload_dir();
			$basedir    = realpath( $upload_dir['basedir'] );
			$real_path  = realpath( $path );
			if ( $real_path === false || $basedir === false ||
			     ! str_starts_with( $real_path, $basedir . DIRECTORY_SEPARATOR ) ) {
				return new WP_Error( 'forbidden', 'File is outside the upload directory.' );
			}

			// Reject files over 50 MB to prevent memory exhaustion.
			$max_bytes = 50 * 1024 * 1024;
			if ( filesize( $real_path ) > $max_bytes ) {
				return new WP_Error( 'file_too_large', 'File exceeds the 50 MB read limit.' );
			}

			$mime    = $post->post_mime_type;
			$is_text = str_starts_with( $mime, 'text/' ) || in_array( $mime, [ 'application/json' ], true );
			$raw     = file_get_contents( $real_path );

			return [
				'id'        => $id,
				'title'     => $post->post_title,
				'filename'  => wp_basename( $path ),
				'mime_type' => $mime,
				'encoding'  => $is_text ? 'text' : 'base64',
				'content'   => $is_text ? $raw : base64_encode( $raw ),
				'url'       => wp_get_attachment_url( $id ),
			];
		},
		'meta' => [
			'mcp'         => [ 'public' => true ],
			'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
		],
	] );

	// ── media/upload ──────────────────────────────────────────────────────────
	wp_register_ability( 'media/upload', [
		'label'       => 'Upload Media',
		'description' => 'Upload a file to the WordPress media library. Use encoding="text" for plain-text files (md, yaml, json) or encoding="base64" for binary files (images, PDF).',
		'category'    => 'media',
		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'filename' => [ 'type' => 'string', 'description' => 'Filename including extension (e.g. "notes.md", "photo.jpg").' ],
				'content'  => [ 'type' => 'string', 'description' => 'File content — plain string for encoding=text, base64-encoded string for encoding=base64.' ],
				'encoding' => [
					'type'        => 'string',
					'description' => '"text" for plain-text files (default), "base64" for binary files.',
					'enum'        => [ 'text', 'base64' ],
					'default'     => 'text',
				],
				'title'       => [ 'type' => 'string', 'description' => 'Media title (optional, defaults to filename).' ],
				'description' => [ 'type' => 'string', 'description' => 'Media description (optional).' ],
			],
			'required' => [ 'filename', 'content' ],
		],
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'upload_files' ),
		'execute_callback'    => function ( $input ) {
			$filename = sanitize_file_name( $input['filename'] );
			$encoding = $input['encoding'] ?? 'text';

			if ( $encoding === 'base64' ) {
				$content = base64_decode( $input['content'], true );
				if ( $content === false ) {
					return new WP_Error( 'decode_failed', 'Failed to decode base64 content.' );
				}
			} else {
				$content = $input['content'];
			}

			$upload = wp_upload_bits( $filename, null, $content );
			if ( $upload['error'] ) {
				return new WP_Error( 'upload_failed', $upload['error'] );
			}

			$filetype  = wp_check_filetype( $filename );
			$attach_id = wp_insert_attachment( [
				'post_mime_type' => $filetype['type'],
				'post_title'     => sanitize_text_field( $input['title'] ?? pathinfo( $filename, PATHINFO_FILENAME ) ),
				'post_content'   => sanitize_textarea_field( $input['description'] ?? '' ),
				'post_status'    => 'inherit',
			], $upload['file'] );

			if ( is_wp_error( $attach_id ) ) return $attach_id;

			require_once ABSPATH . 'wp-admin/includes/image.php';
			wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $upload['file'] ) );

			return [ 'id' => $attach_id, 'url' => $upload['url'] ];
		},
		'meta' => [
			'mcp'         => [ 'public' => true ],
			'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
		],
	] );

} );

// ── shared helper ─────────────────────────────────────────────────────────────

function _post_ability_summary( WP_Post $post ): array {
	$tags = wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] );
	$cats = wp_get_post_categories( $post->ID, [ 'fields' => 'slugs' ] );
	return [
		'id'         => $post->ID,
		'title'      => $post->post_title,
		'categories' => $cats,
		'tags'       => $tags,
		'excerpt'    => wp_strip_all_tags( get_the_excerpt( $post ) ),
		'date'       => $post->post_date,
		'url'        => get_permalink( $post ),
	];
}
