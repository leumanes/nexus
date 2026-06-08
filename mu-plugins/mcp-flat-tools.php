<?php
/*
Plugin Name: MCP Flat Per-Ability Tools
Description: Exposes each Nexus ability as its own first-class (flat) MCP tool and
             removes the generic execute-ability wrapper. Flat tools declare their
             real parameters (id, post_id, content, ...) as typed top-level
             properties, so LLM tool-calling fills them reliably. The generic
             `mcp-adapter/execute-ability` wrapper, by contrast, takes a single
             schema-less `parameters` object — which some models (e.g. grok-4.3)
             serialize as `{}`, producing empty-arg calls that fail validation and
             cascade into false "server unreachable" circuit-breaker trips. Since
             every ability is covered by a typed flat tool, the wrapper is pure
             footgun and is dropped here. The two introspection wrappers
             (discover-abilities, get-ability-info) are kept — they take no
             free-form object parameter and are useful for ability discovery.

             Tools are now derived dynamically from wp_get_abilities() (see filter).
             This eliminates the hardcoded mirror list, auto-picks up new public
             abilities, and makes execute-ability removal timing-independent.
*/
add_filter( 'mcp_adapter_default_server_config', function ( array $config ) {
	$existing = isset( $config['tools'] ) && is_array( $config['tools'] )
		? $config['tools']
		: array();

	// Derive flat tools from the live ability registry instead of a hand-maintained
	// mirror list: every ability flagged mcp.public whose mcp.type is 'tool' (the
	// default) is exposed as its own flat tool. New public abilities are picked up
	// automatically — no second list to keep in sync. Mirrors the adapter's own
	// discover_abilities_by_type(); wp_get_abilities() is already populated when this
	// filter fires (the factory uses it in the same path).
	$flat_tools = array();
	foreach ( wp_get_abilities() as $ability ) {
		$meta = $ability->get_meta();
		if ( ! ( $meta['mcp']['public'] ?? false ) ) {
			continue;
		}
		if ( ( $meta['mcp']['type'] ?? 'tool' ) !== 'tool' ) {
			continue; // resources / prompts are exposed through their own config keys
		}
		$flat_tools[] = $ability->get_name();
	}

	$tools = array_values( array_unique( array_merge( $existing, $flat_tools ) ) );

	// Drop the generic execute-ability wrapper (the schema-less `parameters` footgun).
	// NOTE: done AFTER the merge. The registry can now surface execute-ability itself,
	// so the original pre-merge filter on $existing alone would no longer remove it.
	// discover-abilities / get-ability-info are kept.
	$config['tools'] = array_values( array_filter( $tools, function ( $name ) {
		return $name !== 'mcp-adapter/execute-ability';
	} ) );

	return $config;
} );
