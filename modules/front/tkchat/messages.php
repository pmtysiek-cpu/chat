<?php

namespace IPS\tkchat\modules\front\tkchat;

use IPS\Settings;
use IPS\tkchat\ChatPermissions;
use IPS\Member;
use IPS\Member\Group;
use IPS\Db;
use IPS\Data\Store;
use IPS\Data\Cache;
use IPS\Http\Url;
use IPS\File;
use IPS\Request;
use IPS\Session;
use IPS\Output;
use IPS\Dispatcher\Controller;
use Throwable;
use UnderflowException;
use Exception;

class messages extends Controller
{
    public function __construct()
    {
        parent::__construct();
        
        $do = Request::i()->do ?? '';
        $allowed = ['stream', 'manage', 'edit', 'delete', 'setTyping', 'emojis', 'getUsersForMentions', 'getMember', 'getKlipyKey', 'heartbeat', 'banUser', 'getBannedList', 'unbanUser', 'clearChat', 'getUserStyle'];
        
        if ( !Request::i()->isAjax() && !in_array( $do, $allowed ) && $do !== '' )
        {
            Output::i()->error( 'no_direct_access', '2T100/1', 403 );
        }
    }

    protected function manage()
    {
        try
        {
            Session::i()->csrfCheck();
            $member = Member::loggedIn();

            if ( !ChatPermissions::canUseChat( $member ) )
            {
                $this->_sendDirectJson( array( 'error' => 'Nie masz uprawnień do pisania na czacie.' ) );
            }

            if ( !isset( Request::i()->message ) || trim( Request::i()->message ) === '' )
            {
                $this->_sendDirectJson( array( 'error' => 'Wiadomość jest pusta.' ) );
            }

            $rawMessage = trim( Request::i()->message );
            $rawMessage = strip_tags( $rawMessage );
            $rawMessage = htmlspecialchars( $rawMessage, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            $maxLength = ChatPermissions::getMaxMessageLength();
            if ( mb_strlen( $rawMessage ) > $maxLength )
            {
                $this->_sendDirectJson( array( 'error' => 'Wiadomość jest zbyt długa (max ' . $maxLength . ' znaków).' ) );
            }

            if ( $member->member_id )
            {
                $antispamTime = ChatPermissions::getAntispamTime();
                if ( $antispamTime > 0 )
                {
                    try {
                        $lastMessageTime = Db::i()->select( 'date', 'tkchat_messages', array( 'member_id = ?', $member->member_id ), 'id DESC', 1 )->first();
                        if ( ( time() - $lastMessageTime ) < $antispamTime ) {
                            $this->_sendDirectJson( array( 'error' => 'Zwolnij! Odczekaj ' . $antispamTime . ' sekund między wiadomościami.' ) );
                        }
                    }
                    catch ( UnderflowException $e ) {}
                }
            }

            $replyToId = isset( Request::i()->replyToId ) ? intval( Request::i()->replyToId ) : 0;
            if ( $replyToId > 0 ) {
                try {
                    $replyExists = Db::i()->select( 'COUNT(*)', 'tkchat_messages', array( 'id = ?', $replyToId ) )->first();
                    if ( !$replyExists ) $replyToId = 0;
                } catch ( Exception $e ) {
                    $replyToId = 0;
                }
            }

            Db::i()->insert( 'tkchat_messages', array(
                'member_id'   => $member->member_id ? $member->member_id : 0,
                'reply_to_id' => $replyToId,
                'message'     => $rawMessage,
                'date'        => time(),
                'edited'      => 0
            ) );

            if ( $member->member_id )
            {
                $typing = Store::i()->tkchat_typing ?? array();
                if ( isset($typing[$member->member_id]) ) {
                    unset($typing[$member->member_id]);
                    Store::i()->tkchat_typing = $typing;
                }
            }

            if ( isset( Cache::i()->tkchat_users_cache ) ) {
                unset( Cache::i()->tkchat_users_cache );
            }

            $this->_sendDirectJson( array( 'success' => true ) );
        }
        catch ( Throwable $e ) 
        { 
            $this->_sendDirectJson( array( 'error' => $e->getMessage() ) ); 
        }
    }

    protected function edit()
    {
        try
        {
            Session::i()->csrfCheck();
            $member = Member::loggedIn();

            if ( !$member->member_id )
            {
                $this->_sendDirectJson( array( 'error' => 'Musisz być zalogowany.' ) );
            }

            $messageId = isset( Request::i()->messageId ) ? intval( Request::i()->messageId ) : 0;
            $newMessage = isset( Request::i()->message ) ? trim( Request::i()->message ) : '';

            if ( $messageId <= 0 || empty($newMessage) )
            {
                $this->_sendDirectJson( array( 'error' => 'Nieprawidłowe dane.' ) );
            }

            $maxLength = ChatPermissions::getMaxMessageLength();
            if ( mb_strlen( $newMessage ) > $maxLength )
            {
                $this->_sendDirectJson( array( 'error' => 'Wiadomość jest zbyt długa (max ' . $maxLength . ' znaków).' ) );
            }

            try {
                $message = Db::i()->select( '*', 'tkchat_messages', array( 'id = ?', $messageId ) )->first();
            }
            catch ( UnderflowException $e ) {
                $this->_sendDirectJson( array( 'error' => 'Wiadomość nie istnieje lub została usunięta.' ) );
            }

            $isAuthor = ( $message['member_id'] == $member->member_id );
            $canModerate = ChatPermissions::canModerateChat( $member );
            $canEditOthers = ChatPermissions::canEditAnyMessage( $member );
            $canEditOwn = ChatPermissions::canEditOwnMessage( $member );
            
            if ( $isAuthor && $canEditOwn ) {
                $editTimeLimit = ChatPermissions::getEditTimeLimit();
                if ( $editTimeLimit > 0 && (time() - intval($message['date'])) > $editTimeLimit ) {
                    $minutes = floor( $editTimeLimit / 60 );
                    $this->_sendDirectJson( array( 'error' => 'Czas na edycję minął. Możesz edytować tylko przez ' . $minutes . ' minut.' ) );
                }
            } elseif ( !$canModerate && !$canEditOthers ) {
                $this->_sendDirectJson( array( 'error' => 'Nie masz uprawnień do edycji tej wiadomości.' ) );
            }

            $newMessage = strip_tags( $newMessage );
            $newMessage = htmlspecialchars( $newMessage, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            Db::i()->update( 'tkchat_messages', array(
                'message'   => $newMessage,
                'edited'    => 1,
                'edit_date' => time()
            ), array( 'id = ?', $messageId ) );

            $this->_sendDirectJson( array( 'success' => true ) );
        }
        catch ( Throwable $e ) 
        { 
            $this->_sendDirectJson( array( 'error' => $e->getMessage() ) ); 
        }
    }

    protected function delete()
    {
        try
        {
            Session::i()->csrfCheck();
            $member = Member::loggedIn();

            if ( !$member->member_id )
            {
                $this->_sendDirectJson( array( 'error' => 'Musisz być zalogowany.' ) );
            }

            $messageId = isset( Request::i()->messageId ) ? intval( Request::i()->messageId ) : 0;

            if ( $messageId <= 0 )
            {
                $this->_sendDirectJson( array( 'error' => 'Nieprawidłowe ID wiadomości.' ) );
            }

            try {
                $message = Db::i()->select( '*', 'tkchat_messages', array( 'id = ?', $messageId ) )->first();
            }
            catch ( UnderflowException $e ) {
                $this->_sendDirectJson( array( 'error' => 'Wiadomość nie istnieje lub już została usunięta.' ) );
            }

            $isAuthor = ( $message['member_id'] == $member->member_id );
            $canModerate = ChatPermissions::canModerateChat( $member );
            $canDeleteOthers = ChatPermissions::canDeleteAnyMessage( $member );
            $canEditOwn = ChatPermissions::canEditOwnMessage( $member );
            
            $hasPermission = false;
            if ( $canModerate || $canDeleteOthers ) {
                $hasPermission = true;
            } elseif ( $isAuthor && $canEditOwn ) {
                $hasPermission = true;
            }
            
            if ( !$hasPermission )
            {
                $this->_sendDirectJson( array( 'error' => 'Nie masz uprawnień do usunięcia tej wiadomości.' ) );
            }

            Db::i()->delete( 'tkchat_messages', array( 'id = ?', $messageId ) );

            $this->_sendDirectJson( array( 'success' => true ) );
        }
        catch ( Throwable $e ) 
        { 
            $this->_sendDirectJson( array( 'error' => $e->getMessage() ) ); 
        }
    }

    protected function stream()
    {
        try
        {
            $lastId   = isset( Request::i()->lastId )   ? intval( Request::i()->lastId )   : 0;
            $beforeId = isset( Request::i()->beforeId ) ? intval( Request::i()->beforeId ) : 0;
            $limit    = isset( Request::i()->limit )    ? intval( Request::i()->limit )    : 20;

            if ( $limit > 50 ) $limit = 50;

            $where = NULL;
            
            if ( $lastId > 0 ) {
                $where = array( 'id > ?', $lastId );
                $order = 'id ASC';
            } elseif ( $beforeId > 0 ) {
                $where = array( 'id < ?', $beforeId );
                $order = 'id DESC';
            } else {
                $order = 'id DESC';
            }

            $select = Db::i()->select( '*', 'tkchat_messages', $where, $order, $limit );
            $rows   = iterator_to_array( $select );

            $updatedMessages = array();
            $currentMember = Member::loggedIn();
            
            if ( $lastId > 0 ) {
                $lastCheck = isset( Request::i()->lastCheck ) ? intval( Request::i()->lastCheck ) : (time() - 10);
                
                try {
                    $updatedSelect = Db::i()->select(
                        '*',
                        'tkchat_messages',
                        array( 'id <= ? AND edit_date >= ?', $lastId, $lastCheck ),
                        'id ASC',
                        20
                    );
                    $updatedRows = iterator_to_array( $updatedSelect );
                }
                catch ( Exception $e ) {
                    $updatedRows = array();
                }
                
                foreach ( $updatedRows as $row ) {
                    if ( !is_array( $row ) ) continue;
                    $updatedMessages[] = $this->_formatMessageRow( $row, $currentMember, true );
                }

                if ( isset( Request::i()->visibleIds ) && !empty( Request::i()->visibleIds ) ) {
                    $visibleIds = array_filter( array_map( 'intval', explode( ',', Request::i()->visibleIds ) ), fn($id) => $id > 0 );
                    
                    if ( !empty( $visibleIds ) ) {
                        try {
                            $existingIds = Db::i()->select( 'id', 'tkchat_messages', array( 'id IN (' . implode( ',', $visibleIds ) . ')' ) );
                            $existingIdsArray = array();
                            foreach ( $existingIds as $row ) {
                                if ( is_array( $row ) && isset( $row['id'] ) ) {
                                    $existingIdsArray[] = $row['id'];
                                } elseif ( is_numeric( $row ) ) {
                                    $existingIdsArray[] = (int)$row;
                                }
                            }
                            
                            foreach ( array_diff( $visibleIds, $existingIdsArray ) as $deletedId ) {
                                $updatedMessages[] = array(
                                    'id'         => intval( $deletedId ),
                                    'is_deleted' => true,
                                    'is_update'  => true
                                );
                            }
                        }
                        catch ( Exception $e ) {}
                    }
                }
            }

            $outputMessages = array();
            $usersMap       = array();
            $groupStylesCache = array();

            foreach ( $rows as $row )
            {
                if ( !is_array( $row ) ) continue;
                
                $msg = $this->_formatMessageRow( $row, $currentMember, false );
                $outputMessages[] = $msg;

                if ( $msg['member_id'] > 0 && !empty( $msg['raw_name'] ) ) {
                    $usersMap[ $msg['raw_name'] ] = [
    'id'             => $msg['member_id'],
    'name'           => $msg['raw_name'],
    'color'          => $msg['author_color'],
    'text_shadow'    => $msg['text_shadow'],
    'fa_icon'        => $msg['fa_icon'],
    'font_weight'    => $msg['font_weight'] ?? '',
    'font_style'     => $msg['font_style'] ?? '',
    'text_decoration'=> $msg['text_decoration'] ?? '',
    'background'     => $msg['background'] ?? ''
];
                    
// W stream(), przy budowaniu groupStylesCache:
if ( !isset( $groupStylesCache[ $msg['member_id'] ] ) ) {
    try {
        $msgMember = Member::load( $msg['member_id'] );
        $group = Group::load( $msgMember->member_group_id );
        $prefix = $group->prefix ?? '';
        $suffix = $group->suffix ?? '';
        
        $color = '';
        $textShadow = '';
        $faIcon = '';
        $fontWeight = '';
        $fontStyle = '';
        $textDecoration = '';
        $background = '';
        
        // Kolor
        if (preg_match('/color:\s*([^;"\']+)/', $prefix . $suffix, $m)) {
            $color = preg_replace('/[^a-zA-Z0-9#]/', '', trim($m[1]));
        }
        // Text shadow
        if (preg_match('/text-shadow:\s*([^;"\']+)/', $prefix . $suffix, $m)) {
            $textShadow = trim($m[1]);
        }
        // FA icon
// Pobierz wszystkie klasy FA
if (preg_match_all('/fa[a-z0-9\-]*/', $prefix . $suffix, $matches)) {
    $faClasses = array_unique($matches[0]);
    $faClasses = array_filter($faClasses, fn($c) => $c !== 'fa');
    $faIcon = implode(' ', $faClasses);
}

// ZAWSZE dodaj "fa" na początku jeśli go nie ma
if (!empty($faIcon) && strpos($faIcon, 'fa-') === 0) {
    $faIcon = 'fa ' . $faIcon;
}

// OCZYŚĆ - zamień fa-brands na fa-brands (zostaw jak jest)
// Problem: fa-brands wymaga class="fa-brands fa-xxx" nie "fa fa-brands"
$faIcon = str_replace('fa fa-brands', 'fa-brands', $faIcon);
$faIcon = str_replace('fa fa-solid', 'fa-solid', $faIcon);
$faIcon = str_replace('fa fa-regular', 'fa-regular', $faIcon);
        // Font weight
        if (preg_match('/font-weight:\s*([^;"\']+)/', $prefix . $suffix, $m)) {
            $fontWeight = trim($m[1]);
        }
        // Font style
        if (preg_match('/font-style:\s*([^;"\']+)/', $prefix . $suffix, $m)) {
            $fontStyle = trim($m[1]);
        }
        // Text decoration
        if (preg_match('/text-decoration:\s*([^;"\']+)/', $prefix . $suffix, $m)) {
            $textDecoration = trim($m[1]);
        }
        // Background
// Background - napraw URL
if (preg_match('/background:\s*url\(([^)]+)\)/', $prefix . $suffix, $m)) {
    $bgUrl = trim($m[1], "'\" ");
    $background = 'url(' . $bgUrl . ')';
} elseif (preg_match('/background:\s*([^;"\']+)/', $prefix . $suffix, $m)) {
    $background = trim($m[1]);
}
        
        $groupStylesCache[ $msg['member_id'] ] = array(
            'name'           => $msg['raw_name'],
            'color'          => $color,
            'text_shadow'    => $textShadow,
            'fa_icon'        => $faIcon,
            'font_weight'    => $fontWeight,
            'font_style'     => $fontStyle,
            'text_decoration'=> $textDecoration,
            'background'     => $background
        );
    }
    catch ( Exception $e ) {}
}
                }
            }

            $allMessages = array_merge( $updatedMessages, $outputMessages );

            $hasMoreOlder = false;
            if ( count($outputMessages) > 0 && ( $beforeId > 0 || ( $beforeId == 0 && $lastId == 0 ) ) ) {
                $lastOutputMsg = end($outputMessages);
                try {
                    $checkOlder = Db::i()->select( 'COUNT(*)', 'tkchat_messages', array( 'id < ?', $lastOutputMsg['id'] ) )->first();
                    $hasMoreOlder = ( $checkOlder > 0 );
                }
                catch ( Exception $e ) {}
            }

            $typingUsers = array();
            $timeWindow  = time() - 5;
            $typing      = Store::i()->tkchat_typing ?? array();
            $currentMemberId = $currentMember->member_id;

            foreach ( $typing as $mId => $data ) {
                if ( !is_array( $data ) ) continue;
                if ( $data['time'] < $timeWindow ) continue;
                if ( $mId == $currentMemberId ) continue;
                $typingUsers[] = (string)$data['name'];
            }

            $finalMessages = ( $lastId > 0 ) ? $allMessages : array_reverse( $outputMessages );

            $newLastId = $lastId;
            foreach ( $outputMessages as $msg ) {
                if ( !$msg['is_update'] && $msg['id'] > $newLastId ) {
                    $newLastId = $msg['id'];
                }
            }
// Inicjalizuj jeśli nie istnieje
if ( !isset( Store::i()->tkchat_active ) ) {
    Store::i()->tkchat_active = array();
}

$onlineCount = count( Store::i()->tkchat_active );
            $this->_sendDirectJson( array(
                'messages'     => $finalMessages,
                'usersMap'     => $usersMap,
                'userStyles'   => $groupStylesCache,
                'typing'       => $typingUsers,
                'hasMoreOlder' => $hasMoreOlder,
                'currentUser'  => $currentMemberId ? (int)$currentMemberId : 0,
                'isLoggedIn'   => (bool)$currentMemberId,
                'canChat'      => ( $currentMemberId ? ChatPermissions::canUseChat( $currentMember ) : false ),
                'lastCheck'    => time(),
                'onlineCount' => count( Store::i()->tkchat_active ?? array() )
            ) );
        }
        catch ( Throwable $e ) 
        { 
            $this->_sendDirectJson( array( 'error' => $e->getMessage() ) ); 
        }
    }

    private function _formatMessageRow( array $row, Member $currentMember, bool $isUpdate ) : array
    {
        try {
            $msgMember = Member::load( (int)$row['member_id'] );
        }
        catch ( Exception $e ) {
            $msgMember = null;
        }
        
        $groupName = '';
        $groupBadgeColor = '';
        $isHighlighted = false;
        $textShadow = '';
        $faIcon = '';
        
        if ( !isset($row['member_id']) || $row['member_id'] == 0 || !$msgMember || !$msgMember->member_id ) {
            $authorName = 'Anonim';
            $authorNamePlain = 'Anonim';
            $avatar = (string)Url::internal( "applications/core/interface/default/default_photo.png", "none" );
            $rawName = 'Anonim';
            $memberId = 0;
            $authorColor = '';
} else {
    $group = Group::load( $msgMember->member_group_id );
    $prefix = isset($group->prefix) ? (string)$group->prefix : '';
    $suffix = isset($group->suffix) ? (string)$group->suffix : '';
    
    $groupName = Member::loggedIn()->language()->get( 'core_group_' . $group->g_id );
    
    // Zbierz wszystkie grupy użytkownika
    $allGroups = array_merge(
        [ $msgMember->member_group_id ],
        $msgMember->mgroup_others ? array_map( 'intval', explode( ',', $msgMember->mgroup_others ) ) : []
    );
    
    // Sprawdź wyróżnienie i pobierz kolor z wyróżnionej grupy
    $highlightSetting = Settings::i()->tkchat_highlight_groups;
    $highlightGroups = [];
    if ( $highlightSetting !== null && $highlightSetting !== '' ) {
        $highlightGroups = array_filter( array_map( 'intval', explode( ',', $highlightSetting ) ), fn($id) => $id > 0 );
    }
    
    // Znajdź pasującą grupę wyróżnioną
    $matchedGroupId = null;
    foreach ( $allGroups as $gid ) {
        if ( in_array( $gid, $highlightGroups ) ) {
            $matchedGroupId = $gid;
            $isHighlighted = true;
            break;
        }
    }
    
    // Pobierz styl z pasującej grupy (wyróżnionej lub głównej)
    $styleGroupId = $matchedGroupId ?: $msgMember->member_group_id;
    $styleGroup = Group::load( $styleGroupId );
    $stylePrefix = $styleGroup->prefix ?? '';
    $styleSuffix = $styleGroup->suffix ?? '';
    
    // Kolor
    $authorColor = '';
    if (preg_match('/color:\s*([^;]+)/', $stylePrefix . $styleSuffix, $matches)) {
        $authorColor = trim($matches[1]);
    }
    $authorColor = preg_replace('/[^a-zA-Z0-9#]/', '', $authorColor);
    
    if ( !empty($authorColor) ) {
        $groupBadgeColor = $authorColor;
    }
    
    // Text-shadow
    if (preg_match('/text-shadow:\s*([^;]+)/', $stylePrefix . $styleSuffix, $m)) {
        $textShadow = trim($m[1]);
    }
    
    // FA icon
    if (preg_match_all('/fa[a-z0-9\-]*/', $stylePrefix . $styleSuffix, $m)) {
        $faClasses = array_unique($m[0]);
        $faClasses = array_filter($faClasses, fn($c) => $c !== 'fa');
        $faIcon = implode(' ', $faClasses);
        if (!empty($faIcon) && strpos($faIcon, 'fa-') === 0) {
            $faIcon = 'fa ' . $faIcon;
        }
    }
    
    // Nazwa grupy z wyróżnionej (jeśli jest)
    if ( $matchedGroupId ) {
        $groupName = Member::loggedIn()->language()->get( 'core_group_' . $matchedGroupId );
    }
    
    $authorName = $prefix . $msgMember->name . $suffix;
    $authorNamePlain = (string)$msgMember->name;
    $avatar = (string)$msgMember->photo;
    $rawName = (string)$msgMember->name;
    $memberId = (int)$msgMember->member_id;
}

        $replyData = null;
        if ( isset($row['reply_to_id']) && $row['reply_to_id'] > 0 ) {
            try {
                $parentRow = Db::i()->select( 'member_id, message', 'tkchat_messages', array( 'id = ?', $row['reply_to_id'] ) )->first();
                
                if ( is_array( $parentRow ) ) {
                    try {
                        $parentMember = Member::load( (int)$parentRow['member_id'] );
                    }
                    catch ( Exception $e ) {
                        $parentMember = null;
                    }
                    
                    $parentMessage = (string)$parentRow['message'];
                    if ( preg_match_all( '/@\[(\d+)\]/', $parentMessage, $replyMatches ) ) {
                        foreach ( array_unique($replyMatches[1]) as $repliedUserId ) {
                            try {
                                $repliedMember = Member::load( (int)$repliedUserId );
                                $repliedName   = $repliedMember->member_id ? $repliedMember->name : 'Użytkownik';
                            }
                            catch ( Exception $e ) {
                                $repliedName = 'Użytkownik';
                            }
                            $parentMessage = str_replace( '@[' . $repliedUserId . ']', '@' . $repliedName, $parentMessage );
                        }
                    }

                    $replyData = array(
                        'author'            => ( $parentMember && $parentMember->member_id ) ? (string)$parentMember->name : 'Anonim',
                        'original_author_id' => (int)$parentRow['member_id'],
                        'message'           => (string)mb_strimwidth( strip_tags($parentMessage), 0, 40, '...' ),
                        'original_message_id' => (int)$row['reply_to_id']
                    );
                }
            }
            catch ( UnderflowException $e ) {
                $replyData = array( 
                    'author' => 'System', 
                    'message' => 'Oryginalna wiadomość została usunięta.',
                    'original_author_id' => 0
                );
            }
        }

        $isAuthor = ( $currentMember->member_id && $currentMember->member_id == $memberId );
        $canModerate = ChatPermissions::canModerateChat( $currentMember );
        $canEditOthers = ChatPermissions::canEditAnyMessage( $currentMember );
        $canDeleteOthers = ChatPermissions::canDeleteAnyMessage( $currentMember );
        $canEditOwn = ChatPermissions::canEditOwnMessage( $currentMember );

        $withinTimeLimit = true;
        if ( $isAuthor && $canEditOwn ) {
            $editTimeLimit = ChatPermissions::getEditTimeLimit();
            if ( $editTimeLimit > 0 ) {
                $withinTimeLimit = ( time() - intval($row['date']) ) <= $editTimeLimit;
            }
        }

        if ( $canModerate || $canEditOthers ) {
            $canEdit = true;
        } elseif ( $isAuthor && $canEditOwn && $withinTimeLimit ) {
            $canEdit = true;
        } else {
            $canEdit = false;
        }

        if ( $canModerate || $canDeleteOthers ) {
            $canDelete = true;
        } elseif ( $isAuthor && $canEditOwn ) {
            $canDelete = false;
        } else {
            $canDelete = false;
        }

return array(
    'id'               => intval( $row['id'] ),
    'author'           => $authorName,
    'author_plain'     => $authorNamePlain,
    'author_color'     => $authorColor,
    'raw_name'         => $rawName,
    'member_id'        => $memberId,
    'avatar'           => $avatar,
    'time'             => date( 'H:i', $row['date'] ),
    'timestamp'        => (int)$row['date'],
    'message'          => (string)$row['message'],
    'original_message' => (string)$row['message'],
    'reply_data'       => $replyData,
    'is_deleted'       => false,
    'edited'           => (bool)( $row['edited'] ?? 0 ),
    'can_moderate'     => $canModerate,
    'can_edit'         => $canEdit,
    'can_delete'       => $canDelete,
    'is_update'        => $isUpdate,
    'group_name'       => $groupName,
    'group_badge_color'=> $groupBadgeColor,
    'is_highlighted'   => $isHighlighted,
    'text_shadow'      => $textShadow,
    'fa_icon'          => $faIcon,
    'font_weight'      => $fontWeight ?? '',
    'font_style'       => $fontStyle ?? '',
    'text_decoration'  => $textDecoration ?? '',
    'background'       => $background ?? ''
);
    }

    protected function setTyping()
    {
        try
        {
            $member = Member::loggedIn();
            if ( !$member->member_id ) {
                $this->_sendDirectJson( array( 'success' => false ) );
            }

            $status = isset( Request::i()->status ) ? intval( Request::i()->status ) : 0;
            $typing = Store::i()->tkchat_typing ?? array();

            if ( $status === 1 ) {
                $group  = Group::load( $member->member_group_id );
                $prefix = isset($group->prefix) ? (string)$group->prefix : '';
                $suffix = isset($group->suffix) ? (string)$group->suffix : '';
                $typing[$member->member_id] = array(
                    'name' => (string)($prefix . $member->name . $suffix),
                    'time' => time()
                );
            } else {
                if ( isset($typing[$member->member_id]) ) {
                    unset($typing[$member->member_id]);
                }
            }

            Store::i()->tkchat_typing = $typing;
            $this->_sendDirectJson( array( 'success' => true ) );
        }
        catch ( Throwable $e ) 
        { 
            $this->_sendDirectJson( array( 'error' => $e->getMessage() ) ); 
        }
    }

protected function emojis()
{
    try {
        $cacheKey = 'tkchat_emojis_' . Member::loggedIn()->language()->id;
        $cacheTimeKey = $cacheKey . '_time';
        
        if ( isset( Cache::i()->$cacheKey ) && isset( Cache::i()->$cacheTimeKey ) ) {
            if ( (time() - Cache::i()->$cacheTimeKey) < 3600 ) {
                $this->_sendDirectJson( Cache::i()->$cacheKey );
                return;
            }
        }

        $select = Db::i()->select( 'typed, image, width, height, emo_set_position', 'core_emoticons', null, 'emo_set_position ASC, id ASC' );
        $output = array();
        foreach ( $select as $row )
        {
            try {
                $output[] = array(
                    'code' => (string)$row['typed'],
                    'url'  => (string)File::get( 'core_Emoticons', $row['image'] )->url,
                    'cat'  => 'Zestaw ' . $row['emo_set_position'],
                    'w'    => intval($row['width']),
                    'h'    => intval($row['height'])
                );
            } catch ( Throwable $ex ) { continue; }
        }
        
        // Grupuj według kategorii
        $grouped = [];
        foreach ($output as $emoji) {
            $cat = $emoji['cat'];
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [];
            }
            $grouped[$cat][] = $emoji;
        }
        
        Cache::i()->$cacheKey = $grouped;
        Cache::i()->$cacheTimeKey = time();
        
        $this->_sendDirectJson( $grouped );
    } catch ( Throwable $e ) {
        $this->_sendDirectJson( array() );
    }
}
protected function heartbeat()
{
    try {
        $member = Member::loggedIn();
        
        if ( $member->member_id )
        {
            $active = Store::i()->tkchat_active ?? [];
            $active[ $member->member_id ] = time();
            
            // Usuń nieaktywnych
            $active = array_filter( $active, fn($t) => time() - $t < 300 );
            
            Store::i()->tkchat_active = $active;
            
            // DEBUG
            $this->_sendDirectJson( [ 
                'success' => true, 
                'online' => count($active),
                'debug' => array_values($active)
            ] );
            return;
        }
        
        $this->_sendDirectJson( [ 'success' => false, 'error' => 'Nie zalogowany' ] );
    }
    catch ( \Throwable $e ) 
    { 
        $this->_sendDirectJson( [ 'error' => $e->getMessage() ] ); 
    }
}
    protected function getUsersForMentions()
    {
        try {
            $q     = trim( Request::i()->q );
            $users = array();

            if ( mb_strlen($q) >= 2 ) {
                $cacheKey = 'tkchat_mentions_' . md5($q);
                if ( isset( Cache::i()->$cacheKey ) ) {
                    $this->_sendDirectJson( Cache::i()->$cacheKey );
                    return;
                }

                $select    = Db::i()->select( 'member_id', 'core_members', array( 'name LIKE ?', '%' . $q . '%' ), 'name ASC', 5 );
                $memberIds = iterator_to_array( $select );

                foreach ( $memberIds as $mId ) {
                    try {
                        $member = Member::load( (int)$mId );
                        if ( $member->member_id ) {
                            $group  = Group::load( (int)$member->member_group_id );
                            $prefix = isset($group->prefix) ? (string)$group->prefix : '';
                            $suffix = isset($group->suffix) ? (string)$group->suffix : '';

                            $users[] = array(
                                'id'        => (int)$member->member_id,
                                'name'      => (string)$member->name,
                                'prefix'    => $prefix,
                                'suffix'    => $suffix,
                                'formatted' => (string)($prefix . $member->name . $suffix)
                            );
                        }
                    }
                    catch ( Exception $e ) {
                        continue;
                    }
                }
                
                Cache::i()->$cacheKey = $users;
            }
            $this->_sendDirectJson( $users );
        }
        catch ( Throwable $e ) {
            $this->_sendDirectJson( array() );
        }
    }

    protected function getMember()
    {
        try {
            $nick = trim( Request::i()->name );
            
            if ( mb_strlen($nick) < 2 ) {
                $this->_sendDirectJson( array( 'error' => 'Nazwa zbyt krótka' ) );
            }
            
            $cacheKey = 'tkchat_member_' . md5($nick);
            if ( isset( Cache::i()->$cacheKey ) ) {
                $this->_sendDirectJson( Cache::i()->$cacheKey );
                return;
            }

            $member = Member::load( $nick, 'name' );

            if ( $member->member_id ) {
                $result = array( 'id' => (int)$member->member_id );
                Cache::i()->$cacheKey = $result;
                $this->_sendDirectJson( $result );
            } else {
                $this->_sendDirectJson( array( 'error' => 'not_found' ) );
            }
        }
        catch ( Throwable $e ) {
            $this->_sendDirectJson( array( 'error' => $e->getMessage() ) );
        }
    }

    protected function getKlipyKey()
    {
        try {
            $this->_sendDirectJson( array( 
                'key' => Settings::i()->tkchat_klipy_key ?? ''
            ) );
        } catch ( Throwable $e ) {
            $this->_sendDirectJson( array( 'key' => '' ) );
        }
    }

    private function _sendDirectJson( $data )
    {
        header( 'Content-Type: application/json; charset=utf-8' );
        echo json_encode( $data );
        exit;
    }
protected function banUser()
{
    try {
        Session::i()->csrfCheck();
        $member = Member::loggedIn();
       if ( !ChatPermissions::canModerateChat( $member ) ) {
            $this->_sendDirectJson( ['error' => 'Brak uprawnień.'] );
        }
        $nick = trim( Request::i()->nick );
        if ( empty($nick) ) $this->_sendDirectJson( ['error' => 'Podaj nick.'] );
        
        $target = Member::load( $nick, 'name' );
        if ( !$target->member_id ) $this->_sendDirectJson( ['error' => 'Nie znaleziono.'] );
        
        $duration = intval( Request::i()->duration ) ?: 0;
        $expires = $duration > 0 ? time() + ($duration * 60) : 0;

      // Sprawdź czy już zbanowany
$existing = Db::i()->select( 'COUNT(*)', 'tkchat_bans', [
    'member_id=? AND (expires=0 OR expires>?)',
    $target->member_id, time()
] )->first();

if ( $existing > 0 ) {
    $this->_sendDirectJson( ['error' => 'Ten użytkownik jest już zbanowany.'] );
}  

        Db::i()->insert( 'tkchat_bans', [
            'member_id' => $target->member_id,
            'banned_by' => $member->member_id,
            'time'      => time(),
            'expires'   => $expires
        ] );
        
        $this->_sendDirectJson( ['success' => true] );
    } catch ( \Throwable $e ) { $this->_sendDirectJson( ['error' => $e->getMessage()] ); }
}

protected function getBannedList()
{
    try {
        $rows = Db::i()->select( '*', 'tkchat_bans', ['expires=0 OR expires>?', time()], 'time DESC' );
        $list = [];
        foreach ( $rows as $row ) {
            try {
                $m = Member::load( $row['member_id'] );
                if ( $m->member_id ) {
                    $list[] = ['id' => (int)$row['member_id'], 'name' => $m->name, 'expires' => (int)$row['expires']];
                }
            } catch ( \Exception $e ) {}
        }
        $this->_sendDirectJson( $list );
    } catch ( \Throwable $e ) { $this->_sendDirectJson( [] ); }
}

protected function unbanUser()
{
    try {
        Session::i()->csrfCheck();
        $member = Member::loggedIn();
        if ( !ChatPermissions::canModerateChat( $member ) ) {
            $this->_sendDirectJson( ['error' => 'Brak uprawnień.'] );
        }
        Db::i()->delete( 'tkchat_bans', ['member_id=?', intval( Request::i()->userId )] );
        $this->_sendDirectJson( ['success' => true] );
    } catch ( \Throwable $e ) { $this->_sendDirectJson( ['error' => $e->getMessage()] ); }
}

protected function clearChat()
{
    try {
        Session::i()->csrfCheck();
        $member = Member::loggedIn();
       if ( !ChatPermissions::canModerateChat( $member ) ) {
            $this->_sendDirectJson( ['error' => 'Brak uprawnień.'] );
        }
        Db::i()->delete( 'tkchat_messages' );
        $this->_sendDirectJson( ['success' => true] );
    } catch ( \Throwable $e ) { $this->_sendDirectJson( ['error' => $e->getMessage()] ); }
}

protected function getUserStyle()
{
    try {
        $userId = intval( Request::i()->userId );
        $member = Member::load( $userId );
        
        if ( !$member->member_id ) {
            $this->_sendDirectJson( [] );
        }
        
        $group = Group::load( $member->member_group_id );
        $prefix = $group->prefix ?? '';
        $suffix = $group->suffix ?? '';
        
        $color = '';
        $textShadow = '';
        $faIcon = '';
        $fontWeight = '';
        $fontStyle = '';
        $textDecoration = '';
        $background = '';
        
        if (preg_match('/color:\s*([^;]+)/', $prefix . $suffix, $m)) {
            $color = preg_replace('/[^a-zA-Z0-9#]/', '', trim($m[1]));
        }
        if (preg_match('/text-shadow:\s*([^;]+)/', $prefix . $suffix, $m)) {
            $textShadow = trim($m[1]);
        }
        if (preg_match_all('/fa[a-z0-9\-]*/', $prefix . $suffix, $m)) {
            $faClasses = array_unique($m[0]);
            $faClasses = array_filter($faClasses, fn($c) => $c !== 'fa');
            $faIcon = implode(' ', $faClasses);
            if (!empty($faIcon) && strpos($faIcon, 'fa-') === 0) $faIcon = 'fa ' . $faIcon;
        }
        if (preg_match('/font-weight:\s*([^;]+)/', $prefix . $suffix, $m)) {
            $fontWeight = trim($m[1]);
        }
        if (preg_match('/font-style:\s*([^;]+)/', $prefix . $suffix, $m)) {
            $fontStyle = trim($m[1]);
        }
        if (preg_match('/text-decoration:\s*([^;]+)/', $prefix . $suffix, $m)) {
            $textDecoration = trim($m[1]);
        }
        if (preg_match('/background:\s*url\(([^)]+)\)/', $prefix . $suffix, $m)) {
            $background = 'url(' . trim($m[1], "'\" ") . ')';
        } elseif (preg_match('/background:\s*([^;]+)/', $prefix . $suffix, $m)) {
            $background = trim($m[1]);
        }
        
        $this->_sendDirectJson( [
            'name'           => $member->name,
            'color'          => $color,
            'text_shadow'    => $textShadow,
            'fa_icon'        => $faIcon,
            'font_weight'    => $fontWeight,
            'font_style'     => $fontStyle,
            'text_decoration'=> $textDecoration,
            'background'     => $background
        ] );
    }
    catch ( \Throwable $e ) {
        $this->_sendDirectJson( [] );
    }
}
}