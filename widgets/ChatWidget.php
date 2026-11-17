<?php
/**
 * @brief		ChatWidget Widget
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	tkchat
 * @since		16 Jun 2026
 */

namespace IPS\tkchat\widgets;

use IPS\Helpers\Form;
use IPS\Theme;
use IPS\Db;
use function defined;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * ChatWidget Widget
 */
class ChatWidget extends \IPS\Widget
{
	/**
	 * @brief	Widget Key
	 */
	public string $key = 'ChatWidget';
	
	/**
	 * @brief	App
	 */
	public string $app = 'tkchat';
	
	/**
	 * Initialise this widget
	 *
	 * @return void
	 */ 
	public function init() : void
	{
		// Use this to perform any set up and to assign a template that is not in the following format:
		// $this->template( array( \IPS\Theme::i()->getTemplate( 'widgets', $this->app, 'front' ), $this->key ) );
		// Note: parent::init() will set a default template if you haven't, so make sure your template is defined BEFORE the parent is called.

		parent::init();
	}
	
	/**
	 * Specify widget configuration
	 *
	 * @param	null|Form	$form	Form object
	 * @return	Form
	 */
	public function configuration( Form &$form=null ): Form
	{
 		$form = parent::configuration( $form );

 		// $form->add( new \IPS\Helpers\Form\XXXX( .... ) );
 		return $form;
 	} 
 	
 	 /**
 	 * Ran before saving widget configuration
 	 *
 	 * @param	array	$values	Values from form
 	 * @return	array
 	 */
 	public function preConfig( array $values ): array
 	{
 		return $values;
 	}

	/**
	 * Render a widget
	 *
	 * @return	string
	 */
public function render(): string
    {
// Ładowanie szablonu z lokalizacji dev/html/front/widget/chatContainer.phtml
		return $this->output( \IPS\Theme::i()->getTemplate( 'widget', 'tkchat', 'front' )->chatContainer() );
    }
}