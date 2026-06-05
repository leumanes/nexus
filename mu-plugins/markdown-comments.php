<?php
/**
 * Render markdown in comment content at display time.
 * Raw markdown is stored in the database; HTML is produced on output only.
 *
 * Comments inserted via the posts/add-comment MCP ability carry _is_markdown=1
 * meta. Comments without that meta (e.g. entered through WP admin) are treated
 * as plain text and receive standard WordPress formatting instead.
 */

require_once __DIR__ . '/Parsedown.php';

// wptexturize converts -- to &#8211;, " to curly-quote entities, etc. — Parsedown then
// double-escapes the & to &amp;, so &#8211; renders as literal text instead of an em dash.
// wpautop adds spurious <p> tags around Parsedown's already-wrapped output.
// Both are removed from the filter chain here; non-markdown comments re-apply them manually.
remove_filter('comment_text', 'wptexturize', 10);
// WP core registers wpautop on comment_text at priority 30, not 10 — must match exactly.
remove_filter('comment_text', 'wpautop',     30);

add_filter('comment_text', function (string $text, ?WP_Comment $comment): string {
    // Route on '1' (MCP markdown comment); everything else — no meta, admin UI,
    // WP-CLI, REST — gets standard WordPress formatting.
    if ( ! $comment || get_comment_meta( (int) $comment->comment_ID, '_is_markdown', true ) !== '1' ) {
        return wpautop( wptexturize( $text ) );
    }

    static $pd = null;
    if ($pd === null) {
        $pd = new Parsedown();
        // Safe mode off — content passes through wp_kses_post on write.
        // Output is additionally sanitized with wp_kses_post below to guard
        // against content inserted outside the MCP ability (e.g. WP-CLI, REST).
        $pd->setSafeMode(false);
    }
    return wp_kses_post( $pd->text($text) );
// Priority 8: must run before make_clickable (core, priority 9) which corrupts markdown link syntax.
}, 8, 2);
