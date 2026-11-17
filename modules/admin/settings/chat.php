<?php

namespace IPS\tkchat\modules\admin\settings;

use IPS\Dispatcher\Controller;
use IPS\Helpers\Form;
use IPS\Helpers\Form\CheckboxSet;
use IPS\Helpers\Form\Color;
use IPS\Helpers\Form\Number;
use IPS\Helpers\Form\Text;
use IPS\Member\Group;
use IPS\Output;
use IPS\Session;
use IPS\Settings;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class chat extends Controller
{
	public function execute() : void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'settings_manage' );
		parent::execute();
	}

	protected function manage() : void
	{
		$groups = array();
		foreach ( Group::groups() as $groupId => $group )
		{
			$groups[ $groupId ] = $group->name;
		}

		$chatGroups = Settings::i()->tkchat_chat_groups 
			? array_map( 'intval', explode( ',', Settings::i()->tkchat_chat_groups ) ) 
			: array();
		
		$moderateGroups = Settings::i()->tkchat_moderate_groups 
			? array_map( 'intval', explode( ',', Settings::i()->tkchat_moderate_groups ) ) 
			: array();
		
		$editGroups = Settings::i()->tkchat_edit_groups 
			? array_map( 'intval', explode( ',', Settings::i()->tkchat_edit_groups ) ) 
			: array();
		
		$deleteGroups = Settings::i()->tkchat_delete_groups 
			? array_map( 'intval', explode( ',', Settings::i()->tkchat_delete_groups ) ) 
			: array();
		
		$editOwnGroups = Settings::i()->tkchat_edit_own_groups 
			? array_map( 'intval', explode( ',', Settings::i()->tkchat_edit_own_groups ) ) 
			: array();

		$highlightGroups = Settings::i()->tkchat_highlight_groups 
			? array_map( 'intval', explode( ',', Settings::i()->tkchat_highlight_groups ) ) 
			: array();

		$editTimeLimit = Settings::i()->tkchat_edit_time_limit ? intval( Settings::i()->tkchat_edit_time_limit ) : 0;
		$antispamTime = Settings::i()->tkchat_antispam_time ? intval( Settings::i()->tkchat_antispam_time ) : 2;
		$maxMessageLength = Settings::i()->tkchat_max_message_length ? intval( Settings::i()->tkchat_max_message_length ) : 500;
		$pollingInterval = Settings::i()->tkchat_polling_interval ? intval( Settings::i()->tkchat_polling_interval ) : 3000;
		$klipyEndpoint = Settings::i()->tkchat_klipy_endpoint ?: 'https://api.klipy.com/v2';
		$klipyKey = Settings::i()->tkchat_klipy_key ?: '';

		$form = new Form( 'tkchat_settings', 'save' );
		
		// ── Uprawnienia dostępu ──────────────────────────────────────
		$form->addHeader( 'tkchat_header_access' );
		$form->addMessage( 'tkchat_access_info', 'ipsMessage ipsMessage_info' );
		
		$form->add( new CheckboxSet( 'tkchat_chat_groups', $chatGroups, false, array(
			'options'          => $groups,
			'unlimitedLang'    => 'tkchat_all_groups',
			'impliedUnlimited' => true
		) ) );
		
		// ── Uprawnienia moderacyjne ──────────────────────────────────
		$form->addHeader( 'tkchat_header_moderation' );
		$form->addMessage( 'tkchat_moderation_info', 'ipsMessage ipsMessage_info' );
		
		$form->add( new CheckboxSet( 'tkchat_moderate_groups', $moderateGroups, false, array(
			'options'          => $groups,
			'unlimitedLang'    => 'tkchat_none',
			'impliedUnlimited' => false
		) ) );
		
		$form->add( new CheckboxSet( 'tkchat_edit_groups', $editGroups, false, array(
			'options'          => $groups,
			'unlimitedLang'    => 'tkchat_none',
			'impliedUnlimited' => false
		) ) );
		
		$form->add( new CheckboxSet( 'tkchat_delete_groups', $deleteGroups, false, array(
			'options'          => $groups,
			'unlimitedLang'    => 'tkchat_none',
			'impliedUnlimited' => false
		) ) );
		
		// ── Edycja własnych ──────────────────────────────────────────
		$form->addHeader( 'tkchat_header_own_messages' );
		$form->addMessage( 'tkchat_own_messages_info', 'ipsMessage ipsMessage_info' );
		
		$form->add( new CheckboxSet( 'tkchat_edit_own_groups', $editOwnGroups, false, array(
			'options'          => $groups,
			'unlimitedLang'    => 'tkchat_none',
			'impliedUnlimited' => false
		) ) );
		
		// ── Wyróżnienie wiadomości ───────────────────────────────────
		$form->addHeader( 'tkchat_header_highlight' );
		$form->addMessage( 'tkchat_highlight_info', 'ipsMessage ipsMessage_info' );
		
		$form->add( new CheckboxSet( 'tkchat_highlight_groups', $highlightGroups, false, array(
			'options'          => $groups,
			'unlimitedLang'    => 'tkchat_none',
			'impliedUnlimited' => false
		) ) );
		
		
		// ── Ustawienia ogólne ────────────────────────────────────────
		$form->addHeader( 'tkchat_header_general' );
		
		$form->add( new Number( 'tkchat_max_message_length', $maxMessageLength, false, array(
			'min' => 100,
			'max' => 2000
		) ) );
		
		$form->add( new Number( 'tkchat_antispam_time', $antispamTime, false, array(
			'min'         => 0,
			'max'         => 30,
			'labelSuffix' => \IPS\Member::loggedIn()->language()->addToStack('tkchat_seconds')
		) ) );
		
		$form->add( new Number( 'tkchat_edit_time_limit', $editTimeLimit, false, array(
			'min'         => 0,
			'max'         => 3600,
			'labelSuffix' => \IPS\Member::loggedIn()->language()->addToStack('tkchat_seconds')
		) ) );
		
		$form->add( new Number( 'tkchat_polling_interval', $pollingInterval, false, array(
			'min'         => 1000,
			'max'         => 30000,
			'labelSuffix' => \IPS\Member::loggedIn()->language()->addToStack('tkchat_milliseconds')
		) ) );
		
		// ── API Klipy ────────────────────────────────────────────────
		$form->addHeader( 'tkchat_header_klipy' );
		
		$form->add( new Text( 'tkchat_klipy_endpoint', $klipyEndpoint ) );
		$form->add( new Text( 'tkchat_klipy_key', $klipyKey ) );

		if ( $values = $form->values() )
		{
			$form->saveAsSettings( array(
				'tkchat_chat_groups'        => is_array( $values['tkchat_chat_groups'] ) && count( $values['tkchat_chat_groups'] ) > 0 ? implode( ',', $values['tkchat_chat_groups'] ) : '',
				'tkchat_moderate_groups'    => is_array( $values['tkchat_moderate_groups'] ) && count( $values['tkchat_moderate_groups'] ) > 0 ? implode( ',', $values['tkchat_moderate_groups'] ) : '',
				'tkchat_edit_groups'        => is_array( $values['tkchat_edit_groups'] ) && count( $values['tkchat_edit_groups'] ) > 0 ? implode( ',', $values['tkchat_edit_groups'] ) : '',
				'tkchat_delete_groups'      => is_array( $values['tkchat_delete_groups'] ) && count( $values['tkchat_delete_groups'] ) > 0 ? implode( ',', $values['tkchat_delete_groups'] ) : '',
				'tkchat_edit_own_groups'    => is_array( $values['tkchat_edit_own_groups'] ) && count( $values['tkchat_edit_own_groups'] ) > 0 ? implode( ',', $values['tkchat_edit_own_groups'] ) : '',
				'tkchat_highlight_groups'   => is_array( $values['tkchat_highlight_groups'] ) && count( $values['tkchat_highlight_groups'] ) > 0 ? implode( ',', $values['tkchat_highlight_groups'] ) : '',
				'tkchat_max_message_length' => $values['tkchat_max_message_length'],
				'tkchat_antispam_time'      => $values['tkchat_antispam_time'],
				'tkchat_edit_time_limit'    => $values['tkchat_edit_time_limit'],
				'tkchat_polling_interval'   => $values['tkchat_polling_interval'],
				'tkchat_klipy_endpoint'     => $values['tkchat_klipy_endpoint'],
				'tkchat_klipy_key'          => $values['tkchat_klipy_key'],
			) );

			Session::i()->log( 'acplogs__tkchat_settings_updated' );
			Output::i()->redirect( \IPS\Http\Url::internal( 'app=tkchat&module=settings&controller=chat' ), 'saved' );
		}

		Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack( 'tkchat_settings_title' );
		Output::i()->output = $form;
	}
}