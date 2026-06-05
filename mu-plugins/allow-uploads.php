<?php
/**
 * Extends the allowed upload types and is loaded automatically as a must-use plugin.
 */
add_filter( 'upload_mimes', function ( $mimes ) {
    $mimes['md']   = 'text/markdown';
    $mimes['yaml'] = 'text/yaml';
    $mimes['yml']  = 'text/yaml';
    $mimes['json'] = 'application/json';
    return $mimes;
} );

// finfo detects .md/.yaml/.yml as text/plain — override so WP's content check
// doesn't reject files whose detected MIME differs from the registered one.
add_filter( 'wp_check_filetype_and_ext', function ( $data, $file, $filename, $mimes ) {
    if ( ! $data['ext'] && ! $data['type'] ) {
        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        $map = [
            'md'   => [ 'ext' => 'md',   'type' => 'text/markdown' ],
            'yaml' => [ 'ext' => 'yaml', 'type' => 'text/yaml' ],
            'yml'  => [ 'ext' => 'yml',  'type' => 'text/yaml' ],
            'json' => [ 'ext' => 'json', 'type' => 'application/json' ],
        ];
        if ( isset( $map[ $ext ] ) ) {
            $data['ext']             = $map[ $ext ]['ext'];
            $data['type']            = $map[ $ext ]['type'];
            $data['proper_filename'] = $filename;
        }
    }
    return $data;
}, 10, 4 );
