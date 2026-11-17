<?php

namespace IPS\tkchat;

use IPS\Member;
use IPS\Settings;
use IPS\Db;

class ChatPermissions
{
    public static function canUseChat( ?Member $member = null ) : bool
    {
        $member = $member ?: Member::loggedIn();
        
        if ( !$member->member_id ) return false;
    try {
        $ban = Db::i()->select( '*', 'tkchat_bans', [
            'member_id=? AND (expires=0 OR expires>?)',
            $member->member_id, time()
        ] )->first();
        
        if ( $ban ) {
            return false;
        }
    } catch ( \Exception $e ) {}

        $setting = Settings::i()->tkchat_chat_groups;
        
        if ( $setting === null || $setting === '' ) return false;
        
        $groups = array_filter( array_map( 'intval', explode( ',', $setting ) ), fn($id) => $id > 0 );
        if ( empty( $groups ) ) return false;

        return self::_memberInGroups( $member, $groups );
    }

    public static function canModerateChat( ?Member $member = null ) : bool
    {
        $member = $member ?: Member::loggedIn();
        
        if ( !$member->member_id ) return false;

        $setting = Settings::i()->tkchat_moderate_groups;
        if ( $setting === null || $setting === '' ) return false;
        
        $groups = array_filter( array_map( 'intval', explode( ',', $setting ) ), fn($id) => $id > 0 );
        if ( empty( $groups ) ) return false;

        return self::_memberInGroups( $member, $groups );
    }

    public static function canEditAnyMessage( ?Member $member = null ) : bool
    {
        $member = $member ?: Member::loggedIn();
        
        if ( !$member->member_id ) return false;

        if ( self::canModerateChat( $member ) ) return true;

        $setting = Settings::i()->tkchat_edit_groups;
        if ( $setting === null || $setting === '' ) return false;
        
        $groups = array_filter( array_map( 'intval', explode( ',', $setting ) ), fn($id) => $id > 0 );
        if ( empty( $groups ) ) return false;

        return self::_memberInGroups( $member, $groups );
    }

    public static function canDeleteAnyMessage( ?Member $member = null ) : bool
    {
        $member = $member ?: Member::loggedIn();
        
        if ( !$member->member_id ) return false;

        if ( self::canModerateChat( $member ) ) return true;

        $setting = Settings::i()->tkchat_delete_groups;
        if ( $setting === null || $setting === '' ) return false;
        
        $groups = array_filter( array_map( 'intval', explode( ',', $setting ) ), fn($id) => $id > 0 );
        if ( empty( $groups ) ) return false;

        return self::_memberInGroups( $member, $groups );
    }

    public static function canEditOwnMessage( ?Member $member = null ) : bool
    {
        $member = $member ?: Member::loggedIn();
        
        if ( !$member->member_id ) return false;

        if ( self::canModerateChat( $member ) ) return true;
        if ( self::canEditAnyMessage( $member ) ) return true;

        $setting = Settings::i()->tkchat_edit_own_groups;
        if ( $setting === null || $setting === '' ) return false;
        
        $groups = array_filter( array_map( 'intval', explode( ',', $setting ) ), fn($id) => $id > 0 );
        if ( empty( $groups ) ) return false;

        return self::_memberInGroups( $member, $groups );
    }

    public static function getEditTimeLimit() : int
    {
        $limit = Settings::i()->tkchat_edit_time_limit;
        if ( $limit === null || $limit === '' || intval( $limit ) === 0 ) return 0;
        return intval( $limit );
    }

    public static function getMaxMessageLength() : int
    {
        return Settings::i()->tkchat_max_message_length ? intval( Settings::i()->tkchat_max_message_length ) : 500;
    }

    public static function getAntispamTime() : int
    {
        return Settings::i()->tkchat_antispam_time ? intval( Settings::i()->tkchat_antispam_time ) : 2;
    }

    private static function _memberInGroups( Member $member, array $groupIds ) : bool
    {
        if ( in_array( $member->member_group_id, $groupIds ) ) return true;

        if ( $member->mgroup_others ) {
            foreach ( array_map( 'intval', explode( ',', $member->mgroup_others ) ) as $gid ) {
                if ( in_array( $gid, $groupIds ) ) return true;
            }
        }

        return false;
    }
}