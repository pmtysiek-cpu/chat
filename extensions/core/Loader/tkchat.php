<?php

namespace IPS\tkchat\extensions\core\Loader;

use IPS\Extensions\LoaderAbstract;
use IPS\Http\Url;
use IPS\Output;
use IPS\Theme;
use function defined;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * @brief	Loader extension: tkchat
 * IMPORTANT: Most methods in this extension are NOT called
 * on Database Pages (@see \IPS\cms\Databases\Dispatcher)
 * The above dispatcher class bypasses CSS and JS intentionally.
 * Redirects can be handled in a Raw HTML block for now.
 */
class tkchat extends LoaderAbstract
{
	public function js(): array
    {
        return [ Output::i()->js( 'front_tkchat10.js', 'tkchat', 'interface' ) ];
    }
	public function css(): array
    {
        return [ Theme::i()->css( 'front_tkchat.css', 'tkchat', 'front' ) ];
    }
}